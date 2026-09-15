<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('tax_name', 64)->default('TVA')->after('currency_symbol');
            $table->decimal('tax_rate', 6, 2)->default(0)->after('tax_name');
        });

        DB::table('countries')->where('iso_code', 'CD')->update(['tax_name' => 'TVA', 'tax_rate' => 16]);
        DB::table('countries')->where('iso_code', 'TZ')->update(['tax_name' => 'VAT', 'tax_rate' => 18]);
        DB::table('countries')->where('iso_code', 'CG')->update(['tax_name' => 'TVA', 'tax_rate' => 18.90]);
        DB::table('countries')->where('iso_code', 'RW')->update(['tax_name' => 'VAT', 'tax_rate' => 18]);
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['tax_name', 'tax_rate']);
        });
    }
};
