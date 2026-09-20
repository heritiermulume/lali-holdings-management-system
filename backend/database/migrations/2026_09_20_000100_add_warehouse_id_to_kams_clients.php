<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('kind')
                ->constrained('kams_depots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
