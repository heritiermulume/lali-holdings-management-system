<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['return_id', 'product_id', 'quantity', 'unit_cost', 'unit_price'])]
class KamsStockReturnItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'float',
            'unit_price' => 'float',
        ];
    }

    public function stockReturn(): BelongsTo
    {
        return $this->belongsTo(KamsStockReturn::class, 'return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
