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
    'assigned_at', 'settled_at', 'closed_at', 'expected_amount', 'collected_amount', 'profit_amount', 'loss_amount',
    'deposit_surplus', 'notes', 'created_by', 'closed_by', 'day_closed_by', 'updated_by', 'added_by',
])]
class KamsStockAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'settled_at' => 'datetime',
            'closed_at' => 'datetime',
            'expected_amount' => 'float',
            'collected_amount' => 'float',
            'profit_amount' => 'float',
            'loss_amount' => 'float',
            'deposit_surplus' => 'boolean',
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function dayClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'day_closed_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
