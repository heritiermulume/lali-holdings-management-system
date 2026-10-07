<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('kams_products', 'description')) {
            Schema::table('kams_products', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kams_products', 'description')) {
            Schema::table('kams_products', function (Blueprint $table) {
                $table->text('description')->nullable()->after('status');
            });
        }
    }
};
