<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'member_id', 'kind', 'balance'])]
class KamsSavingsAccount extends Model
{
    protected function casts(): array
    {
        return ['balance' => 'float'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(KamsSavingsEntry::class, 'account_id')->latest();
    }
}
