<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id', 'reference', 'member_id', 'assignment_id', 'manager_member_id', 'depot_id', 'client_id', 'client_name',
    'payment_status', 'subtotal', 'field_total', 'manager_total', 'company_total', 'seller_margin', 'manager_margin',
    'cost_total', 'margin', 'paid_amount', 'notes', 'created_by', 'sold_at',
])]
class KamsStockSale extends Model
{
    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'field_total' => 'float',
            'manager_total' => 'float',
            'company_total' => 'float',
            'seller_margin' => 'float',
            'manager_margin' => 'float',
            'cost_total' => 'float',
            'margin' => 'float',
            'paid_amount' => 'float',
            'sold_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(KamsStockAssignment::class, 'assignment_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'manager_member_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(KamsClient::class, 'client_id');
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'depot_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KamsStockSaleItem::class, 'sale_id');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(KamsSellerCommission::class, 'sale_id');
    }

    public function revenueShares(): HasMany
    {
        return $this->hasMany(KamsRevenueShare::class, 'sale_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
