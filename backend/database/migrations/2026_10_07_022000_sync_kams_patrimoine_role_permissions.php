<?php

use App\Core\Support\PermissionCatalog;
use App\Core\Support\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::forKams() as $permission) {
            if (! str_starts_with($permission['slug'], 'kams.patrimoine.')) {
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

        $permissionIds = DB::table('permissions')
            ->where('slug', 'like', 'kams.patrimoine.%')
            ->pluck('id', 'slug');

        foreach (RoleCatalog::all() as $roleData) {
            $roleId = DB::table('roles')->where('slug', $roleData['slug'])->value('id');
            if (! $roleId) {
                continue;
            }

            $slugs = RoleCatalog::permissionSlugsFor($roleData['slug']);
            if ($slugs === null) {
                $targetSlugs = $permissionIds->keys()->all();
            } else {
                $targetSlugs = array_values(array_filter(
                    $slugs,
                    fn (string $slug) => str_starts_with($slug, 'kams.patrimoine.')
                ));
            }

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
        // Keep permissions and role grants; only a forward sync.
    }
};
