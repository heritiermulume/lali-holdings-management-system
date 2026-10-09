<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kams_stock_sales', 'assignment_id')) {
            Schema::table('kams_stock_sales', function (Blueprint $table) {
                $table->foreignId('assignment_id')
                    ->nullable()
                    ->after('member_id')
                    ->constrained('kams_stock_assignments')
                    ->nullOnDelete();
            });
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => 'kams.stock.reports.delete'],
            [
                'name' => 'Delete stock reports',
                'group' => 'kams.stock',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'kams.stock.reports.delete')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        if (Schema::hasColumn('kams_stock_sales', 'assignment_id')) {
            Schema::table('kams_stock_sales', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assignment_id');
            });
        }
    }
};
