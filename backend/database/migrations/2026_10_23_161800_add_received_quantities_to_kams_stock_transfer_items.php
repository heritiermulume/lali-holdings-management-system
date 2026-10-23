<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_stock_transfer_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_received')->nullable()->after('quantity');
            $table->unsignedInteger('quantity_lost')->nullable()->after('quantity_received');
        });
    }

    public function down(): void
    {
        Schema::table('kams_stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn(['quantity_received', 'quantity_lost']);
        });
    }
};
