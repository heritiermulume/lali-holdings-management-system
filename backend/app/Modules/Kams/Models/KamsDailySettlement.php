<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'office_id', 'settled_on', 'status',
    'field_total', 'seller_savings', 'manager_savings', 'company_share',
    'posted_by', 'posted_at',
])]
class KamsDailySettlement extends Model
{
    protected function casts(): array
    {
        return [
            'settled_on' => 'date:Y-m-d',
            'field_total' => 'float',
            'seller_savings' => 'float',
            'manager_savings' => 'float',
            'company_share' => 'float',
            'posted_at' => 'datetime',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
