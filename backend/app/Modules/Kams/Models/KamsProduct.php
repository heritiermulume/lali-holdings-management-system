<?php

namespace App\Modules\Kams\Models;

use App\Core\Support\TaxSettings;
use App\Models\Company;
use App\Modules\Kams\Services\PeoplePhotoStorage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'sku', 'name', 'category', 'collection', 'price', 'field_price', 'manager_price',
    'company_price', 'cost', 'avg_cost', 'seller_share_percent', 'manager_share_percent', 'stock',
    'status', 'tax_exempt', 'image_path',
])]
class KamsProduct extends Model
{
    use SoftDeletes;

    protected $appends = ['image_url', 'sale_price', 'base_sale_price'];

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
            'tax_exempt' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => app(PeoplePhotoStorage::class)->url($this->image_path));
    }

    /** Catalog base sale price as entered (before country tax when not exempt). */
    public function baseSalePrice(): float
    {
        $value = (float) $this->field_price;

        return $value > 0 ? $value : (float) $this->price;
    }

    /** Catalog sale price for an office, or the product default when no override exists. */
    public function baseSalePriceForOffice(?int $officeId): float
    {
        if ($officeId) {
            if (! $this->relationLoaded('officePrices')) {
                $this->load('officePrices');
            }
            $override = $this->officePrices->first(fn ($row) => (int) $row->office_id === $officeId);
            if ($override && (float) $override->sale_price > 0) {
                return (float) $override->sale_price;
            }
        }

        return $this->baseSalePrice();
    }

    /**
     * Price charged for an office: the depot sale price when one is set, otherwise the catalog sale price.
     * Tax is not added again on top of a bureau price. Without an office, the catalog price includes country tax.
     */
    public function salePrice(?int $officeId = null): float
    {
        if ($officeId) {
            return $this->baseSalePriceForOffice($officeId);
        }

        $base = $this->baseSalePrice();
        if ($base <= 0 || $this->tax_exempt) {
            return $base;
        }

        $this->loadMissing('company.country');

        return TaxSettings::apply($base, $this->company?->country)['total'];
    }

    public function getSalePriceAttribute(): float
    {
        return $this->salePrice();
    }

    public function getBaseSalePriceAttribute(): float
    {
        return $this->baseSalePrice();
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

    /**
     * Inventory / COGS unit cost: weighted average first, then catalog purchase.
     * Keep in sync with StockInventory::unitCost().
     */
    public function inventoryUnitCost(): float
    {
        $avg = (float) $this->avg_cost;
        if ($avg > 0) {
            return $avg;
        }

        return $this->purchasePrice();
    }

    /** SQL fragment for COGS / purchase unit cost (not stock display value). */
    public static function inventoryUnitCostSql(string $alias = 'kams_products'): string
    {
        return "COALESCE(NULLIF({$alias}.avg_cost, 0), NULLIF({$alias}.cost, 0), NULLIF({$alias}.company_price, 0), 0)";
    }

    /**
     * SQL unit sale price for stock value (qty × prix de vente, tax included when not exempt).
     * Expects left joins on companies + countries (see sumStockSaleValue).
     */
    public static function inventoryUnitSalePriceSql(
        string $productAlias = 'kams_products',
        string $countryAlias = 'countries',
    ): string {
        $catalog = "COALESCE(NULLIF({$productAlias}.field_price, 0), NULLIF({$productAlias}.price, 0), 0)";
        $taxedCatalog = "({$catalog} * CASE WHEN COALESCE({$productAlias}.tax_exempt, 0) = 1 THEN 1 ELSE (1 + COALESCE({$countryAlias}.tax_rate, 0) / 100) END)";
        $officePrice = "COALESCE(NULLIF(kams_product_office_prices.sale_price, 0), {$catalog})";

        return "CASE WHEN kams_depots.kind IN ('depot', 'seller') THEN {$officePrice} ELSE {$taxedCatalog} END";
    }

    /**
     * Stock value = quantity × sale price. Caller scopes balances (company, offices, warehouses, condition).
     *
     * @param  Builder<KamsStockBalance>  $balancesQuery
     */
    public static function sumStockSaleValue(Builder $balancesQuery): float
    {
        $table = (new KamsStockBalance)->getTable();
        $value = (clone $balancesQuery)
            ->join('kams_products', 'kams_products.id', '=', "{$table}.product_id")
            ->join('kams_depots', 'kams_depots.id', '=', "{$table}.depot_id")
            ->leftJoin('kams_product_office_prices', function ($join) use ($table) {
                $join->on('kams_product_office_prices.product_id', '=', "{$table}.product_id")
                    ->on('kams_product_office_prices.office_id', '=', 'kams_depots.office_id');
            })
            ->leftJoin('companies', 'companies.id', '=', 'kams_products.company_id')
            ->leftJoin('countries', 'countries.id', '=', 'companies.country_id')
            ->selectRaw('COALESCE(SUM('.$table.'.quantity * '.self::inventoryUnitSalePriceSql().'), 0) as value')
            ->value('value');

        return round((float) $value, 2);
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

    public function officePrices(): HasMany
    {
        return $this->hasMany(KamsProductOfficePrice::class, 'product_id');
    }
}
