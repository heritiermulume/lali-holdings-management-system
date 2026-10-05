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

    public static function inclusive(): bool
    {
        return SettingsCatalog::bool('finance', 'tax_inclusive_prices');
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
