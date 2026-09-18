<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_savings_entries', 'decision_notes')) {
                $table->text('decision_notes')->nullable()->after('notes');
            }
        });

        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_treasury_entries', 'decision_notes')) {
                $table->text('decision_notes')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            if (Schema::hasColumn('kams_savings_entries', 'decision_notes')) {
                $table->dropColumn('decision_notes');
            }
        });

        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (Schema::hasColumn('kams_treasury_entries', 'decision_notes')) {
                $table->dropColumn('decision_notes');
            }
        });
    }
};
