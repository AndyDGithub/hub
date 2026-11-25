<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Str;

/**
 * Découpe les fichiers Markdown de la megadoc en fiches. Ne touche pas à la base.
 *
 * - megadoc_skills.md (fiches de concept) : une fiche par section « ### », taguée par son
 *   chapitre « ## » et son module (« **Module : X** »).
 * - autres pages (~/megadoc/*.md) : une fiche par fichier ; type project si la page indique sa source.
 *
 * Une fiche = ['source', 'type', 'title', 'excerpt', 'body', 'tags'].
 */
class MegadocParser
{
    /** @return list<array{source:string, type:string, title:string, excerpt:?string, body:string, tags:list<string>}> */
    public function parseSkills(string $markdown, string $sourcePrefix): array
    {
        $fiches = [];
        $chapter = null;
        $modules = [];
        $current = null;

        foreach ($this->lines($markdown) as [$line, $inCode]) {
            // Un « # » dans un bloc de code est un commentaire shell/YAML, pas un titre.
            if (! $inCode && preg_match('~^(#{2,3}) (.+)$~', $line, $m)) {
                if ($current) {
                    $fiches[] = $current;
                    $current = null;
                }
                if ($m[1] === '##') {
                    $chapter = trim(preg_replace('~^\d+\.\s*~', '', $m[2]));
                    $modules = [];

                    continue;
                }
                if ($chapter === null || Str::lower($chapter) === 'table des matières') {
                    continue;
                }
                $title = trim($m[2]);
                $current = [
                    'source' => $sourcePrefix.'#'.Str::slug($chapter).'--'.Str::slug($title),
                    'type' => 'concept',
                    'title' => $title,
                    'body' => '',
                    'tags' => array_merge([$chapter], $modules),
                ];

                continue;
            }

            if (! $inCode && $current === null && preg_match('~^\*\*Modules? ?: (.+)\*\*~u', $line, $m)) {
                $modules = array_map('trim', preg_split('~[·,/]~u', $m[1]));
            }
            if ($current) {
                $current['body'] .= $line."\n";
            }
        }
        if ($current) {
            $fiches[] = $current;
        }

        return array_values(array_filter(array_map([$this, 'finish'], $fiches), fn ($f) => $f['body'] !== ''));
    }

    /** @return array{source:string, type:string, title:string, excerpt:?string, body:string, tags:list<string>} */
    public function parsePage(string $markdown, string $source, string $fallbackTitle): array
    {
        $title = $fallbackTitle;
        foreach ($this->lines($markdown) as [$line, $inCode]) {
            if (! $inCode && preg_match('~^# (.+)$~', $line, $m)) {
                $title = trim($m[1]);
                break;
            }
        }

        return $this->finish([
            'source' => $source,
            'type' => preg_match('~^\*\*Source ?:\*\*~mu', $markdown) ? 'project' : 'note',
            'title' => $title,
            'body' => $markdown,
            'tags' => ['megadoc'],
        ]);
    }

    private function finish(array $fiche): array
    {
        $fiche['body'] = trim($fiche['body']);
        $fiche['title'] = Str::limit($fiche['title'], 250, '…');
        $fiche['excerpt'] = $this->excerpt($fiche['body']);

        return $fiche;
    }

    /** Premières lignes de texte suivies (ni titre, ni code, ni tableau, ni citation), sans Markdown. */
    private function excerpt(string $body): ?string
    {
        $text = [];
        foreach ($this->lines($body) as [$line, $inCode]) {
            $structural = $inCode || preg_match('~^\s*(#|\||>|---|\*\*Modules?\b|[-*] |\d+\. )~u', $line);
            if (trim($line) === '' || $structural) {
                if ($text) {
                    break;   // fin du premier paragraphe
                }

                continue;
            }
            $text[] = trim($line);
        }

        return $text ? Str::limit(str_replace(['**', '`'], '', implode(' ', $text)), 280) : null;
    }

    /** @return \Generator<array{0:string, 1:bool}> chaque ligne + « est-on dans un bloc ``` ? » */
    private function lines(string $markdown): \Generator
    {
        $inCode = false;
        foreach (preg_split("~\r?\n~", $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inCode = ! $inCode;
                yield [$line, true];

                continue;
            }
            yield [$line, $inCode];
        }
    }
}
