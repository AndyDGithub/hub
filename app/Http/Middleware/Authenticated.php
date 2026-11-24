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

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
