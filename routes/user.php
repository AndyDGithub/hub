<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt.auth', 'not_banned'])->group(function () {
    Route::get('/me', function () {
        return response()->json([
            'user' => request()->user()?->load('roles', 'permissions'),
        ]);
    });

    Route::get('/users', function () {
        $user = request()->user();

        if (! $user || ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'users' => User::query()
                ->select('id', 'name', 'username', 'email', 'is_banned', 'last_login_at', 'created_at')
                ->with('roles:id,name')
                ->orderByDesc('id')
                ->paginate((int) request()->query('per_page', 10)),
        ]);
    });
});
