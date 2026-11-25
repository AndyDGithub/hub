<?php

namespace App\Services\Knowledge;

use App\Models\Content;
use App\Models\Resolution;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * « Chercher d'abord ; si correspondance, incrémenter ; sinon créer » (ADR-2).
 *
 * Concurrence : deux outils peuvent signaler la même erreur au même moment.
 * - l'incrément est fait par la base (UPDATE … SET occurrences = occurrences + 1), donc atomique :
 *   pas de lecture-modification-écriture dans PHP qui perdrait un incrément ;
 * - la création s'appuie sur l'index UNIQUE de error_fingerprint : si une autre requête a créé
 *   la fiche entre-temps, l'INSERT échoue et on retombe sur le cas « déjà connue ».
 */
class ResolutionRecorder
{
    public function __construct(
        private readonly ErrorNormalizer $normalizer,
        private readonly SearchService $search,
    ) {}

    /**
     * @param  array{error_message:string, context?:array, title?:string, cause?:string, solution?:string, tags?:list<string>, verification?:string}  $data
     * @return array{status:'known'|'created'|'unknown', content?:Content, suggestions?:mixed}
     */
    public function record(array $data, ?User $author): array
    {
        $fingerprint = $this->normalizer->fingerprint($data['error_message']);

        if ($known = $this->increment($fingerprint)) {
            return ['status' => 'known', 'content' => $known];
        }

        // Rien de connu et pas de solution à enregistrer : on ne crée pas de fiche vide,
        // on renvoie les fiches les plus proches.
        if (empty($data['solution'])) {
            return ['status' => 'unknown', 'suggestions' => $this->search->search($data['error_message'], limit: 5)];
        }

        try {
            $content = DB::transaction(fn () => $this->create($data, $fingerprint, $author));
        } catch (UniqueConstraintViolationException $e) {
            // Créée par une requête concurrente : on la compte. Si elle n'existe toujours pas,
            // la violation venait d'ailleurs (ex. slug) → on ne masque pas l'erreur.
            $known = $this->increment($fingerprint) ?? throw $e;

            return ['status' => 'known', 'content' => $known];
        }

        return ['status' => 'created', 'content' => $content];
    }

    private function increment(string $fingerprint): ?Content
    {
        $updated = Resolution::query()->where('error_fingerprint', $fingerprint)
            ->update(['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => now()]);

        return $updated
            ? Content::query()->whereHas('resolution', fn ($q) => $q->where('error_fingerprint', $fingerprint))->with('resolution', 'tags')->first()
            : null;
    }

    private function create(array $data, string $fingerprint, ?User $author): Content
    {
        $title = $data['title'] ?? Str::limit(strtok($data['error_message'], "\n"), 120, '…');
        $body = "## Symptôme\n\n```\n{$data['error_message']}\n```\n\n"
            .(empty($data['cause']) ? '' : "## Cause\n\n{$data['cause']}\n\n")
            ."## Solution\n\n{$data['solution']}\n";

        $content = Content::query()->create([
            'type' => 'resolution',
            'title' => $title,
            'slug' => Slugger::unique($title),
            'excerpt' => Str::limit($data['cause'] ?? $data['solution'], 280),
            'body' => $body,
            'status' => 'published',
            'verification' => $data['verification'] ?? null,
            'author_id' => $author?->id,
            'published_at' => now(),
        ]);
        $content->resolution()->create([
            'error_fingerprint' => $fingerprint,
            'error_message' => $data['error_message'],
            'context' => $data['context'] ?? null,
            'last_seen_at' => now(),
        ]);
        Tagger::sync($content, $data['tags'] ?? []);

        return $content->load('resolution', 'tags');
    }
}
