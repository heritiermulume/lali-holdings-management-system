<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['slug' => 'kams.stock.assignments.delete'],
            [
                'name' => 'Delete stock assignments',
                'group' => 'kams.stock',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'kams.stock.assignments.delete')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
