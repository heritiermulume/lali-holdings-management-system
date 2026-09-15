<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_observation_days', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('decision');
        });
    }

    public function down(): void
    {
        Schema::table('kams_observation_days', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
