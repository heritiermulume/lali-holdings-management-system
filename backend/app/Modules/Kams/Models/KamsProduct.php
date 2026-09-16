<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'sku', 'name', 'category', 'collection', 'price', 'field_price', 'manager_price', 'company_price', 'cost', 'avg_cost', 'seller_share_percent', 'stock', 'status', 'description'])]
class KamsProduct extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'field_price' => 'float',
            'manager_price' => 'float',
            'company_price' => 'float',
            'cost' => 'float',
            'avg_cost' => 'float',
            'seller_share_percent' => 'float',
            'stock' => 'integer',
        ];
    }

    public function fieldPrice(): float
    {
        $value = (float) $this->field_price;

        return $value > 0 ? $value : (float) $this->price;
    }

    public function companyPrice(): float
    {
        $value = (float) $this->company_price;
        if ($value > 0) {
            return $value;
        }
        $cost = (float) $this->cost;

        return $cost > 0 ? $cost : round($this->fieldPrice() * 0.6, 2);
    }

    public function managerPrice(): float
    {
        $value = (float) $this->manager_price;
        if ($value > 0) {
            return $value;
        }

        return round(($this->fieldPrice() + $this->companyPrice()) / 2, 2);
    }

    public function sellerMarginUnit(): float
    {
        return max($this->fieldPrice() - $this->managerPrice(), 0);
    }

    public function managerMarginUnit(): float
    {
        return max($this->managerPrice() - $this->companyPrice(), 0);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(KamsStockBalance::class, 'product_id');
    }
}
