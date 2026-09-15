<?php

namespace App\Core\Support;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class CurrencyConverter
{
    private const FALLBACK = [
        'USD' => 1.0,
        'EUR' => 0.86,
        'GBP' => 0.74,
        'CDF' => 2850.0,
        'TZS' => 2470.0,
        'KES' => 129.0,
        'ZAR' => 17.6,
        'XAF' => 560.0,
        'XOF' => 560.0,
        'AED' => 3.67,
        'CNY' => 7.12,
        'CHF' => 0.80,
        'CAD' => 1.37,
        'JPY' => 147.0,
    ];

    public function defaultCode(): string
    {
        return Currency::defaultCode();
    }

    public function spread(): float
    {
        $percent = (float) Setting::value('finance', 'fx_spread_percent', 1.5);

        return max(0, $percent) / 100;
    }

    public function decimals(): int
    {
        $places = (int) Setting::value('finance', 'decimal_places', 2);

        return max(0, min(6, $places));
    }

    public function usdPer(string $code): float
    {
        $code = strtoupper($code);
        if ($code === 'USD') {
            return 1.0;
        }

        $rate = ExchangeRate::query()
            ->where('base_code', 'USD')
            ->where('quote_code', $code)
            ->value('mid_rate');

        if ($rate && (float) $rate > 0) {
            return (float) $rate;
        }

        return self::FALLBACK[$code] ?? 1.0;
    }

    /** How many units of $to equal 1 unit of $from (mid market). */
    public function midRate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $fromUsd = $this->usdPer($from);
        $toUsd = $this->usdPer($to);
        if ($fromUsd <= 0) {
            return 1.0;
        }

        return $toUsd / $fromUsd;
    }

    public function bankRates(string $from, string $to): array
    {
        $mid = $this->midRate($from, $to);
        $spread = $this->spread();

        return [
            'mid' => $mid,
            'buy' => $mid * (1 - $spread),
            'sell' => $mid * (1 + $spread),
        ];
    }

    public function convert(float $amount, string $from, string $to, string $side = 'mid'): float
    {
        $rates = $this->bankRates($from, $to);
        $rate = $rates[$side] ?? $rates['mid'];

        return round($amount * $rate, $this->decimals());
    }

    /**
     * @param  array<string, float>  $amounts
     * @return array<string, float>
     */
    public function convertBag(array $amounts, string $from, ?string $to = null, string $side = 'mid'): array
    {
        $to ??= $this->defaultCode();
        $converted = [];
        foreach ($amounts as $key => $value) {
            $converted[$key] = $this->convert((float) $value, $from, $to, $side);
        }

        return $converted;
    }

    public function syncFromMarket(): array
    {
        $payload = Http::timeout(12)
            ->acceptJson()
            ->get('https://open.er-api.com/v6/latest/USD')
            ->json();

        $rates = is_array($payload['rates'] ?? null) ? $payload['rates'] : [];
        $source = 'open.er-api.com';
        $fetchedAt = now();

        if ($rates === []) {
            $rates = self::FALLBACK;
            $source = 'fallback';
        }

        $rates['USD'] = 1;

        $codes = Currency::query()->pluck('code')->all();
        if ($codes === []) {
            $codes = array_keys($rates);
        }

        foreach (array_unique(array_merge($codes, ['USD'])) as $code) {
            $code = strtoupper((string) $code);
            $mid = (float) ($rates[$code] ?? self::FALLBACK[$code] ?? 0);
            if ($mid <= 0) {
                continue;
            }

            ExchangeRate::query()->updateOrCreate(
                ['base_code' => 'USD', 'quote_code' => $code],
                ['mid_rate' => $mid, 'fetched_at' => $fetchedAt, 'source' => $source]
            );
        }

        return [
            'source' => $source,
            'fetched_at' => $fetchedAt->toIso8601String(),
            'count' => ExchangeRate::query()->where('base_code', 'USD')->count(),
        ];
    }
}
