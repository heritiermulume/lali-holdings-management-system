<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'depot_id', 'product_id', 'condition', 'quantity'])]
class KamsStockBalance extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'depot_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
