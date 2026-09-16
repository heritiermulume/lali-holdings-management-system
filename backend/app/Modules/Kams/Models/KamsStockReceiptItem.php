<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_id', 'product_id', 'quantity', 'unit_cost'])]
class KamsStockReceiptItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost' => 'float'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(KamsStockReceipt::class, 'receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
