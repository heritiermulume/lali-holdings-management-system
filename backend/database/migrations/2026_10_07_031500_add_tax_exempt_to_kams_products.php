<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_products', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_products', 'tax_exempt')) {
                $table->boolean('tax_exempt')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kams_products', function (Blueprint $table) {
            if (Schema::hasColumn('kams_products', 'tax_exempt')) {
                $table->dropColumn('tax_exempt');
            }
        });
    }
};
