<?php

use App\Http\Controllers\DocController;
use Illuminate\Support\Facades\Route;

Route::prefix('docs')->group(function () {
    Route::get('/', [DocController::class, 'index']);
    Route::get('/{slug}', [DocController::class, 'show']);

    Route::middleware(['jwt.auth', 'not_banned', 'role:maintainer|admin'])->group(function () {
        Route::post('/', [DocController::class, 'store']);
        Route::patch('/{id}', [DocController::class, 'update']);
    });

    Route::delete('/{id}', [DocController::class, 'destroy'])
        ->middleware(['jwt.auth', 'not_banned', 'role:admin|maintainer']);
});
