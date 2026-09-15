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

        $copy = [
            'kams.members.view' => ['kams.members.export', 'kams.members.print', 'kams.members.preview'],
            'kams.members.create' => ['kams.members.import'],
            'kams.members.update' => ['kams.members.activate'],
        ];

        foreach ($copy as $from => $targets) {
            $fromId = Permission::query()->where('slug', $from)->value('id');
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

        Role::query()->whereIn('slug', RoleCatalog::fullAccessSlugs())->each(function (Role $role) {
            $role->permissions()->sync(Permission::query()->pluck('id'));
        });
    }

    public function down(): void
    {
        $slugs = ['kams.members.import', 'kams.members.export', 'kams.members.print', 'kams.members.preview', 'kams.members.activate'];
        $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
