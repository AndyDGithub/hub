<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Création de comptes par un admin/maintainer (pas d'inscription publique, cf. ADR-4).
 * Utilisé par l'API (POST /api/users) et par la commande `php artisan hub:user:create`.
 */
class UserProvisioner
{
    public function __construct(private readonly PasswordService $passwords) {}

    /** Rôles que $creator a le droit d'attribuer (vide si aucun). null = console (tous). */
    public function assignableRoles(?User $creator): array
    {
        $matrix = config('hub.assignable_roles');

        if ($creator === null) {
            return array_values(array_unique(array_merge(...array_values($matrix))));
        }

        $roles = [];
        foreach ($creator->getRoleNames() as $role) {
            $roles = array_merge($roles, $matrix[$role] ?? []);
        }

        return array_values(array_unique($roles));
    }

    /**
     * Crée le compte avec un mot de passe temporaire et renvoie [User, mot de passe en clair].
     * Le mot de passe en clair n'est stocké nulle part : il faut le transmettre tout de suite.
     *
     * @param  array{name: string, email: string, username?: ?string, role: string}  $data
     * @return array{0: User, 1: string}
     */
    public function create(array $data, ?User $creator, ?string $ip = null): array
    {
        // Vérifié ici aussi (pas seulement dans la validation HTTP) pour couvrir la console.
        if (! in_array($data['role'], $this->assignableRoles($creator), true)) {
            throw new InvalidArgumentException("Rôle « {$data['role']} » non attribuable.");
        }

        $temporary = $this->passwords->generateTemporary();

        // Transaction : pas de compte sans rôle ni sans trace dans le journal d'audit.
        $user = DB::transaction(function () use ($data, $creator, $ip, $temporary) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'] ?? null,
                'password' => $this->passwords->hash($temporary),
            ]);
            $user->forceFill([
                'must_change_password' => true,
                'temporary_password_expires_at' => now()->addHours((int) config('hub.passwords.temporary_ttl_hours')),
            ])->save();
            $user->assignRole($data['role']);

            AuditLog::query()->create([
                'user_id' => $creator?->id,
                'action' => 'user.created',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'meta' => ['role' => $data['role'], 'via' => $creator ? 'api' : 'console'],
                'ip_address' => $ip,
            ]);

            return $user;
        });

        return [$user, $temporary];
    }
}
