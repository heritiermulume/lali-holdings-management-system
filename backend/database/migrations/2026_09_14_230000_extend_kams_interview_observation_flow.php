<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_interviews', function (Blueprint $table) {
            $table->dateTime('observation_starts_at')->nullable()->after('interview_date');
            $table->dateTime('observation_ends_at')->nullable()->after('observation_starts_at');
            $table->text('abandonment_reason')->nullable()->after('notes');
        });

        Schema::table('kams_observation_days', function (Blueprint $table) {
            $table->foreignId('supervisor_member_id')->nullable()->after('supervisor_name')->constrained('kams_members')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE kams_interviews MODIFY result VARCHAR(32) NOT NULL DEFAULT 'on_hold'");
            DB::statement("ALTER TABLE kams_candidates MODIFY status VARCHAR(32) NOT NULL DEFAULT 'received'");
        }
    }

    public function down(): void
    {
        Schema::table('kams_observation_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_member_id');
        });

        Schema::table('kams_interviews', function (Blueprint $table) {
            $table->dropColumn(['observation_starts_at', 'observation_ends_at', 'abandonment_reason']);
        });
    }
};
