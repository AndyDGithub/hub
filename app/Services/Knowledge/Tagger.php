<?php

namespace App\Services\Knowledge;

use App\Models\Content;
use App\Models\Tag;

final class Tagger
{
    /** @param list<string> $names */
    public static function sync(Content $content, array $names): void
    {
        $ids = collect($names)
            ->map(fn ($n) => mb_strtolower(trim($n)))
            ->filter()
            ->unique()
            ->map(fn ($n) => Tag::query()->createOrFirst(['name' => mb_substr($n, 0, 100)])->id);

        // createOrFirst (et non firstOrCreate) : si deux imports créent le même tag en même temps,
        // l'index unique tranche et on relit la ligne existante au lieu d'échouer.
        $content->tags()->sync($ids->all());
    }
}
