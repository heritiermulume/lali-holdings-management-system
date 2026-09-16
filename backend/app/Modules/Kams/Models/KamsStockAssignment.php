<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'reference', 'office_id', 'depot_id', 'member_id', 'status',
    'assigned_at', 'settled_at', 'expected_amount', 'collected_amount', 'profit_amount', 'loss_amount',
    'notes', 'created_by',
])]
class KamsStockAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'settled_at' => 'datetime',
            'expected_amount' => 'float',
            'collected_amount' => 'float',
            'profit_amount' => 'float',
            'loss_amount' => 'float',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'depot_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KamsStockAssignmentItem::class, 'assignment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
