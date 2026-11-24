<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\JwtService;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, JwtService $jwt, PasswordService $passwordService): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! $passwordService->verify($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials'],
            ]);
        }

        if ($passwordService->needsUpgrade($user->password)) {
            $user->forceFill([
                'password' => $passwordService->hash($credentials['password']),
            ])->save();
        }

        if ($user->is_banned) {
            return response()->json(['message' => 'Your account is banned'], 403);
        }

        // Mot de passe temporaire périmé : il faut en demander un nouveau à un admin.
        if ($user->must_change_password && $user->temporary_password_expires_at?->isPast()) {
            return response()->json(['message' => 'Temporary password expired, ask an administrator for a new one'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $jwt->issueToken($user);

        return response()->json([
            'message' => 'Login successful',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => $jwt->ttlSeconds(),
            // true = le client doit d'abord appeler POST /api/auth/password (tout le reste renvoie 403).
            'must_change_password' => $user->must_change_password,
            'user' => $user->load('roles'),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()?->load('roles', 'permissions'),
        ]);
    }

    public function refresh(Request $request, JwtService $jwt): JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Missing bearer token'], 401);
        }

        $payload = $jwt->decode($token, allowExpired: true);
        if (! $payload || empty($payload['sub']) || empty($payload['jti'])) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        if ($jwt->isBlacklisted($payload['jti'])) {
            return response()->json(['message' => 'Token revoked'], 401);
        }

        if (($payload['r_exp'] ?? 0) < now()->timestamp) {
            return response()->json(['message' => 'Refresh window expired'], 401);
        }

        $user = User::query()->find($payload['sub']);
        if (! $user) {
            return response()->json(['message' => 'User not found'], 401);
        }

        $jwt->blacklist($payload['jti'], $payload['r_exp']);
        $newToken = $jwt->issueToken($user);

        return response()->json([
            'message' => 'Token refreshed',
            'token_type' => 'Bearer',
            'access_token' => $newToken,
            'expires_in' => $jwt->ttlSeconds(),
        ]);
    }

    public function logout(Request $request, JwtService $jwt): JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Missing bearer token'], 401);
        }

        $payload = $jwt->decode($token, allowExpired: true);
        if (! $payload || empty($payload['jti']) || empty($payload['r_exp'])) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $jwt->blacklist($payload['jti'], $payload['r_exp']);

        return response()->json(['message' => 'Logout successful']);
    }

    public function changePassword(Request $request, JwtService $jwt, PasswordService $passwordService): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:10', 'confirmed', 'different:current_password'],
        ]);

        if (! $passwordService->verify($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect'],
            ]);
        }

        $user->forceFill([
            'password' => $passwordService->hash($validated['new_password']),
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
        ])->save();

        $token = $request->bearerToken();
        if ($token) {
            $payload = $jwt->decode($token, allowExpired: true);
            if ($payload && ! empty($payload['jti']) && ! empty($payload['r_exp'])) {
                $jwt->blacklist($payload['jti'], $payload['r_exp']);
            }
        }

        return response()->json([
            'message' => 'Password updated successfully. Please login again.',
        ]);
    }
}
