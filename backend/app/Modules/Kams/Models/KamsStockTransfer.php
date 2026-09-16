<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'reference', 'from_depot_id', 'to_depot_id', 'status', 'notes',
    'created_by', 'dispatched_by', 'transferred_at', 'received_at', 'received_by',
])]
class KamsStockTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
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
        return $this->hasMany(KamsStockTransferItem::class, 'transfer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
