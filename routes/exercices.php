<?php

use App\Http\Controllers\ExerciceController;
use Illuminate\Support\Facades\Route;

Route::prefix('exercices')->group(function () {
    Route::get('/', [ExerciceController::class, 'index']);
    Route::get('/{slug}', [ExerciceController::class, 'show']);

    Route::middleware(['jwt.auth', 'not_banned', 'role:maintainer|admin'])->group(function () {
        Route::post('/', [ExerciceController::class, 'store']);
        Route::patch('/{id}', [ExerciceController::class, 'update']);
    });

    Route::delete('/{id}', [ExerciceController::class, 'destroy'])
        ->middleware(['jwt.auth', 'not_banned', 'role:admin|maintainer']);
});
