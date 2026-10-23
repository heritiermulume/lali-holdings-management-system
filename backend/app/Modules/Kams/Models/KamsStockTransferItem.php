<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transfer_id', 'product_id', 'quantity', 'quantity_received', 'quantity_lost', 'unit_cost'])]
class KamsStockTransferItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_received' => 'integer',
            'quantity_lost' => 'integer',
            'unit_cost' => 'float',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(KamsStockTransfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
