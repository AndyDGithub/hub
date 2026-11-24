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
    public const ROLES = [
        'admin' => [
            'content.read', 'content.create', 'content.update', 'content.delete',
            'attachment.create', 'attachment.download', 'user.manage',
        ],
        'maintainer' => [
            'content.read', 'content.create', 'content.update',
            'attachment.create', 'attachment.download', 'user.manage',
        ],
        'reader' => [
            'content.read', 'attachment.download',
        ],
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
