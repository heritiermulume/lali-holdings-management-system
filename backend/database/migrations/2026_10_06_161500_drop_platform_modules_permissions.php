<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['modules.view', 'modules.manage'])
            ->orWhere('group', 'modules')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    public function down(): void
    {
        foreach ([
            ['slug' => 'modules.view', 'group' => 'modules', 'name' => 'View modules'],
            ['slug' => 'modules.manage', 'group' => 'modules', 'name' => 'Activate and configure modules'],
        ] as $permission) {
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
    }
};
