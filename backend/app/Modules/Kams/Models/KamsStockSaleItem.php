<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id', 'product_id', 'quantity', 'unit_price', 'unit_cost',
    'field_price', 'manager_price', 'company_price', 'seller_margin', 'manager_margin',
])]
class KamsStockSaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'float',
            'unit_cost' => 'float',
            'field_price' => 'float',
            'manager_price' => 'float',
            'company_price' => 'float',
            'seller_margin' => 'float',
            'manager_margin' => 'float',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(KamsStockSale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
