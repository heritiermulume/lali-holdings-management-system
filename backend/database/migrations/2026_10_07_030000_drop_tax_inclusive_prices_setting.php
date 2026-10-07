<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'finance')
            ->where('key', 'tax_inclusive_prices')
            ->delete();
    }

    public function down(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'finance', 'key' => 'tax_inclusive_prices'],
            ['value' => '0', 'type' => 'boolean', 'updated_at' => now(), 'created_at' => now()],
        );
    }
};
