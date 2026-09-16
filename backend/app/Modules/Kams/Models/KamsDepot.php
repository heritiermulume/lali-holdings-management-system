<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use App\Models\Province;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'office_id', 'member_id', 'manager_user_id', 'kind', 'province_id', 'name', 'code', 'status', 'notes'])]
class KamsDepot extends Model
{
    use SoftDeletes;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(KamsStockBalance::class, 'depot_id');
    }

    public function scopeWarehouses(Builder $query): Builder
    {
        return $query->where('kind', 'warehouse');
    }

    public function scopeOfficeDepots(Builder $query): Builder
    {
        return $query->where('kind', 'depot');
    }

    public function scopeSellers(Builder $query): Builder
    {
        return $query->where('kind', 'seller');
    }

    public function isWarehouse(): bool
    {
        return $this->kind === 'warehouse';
    }

    public function isSeller(): bool
    {
        return $this->kind === 'seller';
    }
}
