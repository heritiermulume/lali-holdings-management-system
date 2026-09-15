<?php

use App\Core\Support\PermissionCatalog;
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

        $legacy = Permission::query()
            ->whereIn('slug', ['kams.view', 'kams.create', 'kams.update', 'kams.delete', 'kams.approve', 'kams.export'])
            ->pluck('id', 'slug');

        $map = [
            'kams.view' => ['kams.dashboard.view', 'kams.members.view', 'kams.recruitment.view', 'kams.clients.view', 'kams.products.view', 'kams.orders.view', 'kams.campaigns.view'],
            'kams.create' => ['kams.members.create', 'kams.recruitment.create', 'kams.clients.create', 'kams.products.create', 'kams.orders.create', 'kams.campaigns.create'],
            'kams.update' => ['kams.members.update', 'kams.recruitment.update', 'kams.clients.update', 'kams.products.update', 'kams.orders.update', 'kams.campaigns.update'],
            'kams.delete' => ['kams.members.delete', 'kams.recruitment.delete', 'kams.clients.delete', 'kams.products.delete', 'kams.campaigns.delete'],
            'kams.approve' => ['kams.recruitment.approve'],
            'kams.export' => ['kams.orders.export'],
        ];

        foreach ($map as $oldSlug => $newSlugs) {
            $oldId = $legacy[$oldSlug] ?? null;
            if (! $oldId) {
                continue;
            }

            $roleIds = DB::table('permission_role')->where('permission_id', $oldId)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                foreach ($newSlugs as $slug) {
                    $newId = $newIds[$slug] ?? null;
                    if (! $newId) {
                        continue;
                    }
                    DB::table('permission_role')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $newId],
                        []
                    );
                }
            }
        }

        Role::query()->whereIn('slug', ['super_administrator', 'president'])->each(function (Role $role) {
            $role->permissions()->sync(Permission::query()->pluck('id'));
        });
    }

    public function down(): void
    {
        $ids = Permission::query()->where('slug', 'like', 'kams.%.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
