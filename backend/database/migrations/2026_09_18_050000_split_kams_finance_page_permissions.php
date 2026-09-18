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

        $legacy = Permission::query()
            ->whereIn('slug', ['kams.finance.view', 'kams.finance.create', 'kams.finance.update', 'kams.finance.export'])
            ->pluck('id', 'slug');

        $map = [
            'kams.finance.view' => [
                'kams.finance.dashboard.view',
                'kams.finance.account.view',
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.account.print',
                'kams.finance.account.preview',
            ],
            'kams.finance.create' => [
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.company.deposit',
            ],
            'kams.finance.update' => [
                'kams.finance.dashboard.update',
                'kams.finance.account.update',
                'kams.finance.company.view',
                'kams.finance.company.deposit',
                'kams.finance.company.withdraw',
                'kams.finance.company.expense',
                'kams.finance.company.approve',
                'kams.finance.company.print',
                'kams.finance.company.preview',
            ],
            'kams.finance.export' => [
                'kams.finance.account.print',
                'kams.finance.account.preview',
                'kams.finance.company.print',
                'kams.finance.company.preview',
            ],
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

        $legacyIds = Permission::query()
            ->whereIn('slug', ['kams.finance.view', 'kams.finance.create', 'kams.finance.update', 'kams.finance.export'])
            ->pluck('id');
        if ($legacyIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $legacyIds)->delete();
            Permission::query()->whereIn('id', $legacyIds)->delete();
        }
    }

    public function down(): void
    {
        $legacy = [
            ['slug' => 'kams.finance.view', 'group' => 'kams.finance', 'name' => 'View kams finance'],
            ['slug' => 'kams.finance.create', 'group' => 'kams.finance', 'name' => 'Create kams finance'],
            ['slug' => 'kams.finance.update', 'group' => 'kams.finance', 'name' => 'Update kams finance'],
            ['slug' => 'kams.finance.export', 'group' => 'kams.finance', 'name' => 'Export kams finance'],
        ];
        foreach ($legacy as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
        }

        $ids = Permission::query()->where('slug', 'like', 'kams.finance.%.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
