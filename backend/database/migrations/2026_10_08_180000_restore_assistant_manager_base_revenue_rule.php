<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures the base assistant_manager rule stays on settings defaults (divisor 4).
 * Certified AM surplus (1/8 of sub-AM teams) is computed in RevenueShareService.
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
        // Intentionally empty — certified surplus must not live on this rule row.
    }
};
