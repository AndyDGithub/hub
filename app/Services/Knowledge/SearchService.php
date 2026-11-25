<?php

namespace App\Services\Knowledge;

use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recherche plein texte (ADR-3, étape 1). Sur MariaDB : index FULLTEXT, classement TF-IDF d'InnoDB.
 * Ailleurs (SQLite en dev) : simple LIKE, sans classement, pour que l'app reste utilisable.
 */
class SearchService
{
    /** @return Collection<int, array{id:int, slug:string, type:string, title:string, excerpt:?string, score:float, snippet:string}> */
    public function search(string $query, ?string $type = null, int $limit = 10): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        $builder = Content::query()
            ->select('id', 'slug', 'type', 'title', 'excerpt', 'body')
            ->where('status', '!=', 'archived')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->limit(min(50, max(1, $limit)));

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Requête liée (?) : le texte de l'utilisateur n'est jamais concaténé dans le SQL.
            $match = 'MATCH(title, excerpt, body) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $builder->selectRaw("$match AS score", [$query])
                ->whereRaw("$match > 0", [$query])
                ->orderByDesc('score');
        } else {
            $builder->selectRaw('1 AS score')->where(function ($q) use ($query) {
                foreach ($this->terms($query) as $term) {
                    $q->orWhere('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%");
                }
            });
        }

        return $builder->get()->map(fn (Content $c) => [
            'id' => $c->id,
            'slug' => $c->slug,
            'type' => $c->type,
            'title' => $c->title,
            'excerpt' => $c->excerpt,
            'score' => round((float) $c->score, 4),
            'snippet' => $this->snippet((string) $c->body, $query),
        ]);
    }

    /** ~240 caractères autour de la première occurrence d'un des termes. */
    private function snippet(string $body, string $query): string
    {
        $position = null;
        foreach ($this->terms($query) as $term) {
            $found = mb_stripos($body, $term);
            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }
        $start = max(0, ($position ?? 0) - 80);
        $text = preg_replace('~\s+~', ' ', mb_substr($body, $start, 240));

        return ($start > 0 ? '…' : '').trim($text).'…';
    }

    /** @return list<string> mots de 3 lettres ou plus (en dessous, InnoDB ne les indexe pas non plus). */
    private function terms(string $query): array
    {
        return array_values(array_filter(preg_split('~\W+~u', $query), fn ($t) => mb_strlen($t) >= 3));
    }
}
