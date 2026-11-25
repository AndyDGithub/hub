<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pour les routes authentifiées par token d'outil (Sanctum) : mêmes garde-fous que pour les humains.
 * Un compte banni ou qui n'a pas encore remplacé son mot de passe temporaire ne peut rien faire.
 */
class AccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if ($user->is_banned) {
            return response()->json(['message' => 'User is banned'], 403);
        }
        if ($user->must_change_password) {
            return response()->json(['message' => 'Password change required', 'must_change_password' => true], 403);
        }

        return $next($request);
    }
}
