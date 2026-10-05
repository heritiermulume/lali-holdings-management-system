<?php

namespace App\Core\Support;

use App\Models\Country;
use App\Models\Currency;
use App\Support\CurrencyCatalog;

class CurrencySync
{
    public static function ensureFromCountries(): void
    {
        $codes = Country::query()
            ->where('is_active', true)
            ->whereNotNull('currency_code')
            ->pluck('currency_code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->filter()
            ->unique()
            ->values();

        $order = (int) Currency::query()->max('sort_order');

        foreach ($codes as $code) {
            $existing = Currency::query()->where('code', $code)->first();
            if ($existing) {
                $existing->is_active = true;
                $existing->save();
                continue;
            }

            $meta = CurrencyCatalog::find($code);
            Currency::query()->create([
                'code' => $code,
                'name' => $meta['name'],
                'name_fr' => $meta['name_fr'],
                'symbol' => $meta['symbol'],
                'is_active' => true,
                'is_default' => Currency::query()->doesntExist(),
                'sort_order' => ++$order,
            ]);
        }

        if ($codes->isNotEmpty()) {
            Currency::query()
                ->whereNotIn('code', $codes->all())
                ->where('is_default', false)
                ->update(['is_active' => false]);
        }

        if (Currency::query()->where('is_default', true)->doesntExist()) {
            $first = Currency::query()->whereIn('code', $codes->all())->orderBy('code')->first()
                ?? Currency::query()->first();
            if ($first) {
                $first->is_default = true;
                $first->is_active = true;
                $first->save();
            }
        }
    }
}
