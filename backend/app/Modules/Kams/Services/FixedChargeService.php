<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsFixedCharge;
use Illuminate\Support\Collection;

class FixedChargeService
{
    /**
     * Sum of the recorded charge amounts. Dates do not change this total.
     *
     * @param  list<int>  $officeIds
     */
    public function total(?int $companyId, array $officeIds, ?string $from = null, ?string $to = null): float
    {
        return round((float) KamsFixedCharge::query()
            ->where('is_active', true)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereIn('office_id', $officeIds))
            ->sum('amount'), 2);
    }

    /**
     * @param  Collection<int, KamsFixedCharge>  $charges
     */
    public function sum(Collection $charges, ?string $from = null, ?string $to = null): float
    {
        return round((float) $charges->sum('amount'), 2);
    }
}
