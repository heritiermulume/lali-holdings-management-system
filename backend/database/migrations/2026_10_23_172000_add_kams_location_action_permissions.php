<?php

use App\Core\Support\PermissionCatalog;
use App\Core\Support\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $slugs = [
        'kams.stock.warehouses.create',
        'kams.stock.warehouses.receive',
        'kams.stock.warehouses.transfer',
        'kams.stock.depots.create',
        'kams.stock.depots.update',
        'kams.stock.depots.receive',
        'kams.stock.depots.supply',
        'kams.stock.depots.remove',
        'kams.stock.depots.adjust',
        'kams.stock.depots.price',
    ];

    public function up(): void
    {
        foreach (PermissionCatalog::forKams() as $permission) {
            if (! in_array($permission['slug'], $this->slugs, true)) {
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

        $permissionIds = DB::table('permissions')->whereIn('slug', $this->slugs)->pluck('id', 'slug');

        foreach (RoleCatalog::all() as $roleData) {
            $roleId = DB::table('roles')->where('slug', $roleData['slug'])->value('id');
            if (! $roleId) {
                continue;
            }

            $granted = RoleCatalog::permissionSlugsFor($roleData['slug']);
            $targetSlugs = $granted === null
                ? $this->slugs
                : array_values(array_intersect($this->slugs, $granted));

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
        $ids = DB::table('permissions')->whereIn('slug', $this->slugs)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
