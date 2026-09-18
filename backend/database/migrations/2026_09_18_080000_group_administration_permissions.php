<?php

use App\Core\Support\PermissionCatalog;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::core() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
        }
    }

    public function down(): void
    {
        $previous = [
            'dashboard.group' => 'dashboard',
            'dashboard.company' => 'dashboard',
            'users.view' => 'users',
            'users.create' => 'users',
            'users.update' => 'users',
            'users.delete' => 'users',
            'users.manage' => 'users',
            'roles.view' => 'roles',
            'roles.manage' => 'roles',
            'organization.view' => 'organization',
            'organization.create' => 'organization',
            'organization.update' => 'organization',
            'organization.delete' => 'organization',
            'companies.view' => 'companies',
            'companies.create' => 'companies',
            'companies.update' => 'companies',
            'companies.delete' => 'companies',
            'audit.view' => 'audit',
            'settings.manage' => 'settings',
        ];

        foreach ($previous as $slug => $group) {
            Permission::query()->where('slug', $slug)->update(['group' => $group]);
        }
    }
};
