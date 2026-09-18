<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_savings_entries', 'status')) {
                $table->string('status', 20)->default('posted')->after('type');
                $table->index(['account_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
