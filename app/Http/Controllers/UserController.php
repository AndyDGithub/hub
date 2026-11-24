<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'users' => User::query()
                ->select('id', 'name', 'username', 'email', 'is_banned', 'must_change_password', 'last_login_at', 'created_at')
                ->with('roles:id,name')
                ->orderByDesc('id')
                ->paginate(min(100, max(1, (int) $request->query('per_page', 10)))),
        ]);
    }

    /** Crée un compte avec un mot de passe temporaire (remplace l'ancienne inscription publique). */
    public function store(Request $request, UserProvisioner $provisioner): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            // Seuls les rôles que l'appelant peut attribuer passent la validation.
            'role' => ['required', 'string', Rule::in($provisioner->assignableRoles($request->user()))],
        ]);

        [$user, $temporaryPassword] = $provisioner->create($validated, $request->user(), $request->ip());

        return response()->json([
            'message' => 'User created. Send the temporary password to the person; it is shown only once.',
            'user' => $user->load('roles:id,name'),
            'temporary_password' => $temporaryPassword,
            'temporary_password_expires_at' => $user->temporary_password_expires_at,
        ], 201);
    }
}
