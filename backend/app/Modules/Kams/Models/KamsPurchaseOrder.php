<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'warehouse_id', 'supplier_id', 'supplier_name', 'reference',
    'status', 'ordered_at', 'total', 'notes', 'created_by',
])]
class KamsPurchaseOrder extends Model
{
    protected function casts(): array
    {
        return [
            'ordered_at' => 'date',
            'total' => 'float',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'warehouse_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(KamsClient::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KamsPurchaseOrderItem::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
