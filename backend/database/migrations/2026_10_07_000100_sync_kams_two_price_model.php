<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Collapse the old three-price ladder to purchase + sale.
        // sale = field_price/price, purchase = company_price/cost, manager_price unused (= purchase).
        DB::table('kams_products')->orderBy('id')->chunkById(100, function ($products) {
            foreach ($products as $product) {
                $sale = (float) (($product->field_price ?: null) ?: $product->price);
                $purchase = (float) (($product->company_price ?: null) ?: $product->cost);
                if ($purchase <= 0 && $sale > 0) {
                    $purchase = $sale;
                }
                DB::table('kams_products')->where('id', $product->id)->update([
                    'price' => $sale,
                    'field_price' => $sale,
                    'cost' => $purchase,
                    'company_price' => $purchase,
                    'manager_price' => $purchase,
                    'avg_cost' => (float) ($product->avg_cost ?: $purchase),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible data normalization.
    }
};
