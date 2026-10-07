<?php

namespace App\Core\Support;

use App\Models\Country;
use App\Support\SettingsCatalog;

class TaxSettings
{
    public static function name(?Country $country = null): string
    {
        if ($country?->tax_name) {
            return (string) $country->tax_name;
        }

        return SettingsCatalog::string('finance', 'tax_name');
    }

    public static function rate(?Country $country = null): float
    {
        if ($country && $country->tax_rate !== null) {
            return max(0, (float) $country->tax_rate);
        }

        return max(0, SettingsCatalog::number('finance', 'default_tax_rate'));
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
        $factor = $rate / 100;
        $places = max(0, min(6, (int) SettingsCatalog::number('finance', 'decimal_places')));

        $subtotal = round($amount, $places);
        $tax = round($subtotal * $factor, $places);
        $total = round($subtotal + $tax, $places);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'rate' => $rate,
            'inclusive' => false,
            'name' => self::name($model),
        ];
    }
}
