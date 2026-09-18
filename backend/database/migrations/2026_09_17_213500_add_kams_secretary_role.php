<?php

use App\Core\Support\RoleCatalog;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'slug');

        foreach (RoleCatalog::all() as $roleData) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['name'],
                    'level' => $roleData['level'],
                    'module_slug' => $roleData['module_slug'],
                    'is_system' => true,
                ]
            );

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

        if ($kamsOfficeIds->isEmpty()) {
            return;
        }

        Role::query()
            ->where('module_slug', 'kams')
            ->whereNotIn('slug', RoleCatalog::fullAccessSlugs())
            ->get()
            ->each(function (Role $role) use ($kamsOfficeIds) {
                $role->offices()->syncWithoutDetaching($kamsOfficeIds);
            });
    }

    public function down(): void
    {
        $role = Role::query()->where('slug', 'secretary')->first();
        if (! $role) {
            return;
        }

        $role->permissions()->detach();
        $role->offices()->detach();
        $role->delete();
    }
};
