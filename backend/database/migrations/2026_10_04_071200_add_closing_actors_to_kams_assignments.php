<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('day_closed_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('day_closed_by');
            $table->dropConstrainedForeignId('closed_by');
        });
    }
};
