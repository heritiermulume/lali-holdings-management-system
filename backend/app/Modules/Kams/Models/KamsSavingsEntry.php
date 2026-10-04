<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'company_id', 'account_id', 'member_id', 'kind', 'type', 'status', 'amount', 'balance_after',
    'source_type', 'source_id', 'occurred_on', 'notes', 'decision_notes', 'created_by', 'requested_by', 'performed_by',
])]
class KamsSavingsEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(KamsSavingsAccount::class, 'account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
