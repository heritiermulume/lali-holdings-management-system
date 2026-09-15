<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'member_id', 'category', 'target_module', 'amount',
    'currency_code', 'is_opening_balance', 'verified', 'notes',
])]
class KamsMemberOpeningPosition extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_opening_balance' => 'boolean',
            'verified' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }
}
