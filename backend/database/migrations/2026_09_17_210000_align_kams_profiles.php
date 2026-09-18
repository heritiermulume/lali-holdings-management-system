<?php

use App\Core\Support\RoleCatalog;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (RoleCatalog::all() as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['name'],
                    'level' => $role['level'],
                    'module_slug' => $role['module_slug'],
                    'is_system' => true,
                ]
            );
        }

        $ids = Role::query()->pluck('id', 'slug');
        foreach (RoleCatalog::legacySlugMap() as $oldSlug => $newSlug) {
            $oldId = $ids[$oldSlug] ?? null;
            $newId = $ids[$newSlug] ?? null;
            if (! $oldId || ! $newId || $oldId === $newId) {
                continue;
            }

            $this->reassignRole($oldId, $newId);

            Role::query()->whereKey($oldId)->each(function (Role $role) {
                $role->permissions()->detach();
                $role->offices()->detach();
                $role->delete();
            });

            $ids = Role::query()->pluck('id', 'slug');
        }

        Role::query()->whereIn('slug', RoleCatalog::fullAccessSlugs())->each(function (Role $role) {
            $role->offices()->detach();
            DB::table('role_user')->where('role_id', $role->id)->update(['company_id' => null]);
        });

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

        $kamsOfficeIds = Office::query()
            ->whereHas('company.module', fn ($module) => $module->where('slug', 'kams'))
            ->pluck('id');

        if ($kamsOfficeIds->isNotEmpty()) {
            Role::query()
                ->where('module_slug', 'kams')
                ->whereNotIn('slug', RoleCatalog::fullAccessSlugs())
                ->get()
                ->each(function (Role $role) use ($kamsOfficeIds) {
                    $role->offices()->syncWithoutDetaching($kamsOfficeIds);
                });
        }

        Role::query()
            ->where('is_system', true)
            ->whereNotIn('slug', RoleCatalog::slugs())
            ->each(function (Role $role) {
                $fallback = Role::query()->where('slug', 'distributor')->first();
                if ($fallback) {
                    $this->reassignRole($role->id, $fallback->id);
                }
                $role->permissions()->detach();
                $role->offices()->detach();
                $role->delete();
            });
    }

    public function down(): void
    {
        //
    }

    private function reassignRole(int $oldId, int $newId): void
    {
        $userIds = DB::table('role_user')->where('role_id', $oldId)->pluck('user_id');
        foreach ($userIds as $userId) {
            $exists = DB::table('role_user')->where('user_id', $userId)->where('role_id', $newId)->exists();
            if ($exists) {
                DB::table('role_user')->where('user_id', $userId)->where('role_id', $oldId)->delete();
            } else {
                DB::table('role_user')->where('user_id', $userId)->where('role_id', $oldId)->update(['role_id' => $newId]);
            }
        }

        if (Schema::hasTable('kams_members')) {
            DB::table('kams_members')->where('role_id', $oldId)->update(['role_id' => $newId]);
        }
    }
};
