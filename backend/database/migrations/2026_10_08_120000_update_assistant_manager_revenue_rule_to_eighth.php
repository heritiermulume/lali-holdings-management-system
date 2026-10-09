<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the base assistant_manager rule on divisor 4.
 * Certified AM surplus (1/8 of sub-AM team production) is handled in RevenueShareService, not this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kams_revenue_rules')) {
            return;
        }

        DB::table('kams_revenue_rules')
            ->where('code', 'assistant_manager')
            ->update([
                'label' => 'Assistant manager team share',
                'percent' => 20,
                'divisor' => 4,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No-op.
    }
};
