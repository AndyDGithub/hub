<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class Authenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Missing bearer token'], 401);
        }

        /** @var JwtService $jwt */
        $jwt = app(JwtService::class);
        $payload = $jwt->decode($token);

        if (! $payload || empty($payload['sub']) || empty($payload['jti'])) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        if ($jwt->isBlacklisted($payload['jti'])) {
            return response()->json(['message' => 'Token revoked'], 401);
        }

        $user = User::query()->find($payload['sub']);
        if (! $user) {
            return response()->json(['message' => 'User not found'], 401);
        }

        // Tant que le mot de passe temporaire n'est pas changé, seules ces routes sont ouvertes.
        if ($user->must_change_password && ! $request->is('api/auth/password', 'api/auth/logout', 'api/auth/me')) {
            return response()->json(['message' => 'Password change required', 'must_change_password' => true], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
