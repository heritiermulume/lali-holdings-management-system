<?php

use App\Core\Support\PermissionCatalog;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::forKams() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );
        }
    }

    public function down(): void
    {
        Permission::query()
            ->where('slug', 'like', 'kams.finance.%.%')
            ->get()
            ->each(function (Permission $permission) {
                $parts = explode('.', $permission->slug);
                if (count($parts) < 4) {
                    return;
                }
                $permission->forceFill([
                    'group' => $parts[0].'.'.$parts[1].'.'.$parts[2],
                ])->save();
            });
    }
};
