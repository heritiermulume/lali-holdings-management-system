<?php

use App\Core\Support\RoleCatalog;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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

        $presidentId = Role::query()->where('slug', 'president')->value('id');
        $partnerId = Role::query()->where('slug', 'partner')->value('id');

        if ($presidentId && $partnerId) {
            $platformPartnerUsers = DB::table('role_user')
                ->where('role_id', $partnerId)
                ->whereNull('company_id')
                ->pluck('user_id');

            foreach ($platformPartnerUsers as $userId) {
                $hasPresident = DB::table('role_user')
                    ->where('user_id', $userId)
                    ->where('role_id', $presidentId)
                    ->exists();

                if ($hasPresident) {
                    DB::table('role_user')
                        ->where('user_id', $userId)
                        ->where('role_id', $partnerId)
                        ->whereNull('company_id')
                        ->delete();
                } else {
                    DB::table('role_user')
                        ->where('user_id', $userId)
                        ->where('role_id', $partnerId)
                        ->whereNull('company_id')
                        ->update(['role_id' => $presidentId]);
                }
            }
        }

        Role::query()->whereIn('slug', RoleCatalog::fullAccessSlugs())->each(function (Role $role) {
            $role->offices()->detach();
            DB::table('role_user')->where('role_id', $role->id)->update(['company_id' => null]);
        });

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
    }

    public function down(): void
    {
        $role = Role::query()->where('slug', 'president')->first();
        if (! $role) {
            return;
        }

        $partner = Role::query()->where('slug', 'partner')->first();
        if ($partner) {
            $userIds = DB::table('role_user')->where('role_id', $role->id)->pluck('user_id');
            foreach ($userIds as $userId) {
                $exists = DB::table('role_user')->where('user_id', $userId)->where('role_id', $partner->id)->exists();
                if ($exists) {
                    DB::table('role_user')->where('user_id', $userId)->where('role_id', $role->id)->delete();
                } else {
                    DB::table('role_user')->where('user_id', $userId)->where('role_id', $role->id)->update([
                        'role_id' => $partner->id,
                        'company_id' => null,
                    ]);
                }
            }
        }

        $role->permissions()->detach();
        $role->offices()->detach();
        $role->delete();
    }
};
