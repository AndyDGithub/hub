<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /** @var PasswordService $passwordService */
        $passwordService = app(PasswordService::class);

        $permissions = [
            'content.read',
            'content.create',
            'content.update',
            'content.delete',
            'attachment.create',
            'attachment.download',
            'user.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $roles = [
            'admin' => $permissions,
            'maintainer' => [
                'content.read',
                'content.create',
                'content.update',
                'attachment.create',
                'attachment.download',
            ],
            'reader' => [
                'content.read',
                'attachment.download',
            ],
        ];

        foreach ($roles as $role => $rolePermissions) {
            $roleModel = Role::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);

            foreach ($rolePermissions as $permissionName) {
                if (! $roleModel->hasPermissionTo($permissionName)) {
                    $roleModel->givePermissionTo($permissionName);
                }
            }
        }

        $admin = User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@portfolio.local')],
            [
                'name' => env('ADMIN_NAME', 'Portfolio Admin'),
                'username' => env('ADMIN_USERNAME', 'portfolio_admin'),
                'password' => $passwordService->hash(env('ADMIN_PASSWORD', 'ChangeMeNow123!')),
                'is_banned' => false,
            ]
        );

        $admin->assignRole('admin');

        $reader = User::query()->firstOrCreate(
            ['email' => env('READER_EMAIL', 'reader@portfolio.local')],
            [
                'name' => env('READER_NAME', 'Portfolio Reader'),
                'username' => env('READER_USERNAME', 'portfolio_reader'),
                'password' => $passwordService->hash(env('READER_PASSWORD', 'ReaderPass123!')),
                'is_banned' => false,
            ]
        );

        $reader->assignRole('reader');
    }
}
