<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->boolean('deposit_surplus')->default(false)->after('loss_amount');
        });
    }

    public function down(): void
    {
        Schema::table('kams_stock_assignments', function (Blueprint $table) {
            $table->dropColumn('deposit_surplus');
        });
    }
};
