<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_order_id', 'product_id', 'quantity', 'received_quantity', 'unit_cost', 'line_total',
])]
class KamsPurchaseOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'received_quantity' => 'integer',
            'unit_cost' => 'float',
            'line_total' => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(KamsPurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
