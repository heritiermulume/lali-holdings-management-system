<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'depot_id', 'product_id', 'condition', 'quantity'])]
class KamsStockBalance extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(KamsDepot::class, 'depot_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.condition', 'available');
    }

    /**
     * Limit to office depots/sellers in scope, and always include central warehouses.
     *
     * @param  list<int>  $officeIds
     */
    public function scopeForOfficesOrWarehouses(Builder $query, array $officeIds): Builder
    {
        if ($officeIds === []) {
            return $query;
        }

        return $query->whereHas('depot', function (Builder $depot) use ($officeIds) {
            $depot->where(function (Builder $inner) use ($officeIds) {
                $inner->whereIn('office_id', $officeIds)
                    ->orWhere('kind', 'warehouse');
            });
        });
    }
}
