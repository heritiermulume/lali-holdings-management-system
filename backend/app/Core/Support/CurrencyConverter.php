<?php

namespace App\Core\Support;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CurrencyConverter
{
    public function defaultCode(): string
    {
        return Currency::defaultCode();
    }

    public function spread(): float
    {
        return max(0, SettingsCatalog::number('finance', 'fx_spread_percent')) / 100;
    }

    public function decimals(): int
    {
        $places = (int) SettingsCatalog::number('finance', 'decimal_places');

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

        throw new RuntimeException("No exchange rate found for {$code}. Sync market rates first.");
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
            throw new RuntimeException("Invalid exchange rate for {$from}.");
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
        if ($rates === []) {
            throw new RuntimeException('Unable to fetch market exchange rates.');
        }

        $source = 'open.er-api.com';
        $fetchedAt = now();
        $rates['USD'] = 1;

        $codes = Currency::query()->pluck('code')->all();
        if ($codes === []) {
            throw new RuntimeException('No currencies configured in the database.');
        }

        foreach (array_unique(array_merge($codes, ['USD'])) as $code) {
            $code = strtoupper((string) $code);
            $mid = (float) ($rates[$code] ?? 0);
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
