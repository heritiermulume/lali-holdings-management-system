<?php

namespace App\Core\Support;

use App\Models\Country;
use App\Models\Setting;

class TaxSettings
{
    public static function name(?Country $country = null): string
    {
        if ($country?->tax_name) {
            return (string) $country->tax_name;
        }

        return (string) Setting::value('finance', 'tax_name', 'TVA');
    }

    public static function rate(?Country $country = null): float
    {
        if ($country && $country->tax_rate !== null) {
            return max(0, (float) $country->tax_rate);
        }

        $rate = Setting::value('finance', 'default_tax_rate', null);
        if ($rate === null) {
            $rate = Setting::value('kams', 'default_tax_rate', 16);
        }

        return max(0, (float) $rate);
    }

    public static function inclusive(): bool
    {
        return (bool) Setting::value('finance', 'tax_inclusive_prices', false);
    }

    /**
     * @return array{subtotal: float, tax: float, total: float, rate: float, inclusive: bool, name: string}
     */
    public static function apply(float $amount, Country|int|null $country = null): array
    {
        $model = $country instanceof Country
            ? $country
            : ($country ? Country::query()->find($country) : null);

        $rate = self::rate($model);
        $inclusive = self::inclusive();
        $factor = $rate / 100;

        if ($inclusive) {
            $total = round($amount, 2);
            $subtotal = $factor > 0 ? round($total / (1 + $factor), 2) : $total;
            $tax = round($total - $subtotal, 2);
        } else {
            $subtotal = round($amount, 2);
            $tax = round($subtotal * $factor, 2);
            $total = round($subtotal + $tax, 2);
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'rate' => $rate,
            'inclusive' => $inclusive,
            'name' => self::name($model),
        ];
    }
}
