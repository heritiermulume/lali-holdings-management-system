<?php

use App\Core\Support\PermissionCatalog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::forKams() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
        }

        $options = Permission::query()
            ->whereIn('slug', [
                'kams.dashboard.products.view',
                'kams.dashboard.orders.view',
                'kams.dashboard.finance.view',
                'kams.dashboard.recruitment.view',
            ])
            ->pluck('id');

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'kams.dashboard.view'))
            ->each(function (Role $role) use ($options) {
                $role->permissions()->syncWithoutDetaching($options);
            });
    }

    public function down(): void
    {
        Permission::query()
            ->whereIn('slug', [
                'kams.dashboard.products.view',
                'kams.dashboard.orders.view',
                'kams.dashboard.finance.view',
                'kams.dashboard.recruitment.view',
            ])
            ->delete();
    }
};
