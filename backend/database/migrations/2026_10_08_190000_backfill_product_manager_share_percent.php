<?php

use App\Modules\Kams\Support\RevenueRuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kams_products', 'manager_share_percent')) {
            return;
        }

        $rulePercents = DB::table('kams_revenue_rules')
            ->where('code', RevenueRuleCatalog::MANAGER_SALE)
            ->pluck('percent', 'company_id');

        DB::table('kams_products')
            ->whereNull('manager_share_percent')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($rulePercents) {
                foreach ($products as $product) {
                    $percent = (float) ($rulePercents[$product->company_id] ?? 20);
                    if ($percent <= 0) {
                        $percent = 20;
                    }
                    DB::table('kams_products')
                        ->where('id', $product->id)
                        ->update(['manager_share_percent' => $percent]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally left empty: product-level percents should not be wiped.
    }
};
