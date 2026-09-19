<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'code', 'role_slug', 'label', 'percent', 'divisor', 'basis', 'scope',
    'paid_from', 'sort_order', 'is_active', 'effective_from', 'effective_to', 'created_by', 'updated_by',
])]
class KamsRevenueRule extends Model
{
    protected function casts(): array
    {
        return [
            'percent' => 'float',
            'divisor' => 'float',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(KamsRevenueRuleHistory::class, 'rule_id')->latest();
    }
}
