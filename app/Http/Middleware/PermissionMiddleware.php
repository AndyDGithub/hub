<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $allowedPermissions = array_filter(array_map('trim', explode('|', $permissions)));
        if (! $user->hasAnyPermission($allowedPermissions)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
