<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt.auth', 'not_banned'])->group(function () {
    Route::get('/me', fn () => response()->json([
        'user' => request()->user()?->load('roles', 'permissions'),
    ]));

    // Gestion des comptes : admin et maintainer. Le rôle attribuable est restreint dans
    // UserController::store (config hub.assignable_roles).
    Route::middleware('role:admin|maintainer')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store'])->middleware('throttle:20,1');
    });
});
