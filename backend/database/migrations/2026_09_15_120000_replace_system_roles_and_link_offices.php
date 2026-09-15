<?php

use App\Core\Support\RoleCatalog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_role')) {
            Schema::create('office_role', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['office_id', 'role_id']);
            });
        }

        foreach (RoleCatalog::all() as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['name'],
                    'level' => $role['level'],
                    'is_system' => true,
                ]
            );
        }

        $permissionIds = Permission::query()->pluck('id');
        foreach (RoleCatalog::fullAccessSlugs() as $slug) {
            Role::query()->where('slug', $slug)->first()?->permissions()->sync($permissionIds);
        }

        $ids = Role::query()->pluck('id', 'slug');
        foreach (RoleCatalog::legacySlugMap() as $oldSlug => $newSlug) {
            $oldId = $ids[$oldSlug] ?? null;
            $newId = $ids[$newSlug] ?? null;
            if (! $oldId || ! $newId || $oldId === $newId) {
                continue;
            }

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

            $newRole = Role::query()->find($newId);
            $oldRole = Role::query()->find($oldId);
            if ($newRole && $oldRole && $newRole->permissions()->count() === 0 && ! $newRole->hasFullAccess()) {
                $newRole->permissions()->sync($oldRole->permissions()->pluck('permissions.id'));
            }

            Role::query()->whereKey($oldId)->each(function (Role $role) {
                $role->permissions()->detach();
                $role->delete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('office_role');
    }
};
