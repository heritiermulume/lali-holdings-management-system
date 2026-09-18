<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'office_id', 'code', 'name', 'balance'])]
class KamsTreasuryAccount extends Model
{
    protected function casts(): array
    {
        return ['balance' => 'float'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(KamsTreasuryEntry::class, 'account_id')->latest();
    }

    public function isCentral(): bool
    {
        return $this->code === 'CENTRAL' || $this->office_id === null;
    }
}
