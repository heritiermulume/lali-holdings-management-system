<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'reference', 'warehouse_id', 'supplier_name', 'status', 'total_cost',
    'notes', 'document_path', 'created_by', 'validated_by', 'validated_at',
])]
class KamsStockReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'total_cost' => 'float',
            'validated_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KamsStockReceiptItem::class, 'receipt_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
