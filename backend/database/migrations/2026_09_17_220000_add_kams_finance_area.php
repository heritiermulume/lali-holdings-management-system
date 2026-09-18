<?php

use App\Core\Support\PermissionCatalog;
use App\Core\Support\RoleCatalog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newIds = [];
        foreach (PermissionCatalog::forKams() as $permission) {
            $model = Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
            $newIds[$permission['slug']] = $model->id;
        }

        $fromView = Permission::query()->where('slug', 'kams.products.view')->value('id');
        $fromUpdate = Permission::query()->where('slug', 'kams.products.update')->value('id');
        $fromExport = Permission::query()->where('slug', 'kams.orders.export')->value('id');

        $copy = [
            $fromView => ['kams.finance.view'],
            $fromUpdate => ['kams.finance.update', 'kams.finance.create'],
            $fromExport => ['kams.finance.export'],
        ];

        foreach ($copy as $fromId => $targets) {
            if (! $fromId) {
                continue;
            }
            $roleIds = DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                foreach ($targets as $slug) {
                    $toId = $newIds[$slug] ?? null;
                    if (! $toId) {
                        continue;
                    }
                    DB::table('permission_role')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $toId],
                        []
                    );
                }
            }
        }

        $permissionIds = Permission::query()->pluck('id', 'slug');
        foreach (RoleCatalog::all() as $roleData) {
            $role = Role::query()->where('slug', $roleData['slug'])->first();
            if (! $role) {
                continue;
            }
            $slugs = RoleCatalog::permissionSlugsFor($roleData['slug']);
            if ($slugs === null) {
                $role->permissions()->sync($permissionIds->values());
            } else {
                $role->permissions()->sync($permissionIds->only($slugs)->values());
            }
        }
    }

    public function down(): void
    {
        $slugs = ['kams.finance.view', 'kams.finance.create', 'kams.finance.update', 'kams.finance.export'];
        $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
