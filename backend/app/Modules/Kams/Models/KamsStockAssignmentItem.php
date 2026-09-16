<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'assignment_id', 'product_id', 'quantity_out', 'quantity_returned', 'quantity_lost', 'unit_price', 'unit_cost',
])]
class KamsStockAssignmentItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_out' => 'integer',
            'quantity_returned' => 'integer',
            'quantity_lost' => 'integer',
            'unit_price' => 'float',
            'unit_cost' => 'float',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(KamsStockAssignment::class, 'assignment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }

    public function quantitySold(): int
    {
        return max(0, $this->quantity_out - $this->quantity_returned - $this->quantity_lost);
    }
}
