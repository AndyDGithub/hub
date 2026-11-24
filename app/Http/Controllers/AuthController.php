<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\JwtService;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function register(Request $request, JwtService $jwt, PasswordService $passwordService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $validated['password'] = $passwordService->hash($validated['password']);
        $user = User::query()->create($validated);
        $defaultRole = Role::query()->where('name', 'reader')->where('guard_name', 'web')->first();
        if ($defaultRole) {
            $user->assignRole($defaultRole);
        }

        $token = $jwt->issueToken($user);

        return response()->json([
            'message' => 'User registered',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => (int) env('JWT_TTL', 60) * 60,
            'user' => $user->load('roles'),
        ], 201);
    }

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

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $jwt->issueToken($user);

        return response()->json([
            'message' => 'Login successful',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => (int) env('JWT_TTL', 60) * 60,
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
            'expires_in' => (int) env('JWT_TTL', 60) * 60,
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
