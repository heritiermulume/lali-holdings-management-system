<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'account_id', 'office_id', 'member_id', 'related_entry_id', 'type', 'status', 'amount',
    'occurred_on', 'notes', 'decision_notes', 'created_by', 'requested_by', 'performed_by',
])]
class KamsTreasuryEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(KamsTreasuryAccount::class, 'account_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
