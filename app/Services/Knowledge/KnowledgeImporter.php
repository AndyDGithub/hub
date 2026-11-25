<?php

namespace App\Services\Knowledge;

use App\Models\Content;
use Illuminate\Support\Facades\DB;

/**
 * Synchronise des fiches parsées avec la base, de façon idempotente : la clé est `source`,
 * `source_hash` dit si le texte a changé. Relancer l'import ne crée jamais de doublon.
 */
class KnowledgeImporter
{
    public function __construct(private readonly SecretScanner $secrets) {}

    /**
     * @param  list<array>  $fiches  sortie de MegadocParser
     * @return list<array{source:string, title:string, action:'create'|'update'|'unchanged'|'skipped', reason?:string}>
     */
    public function sync(array $fiches, bool $write): array
    {
        $report = [];
        foreach ($fiches as $fiche) {
            $line = ['source' => $fiche['source'], 'title' => $fiche['title']];

            if ($found = $this->secrets->scan($fiche['body'])) {
                $report[] = $line + ['action' => 'skipped', 'reason' => 'secret possible : '.implode(', ', $found)];

                continue;
            }

            $hash = hash('sha256', $fiche['type']."\n".$fiche['title']."\n".$fiche['body']."\n".implode(',', $fiche['tags']));
            $existing = Content::withTrashed()->where('source', $fiche['source'])->first();
            $action = match (true) {
                $existing === null => 'create',
                $existing->source_hash !== $hash => 'update',
                default => 'unchanged',
            };

            if ($write && $action !== 'unchanged') {
                // Une transaction par fiche : une erreur sur une fiche n'en laisse aucune à moitié écrite.
                DB::transaction(function () use ($fiche, $hash, $existing) {
                    $content = $existing ?? new Content(['status' => 'published', 'published_at' => now()]);
                    $content->fill([
                        'type' => $fiche['type'],
                        'title' => $fiche['title'],
                        'slug' => $content->slug ?? Slugger::unique($fiche['title']),
                        'excerpt' => $fiche['excerpt'],
                        'body' => $fiche['body'],
                        'source' => $fiche['source'],
                        'source_hash' => $hash,
                    ])->save();
                    Tagger::sync($content, $fiche['tags']);
                });
            }

            $report[] = $line + ['action' => $action];
        }

        return $report;
    }
}
