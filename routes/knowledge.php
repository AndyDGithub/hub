<?php

use App\Http\Controllers\KnowledgeController;
use Illuminate\Support\Facades\Route;

// Base de connaissance (megadoc, résolutions). Lecture réservée aux comptes ayant content.read :
// contrairement à /docs, rien n'est public ici (visibilité fine en phase 2).
Route::prefix('knowledge')->middleware(['jwt.auth', 'not_banned'])->group(function () {
    Route::middleware('permission:content.read')->group(function () {
        Route::get('/search', [KnowledgeController::class, 'search'])->middleware('throttle:60,1');
        Route::get('/{slug}', [KnowledgeController::class, 'show']);
    });
    Route::post('/resolutions', [KnowledgeController::class, 'record'])
        ->middleware(['permission:content.create', 'throttle:30,1']);
});
