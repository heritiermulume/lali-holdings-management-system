<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->foreignId('guardian_member_id')->nullable()->after('member_id')->constrained('kams_members')->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE kams_candidates MODIFY source VARCHAR(32) NOT NULL DEFAULT 'online_form'");
        }
    }

    public function down(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_member_id');
        });
    }
};
