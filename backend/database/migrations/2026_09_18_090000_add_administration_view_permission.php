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
        foreach (PermissionCatalog::core() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
        }

        $adminViewId = Permission::query()->where('slug', 'administration.view')->value('id');
        $legacyIds = Permission::query()->whereIn('slug', ['dashboard.group', 'dashboard.company'])->pluck('id');

        if ($adminViewId) {
            $roleIds = DB::table('permission_role')
                ->whereIn('permission_id', Permission::query()->where('group', 'administration')->pluck('id')->all() ?: [0])
                ->pluck('role_id')
                ->unique();

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $adminViewId, 'role_id' => $roleId],
                    ['permission_id' => $adminViewId, 'role_id' => $roleId],
                );
            }
        }

        if ($legacyIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $legacyIds)->delete();
            Permission::query()->whereIn('id', $legacyIds)->delete();
        }

        $permissionIds = Permission::query()->pluck('id', 'slug');
        foreach (RoleCatalog::all() as $roleData) {
            $role = Role::query()->where('slug', $roleData['slug'])->first();
            if (! $role || $role->hasFullAccess()) {
                continue;
            }
            $slugs = RoleCatalog::permissionSlugsFor($roleData['slug']) ?? [];
            $role->permissions()->sync($permissionIds->only($slugs)->values());
        }
    }

    public function down(): void
    {
        foreach ([
            ['slug' => 'dashboard.group', 'group' => 'administration', 'name' => 'View group dashboard'],
            ['slug' => 'dashboard.company', 'group' => 'administration', 'name' => 'View company dashboard'],
        ] as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
        }

        $adminView = Permission::query()->where('slug', 'administration.view')->first();
        if ($adminView) {
            DB::table('permission_role')->where('permission_id', $adminView->id)->delete();
            $adminView->delete();
        }
    }
};
