<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'reference', 'type', 'status', 'sale_id', 'member_id',
    'from_depot_id', 'to_depot_id', 'reason', 'condition', 'notes', 'created_by', 'validated_by', 'validated_at',
])]
class KamsStockReturn extends Model
{
    protected function casts(): array
    {
        return ['validated_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(KamsStockSale::class, 'sale_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function fromDepot(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'from_depot_id');
    }

    public function toDepot(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'to_depot_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KamsStockReturnItem::class, 'return_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
