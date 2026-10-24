<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('settled_at');
        });

        DB::table('kams_stock_assignments')
            ->whereIn('status', ['closed', 'settled'])
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('COALESCE(settled_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
