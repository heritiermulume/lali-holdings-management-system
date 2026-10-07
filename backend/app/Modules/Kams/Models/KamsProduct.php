<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'sku', 'name', 'category', 'collection', 'price', 'field_price', 'manager_price', 'company_price', 'cost', 'avg_cost', 'seller_share_percent', 'manager_share_percent', 'stock', 'status', 'image_path'])]
class KamsProduct extends Model
{
    use SoftDeletes;

    protected $appends = ['image_url'];

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
            'manager_share_percent' => 'float',
            'stock' => 'integer',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path ? '/storage/'.$this->image_path : null);
    }

    /** Catalog sale price (prix de vente). */
    public function salePrice(): float
    {
        $value = (float) $this->field_price;

        return $value > 0 ? $value : (float) $this->price;
    }

    /** Catalog purchase price (prix d'achat). */
    public function purchasePrice(): float
    {
        $value = (float) $this->company_price;
        if ($value > 0) {
            return $value;
        }

        return max(0, (float) $this->cost);
    }

    /** @deprecated Use salePrice() */
    public function fieldPrice(): float
    {
        return $this->salePrice();
    }

    /** @deprecated Use purchasePrice() */
    public function companyPrice(): float
    {
        return $this->purchasePrice();
    }

    /** @deprecated Intermediate manager price removed — kept for legacy rows. */
    public function managerPrice(): float
    {
        return $this->purchasePrice();
    }

    public function profitUnit(): float
    {
        return max($this->salePrice() - $this->purchasePrice(), 0);
    }

    /** @deprecated Price-ladder seller margin removed. */
    public function sellerMarginUnit(): float
    {
        return 0.0;
    }

    /** @deprecated Price-ladder manager margin removed. */
    public function managerMarginUnit(): float
    {
        return 0.0;
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
