<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('day_closed_by')->constrained('users')->nullOnDelete();
            $table->foreignId('added_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
