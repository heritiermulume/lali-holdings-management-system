<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'sale_id', 'sale_item_id', 'product_id', 'office_id', 'rule_id', 'rule_code',
    'role_slug', 'beneficiary_user_id', 'beneficiary_member_id', 'base_amount', 'percent', 'divisor',
    'amount', 'formula', 'status', 'parent_share_id', 'snapshot', 'posted_at', 'paid_at', 'transfer_entry_id',
])]
class KamsRevenueShare extends Model
{
    protected function casts(): array
    {
        return [
            'base_amount' => 'float',
            'percent' => 'float',
            'divisor' => 'float',
            'amount' => 'float',
            'snapshot' => 'array',
            'posted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(KamsStockSale::class, 'sale_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(KamsStockSaleItem::class, 'sale_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(KamsRevenueRule::class, 'rule_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'beneficiary_member_id');
    }

    public function beneficiaryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function parentShare(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_share_id');
    }

    public function transferEntry(): BelongsTo
    {
        return $this->belongsTo(KamsTreasuryEntry::class, 'transfer_entry_id');
    }
}
