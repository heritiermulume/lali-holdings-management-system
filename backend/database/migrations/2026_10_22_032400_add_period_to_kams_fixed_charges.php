<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_fixed_charges', function (Blueprint $table) {
            $table->string('period')->default('month')->after('amount');
            $table->date('starts_on')->nullable()->after('period');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        DB::table('kams_fixed_charges')
            ->whereNull('starts_on')
            ->update(['starts_on' => now()->startOfYear()->toDateString(), 'period' => 'month']);
    }

    public function down(): void
    {
        Schema::table('kams_fixed_charges', function (Blueprint $table) {
            $table->dropColumn(['period', 'starts_on', 'ends_on']);
        });
    }
};
