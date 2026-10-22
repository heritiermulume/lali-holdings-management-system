<?php

namespace App\Modules\Kams\Services;

use App\Models\Currency;
use App\Models\Office;

class KamsDisplayCurrency
{
    /**
     * Product prices are stored in the finance default currency.
     * A single office displays that country's currency, converted with the
     * rate configured on the country (units of local currency per 1 default unit).
     * Several offices in the same view stay in the default currency.
     *
     * @param  array{office_ids?: list<int>, selected_office_id?: int|null, offices?: \Illuminate\Support\Collection}  $state
     * @return array{currency: string, source_currency: string, money_rate: float}
     */
    public function forState(array $state, ?int $companyId = null): array
    {
        $officeIds = array_values(array_map('intval', $state['office_ids'] ?? []));
        $selected = isset($state['selected_office_id']) ? (int) $state['selected_office_id'] : null;
        $single = $selected || count($officeIds) === 1;
        $officeId = $selected ?: ($officeIds[0] ?? null);

        $source = Currency::defaultCode() ?: '';
        $display = $source;
        $rate = 1.0;

        if ($single && $officeId) {
            $country = Office::query()
                ->whereKey($officeId)
                ->with('country:id,currency_code,fx_rate')
                ->first()
                ?->country;
            $countryCode = is_string($country?->currency_code) ? strtoupper($country->currency_code) : '';
            if ($countryCode !== '') {
                $display = $countryCode;
            }
            if ($source !== '' && $display !== '' && $source !== $display) {
                $configured = (float) ($country?->fx_rate ?? 0);
                $rate = $configured > 0 ? $configured : 1.0;
            }
        }

        return [
            'currency' => $display !== '' ? $display : $source,
            'source_currency' => $source !== '' ? $source : $display,
            'money_rate' => $rate,
        ];
    }
}
