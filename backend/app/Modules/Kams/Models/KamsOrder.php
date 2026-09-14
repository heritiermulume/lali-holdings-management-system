<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'client_id', 'reference', 'status',
    'subtotal', 'tax', 'total', 'ordered_at', 'notes',
])]
class KamsOrder extends Model
{
    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'ordered_at' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(KamsClient::class, 'client_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KamsOrderItem::class, 'order_id');
    }
}
