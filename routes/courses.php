<?php

use App\Http\Controllers\CourseController;
use Illuminate\Support\Facades\Route;

Route::prefix('courses')->group(function () {
    Route::get('/', [CourseController::class, 'index']);
    Route::get('/{slug}', [CourseController::class, 'show']);

    Route::middleware(['jwt.auth', 'not_banned', 'role:maintainer|admin'])->group(function () {
        Route::post('/', [CourseController::class, 'store']);
        Route::patch('/{id}', [CourseController::class, 'update']);
    });

    Route::delete('/{id}', [CourseController::class, 'destroy'])
        ->middleware(['jwt.auth', 'not_banned', 'role:admin|maintainer']);
});
