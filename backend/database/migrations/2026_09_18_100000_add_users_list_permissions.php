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

        $newSlugs = ['users.import', 'users.export', 'users.print', 'users.preview'];
        $newIds = Permission::query()->whereIn('slug', $newSlugs)->pluck('id', 'slug');
        $viewId = Permission::query()->where('slug', 'users.view')->value('id');

        if ($viewId) {
            $roleIds = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                foreach ($newIds as $permissionId) {
                    DB::table('permission_role')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $permissionId],
                        []
                    );
                }
            }
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
        $ids = Permission::query()
            ->whereIn('slug', ['users.import', 'users.export', 'users.print', 'users.preview'])
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            Permission::query()->whereIn('id', $ids)->delete();
        }
    }
};
