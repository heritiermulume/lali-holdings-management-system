<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_observation_days', function (Blueprint $table) {
            $table->foreignId('sponsor_member_id')->nullable()->after('supervisor_member_id')->constrained('kams_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_observation_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_member_id');
        });
    }
};
