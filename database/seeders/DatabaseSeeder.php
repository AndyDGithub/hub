<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée uniquement les rôles et permissions (idempotent : relançable sans risque).
 * Aucun compte n'est créé ici : le premier admin se crée avec
 *   php artisan hub:user:create <email> --role=admin
 */
class DatabaseSeeder extends Seeder
{
    // tools.use = outils standard ; tools.premium = outils coûteux (cluster, GPU, API payantes).
    // Les permissions admin.* sont réservées à l'admin : rôles et permissions, comptes maintainer/admin,
    // données sensibles (réglages, secrets d'intégration, journal d'audit complet).
    private const READ = ['content.read', 'attachment.download'];

    private const EDIT = ['content.create', 'content.update', 'attachment.create'];

    private const TOOLS = ['tools.use', 'tools.premium'];

    public const ROLES = [
        'admin' => [...self::READ, ...self::EDIT, ...self::TOOLS, 'content.delete', 'user.manage', 'admin.roles', 'admin.sensitive'],
        'maintainer' => [...self::READ, ...self::EDIT, ...self::TOOLS, 'user.manage'],
        'premium' => [...self::READ, ...self::TOOLS],
        'user' => [...self::READ, 'tools.use'],
        'reader' => self::READ,
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_unique(array_merge(...array_values(self::ROLES))) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            // syncPermissions : le code est la source de vérité, une permission retirée ici est retirée en base.
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
    }
}
