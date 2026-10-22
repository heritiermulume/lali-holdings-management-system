<?php

use App\Core\Support\PermissionCatalog;
use App\Core\Support\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = [
            'kams.stock.warehouses.update',
            'kams.stock.warehouses.delete',
            'kams.stock.depots.delete',
        ];

        foreach (PermissionCatalog::forKams() as $permission) {
            if (! in_array($permission['slug'], $slugs, true)) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id', 'slug');

        foreach (RoleCatalog::all() as $roleData) {
            $roleId = DB::table('roles')->where('slug', $roleData['slug'])->value('id');
            if (! $roleId) {
                continue;
            }

            $granted = RoleCatalog::permissionSlugsFor($roleData['slug']);
            $targetSlugs = $granted === null
                ? $slugs
                : array_values(array_intersect($slugs, $granted));

            foreach ($targetSlugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;
                if (! $permissionId) {
                    continue;
                }
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    []
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('slug', [
                'kams.stock.warehouses.update',
                'kams.stock.warehouses.delete',
                'kams.stock.depots.delete',
            ])
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
