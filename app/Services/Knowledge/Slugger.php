<?php

namespace App\Services\Knowledge;

use App\Models\Content;
use Illuminate\Support\Str;

final class Slugger
{
    /** Slug unique parmi les contents (y compris supprimés logiquement : l'index unique les voit). */
    public static function unique(string $title, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($title) ?: 'fiche', 180, '');
        $slug = $base;
        for ($i = 2; Content::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
