<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'sale_id', 'member_id', 'status',
    'estimated_amount', 'adjusted_amount', 'payable_amount', 'paid_amount',
])]
class KamsSellerCommission extends Model
{
    protected function casts(): array
    {
        return [
            'estimated_amount' => 'float',
            'adjusted_amount' => 'float',
            'payable_amount' => 'float',
            'paid_amount' => 'float',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(KamsStockSale::class, 'sale_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }
}
