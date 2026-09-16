<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'account_id', 'member_id', 'kind', 'type', 'amount',
    'source_type', 'source_id', 'occurred_on', 'notes', 'created_by',
])]
class KamsSavingsEntry extends Model
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
}
