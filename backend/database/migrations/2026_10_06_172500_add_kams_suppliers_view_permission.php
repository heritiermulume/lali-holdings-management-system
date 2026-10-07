<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['slug' => 'kams.suppliers.view'],
            [
                'name' => 'View suppliers',
                'group' => 'kams.clients',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $targetId = DB::table('permissions')->where('slug', 'kams.suppliers.view')->value('id');
        $sourceId = DB::table('permissions')->where('slug', 'kams.clients.view')->value('id');
        if ($targetId && $sourceId) {
            foreach (DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id') as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $targetId],
                    []
                );
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'kams.suppliers.view')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
