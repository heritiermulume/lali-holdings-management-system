<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\CurrencyConverter;
use App\Core\Support\CurrencySync;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Support\CurrencyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    use RespondsWithJson;

    public function overview(CurrencyConverter $fx): JsonResponse
    {
        CurrencySync::ensureFromCountries();
        if (Currency::query()->exists() && ExchangeRate::query()->doesntExist()) {
            try {
                $fx->syncFromMarket();
            } catch (\Throwable) {
            }
        }

        return $this->ok($this->payload($fx));
    }

    public function catalog(): JsonResponse
    {
        $enabled = Currency::query()->pluck('code')->all();

        $items = collect(CurrencyCatalog::all())
            ->map(function (array $meta, string $code) use ($enabled) {
                $row = Currency::query()->where('code', $code)->first();

                return [
                    'code' => $code,
                    'name' => $row?->name ?? $meta['name'],
                    'name_fr' => $row?->name_fr ?? $meta['name_fr'],
                    'symbol' => $row?->symbol ?? $meta['symbol'],
                    'enabled' => in_array($code, $enabled, true),
                ];
            })
            ->values()
            ->all();

        return $this->ok($items);
    }

    public function storeCurrency(Request $request, CurrencyConverter $fx): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3'],
            'name' => ['nullable', 'string', 'max:120'],
            'name_fr' => ['nullable', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:16'],
        ]);
        $code = strtoupper($data['code']);
        $existing = Currency::query()->where('code', $code)->first();
        $meta = CurrencyCatalog::find($code);

        $currency = Currency::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $data['name'] ?? $existing?->name ?? $meta['name'],
                'name_fr' => $data['name_fr'] ?? $existing?->name_fr ?? $meta['name_fr'],
                'symbol' => $data['symbol'] ?? $existing?->symbol ?? $meta['symbol'],
                'is_active' => true,
                'is_default' => $existing?->is_default ?? Currency::query()->count() === 0,
                'sort_order' => $existing?->sort_order ?? ((int) Currency::query()->max('sort_order') + 1),
            ]
        );

        if (Currency::query()->where('is_default', true)->doesntExist()) {
            $this->makeDefault($currency);
        }

        try {
            $fx->syncFromMarket();
        } catch (\Throwable) {
        }
        CurrencySync::ensureFromCountries();

        return $this->created($this->payload($fx));
    }

    public function updateCurrency(Request $request, Currency $currency, CurrencyConverter $fx): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_default', $data) && $data['is_default']) {
            $this->makeDefault($currency);
        } elseif (array_key_exists('is_active', $data)) {
            if ($currency->is_default && ! $data['is_active']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The default currency cannot be deactivated.',
                ], 422);
            }
            $currency->is_active = $data['is_active'];
            $currency->save();
        }

        return $this->ok($this->payload($fx));
    }

    public function destroyCurrency(Currency $currency, CurrencyConverter $fx): JsonResponse
    {
        if ($currency->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'The default currency cannot be deleted.',
            ], 422);
        }

        if (Country::query()->where('currency_code', $currency->code)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This currency is still used by a country.',
            ], 409);
        }

        $currency->delete();
        ExchangeRate::query()
            ->where(fn ($q) => $q->where('quote_code', $currency->code)->orWhere('base_code', $currency->code))
            ->delete();

        return $this->ok($this->payload($fx));
    }

    public function syncRates(CurrencyConverter $fx): JsonResponse
    {
        try {
            $sync = $fx->syncFromMarket();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return $this->ok(array_merge($this->payload($fx), ['sync' => $sync]));
    }

    private function makeDefault(Currency $currency): void
    {
        Currency::query()->where('id', '!=', $currency->id)->update(['is_default' => false]);
        $currency->is_default = true;
        $currency->is_active = true;
        $currency->save();

        Setting::query()->updateOrCreate(
            ['group' => 'finance', 'key' => 'reporting_currency'],
            ['value' => $currency->code, 'type' => 'string']
        );
    }

    private function payload(CurrencyConverter $fx): array
    {
        CurrencySync::ensureFromCountries();
        $default = $fx->defaultCode();
        $spread = $fx->spread();
        $countries = Country::query()->where('is_active', true)->orderBy('name')->get();
        $currencies = Currency::query()->orderBy('sort_order')->orderBy('code')->get()
            ->map(function (Currency $currency) use ($fx, $default, $countries) {
                $vsDefault = ['mid' => null, 'buy' => null, 'sell' => null];
                if ($default !== '') {
                    try {
                        $vsDefault = $fx->bankRates($default, $currency->code);
                    } catch (\Throwable) {
                    }
                }
                $row = ExchangeRate::query()
                    ->where('base_code', 'USD')
                    ->where('quote_code', $currency->code)
                    ->first();
                $linked = $countries->where('currency_code', $currency->code)->values();

                return [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'name_fr' => $currency->name_fr,
                    'symbol' => $currency->symbol,
                    'is_default' => $currency->is_default,
                    'is_active' => $currency->is_active,
                    'mid' => $vsDefault['mid'],
                    'buy' => $vsDefault['buy'],
                    'sell' => $vsDefault['sell'],
                    'fetched_at' => $row?->fetched_at?->toIso8601String(),
                    'source' => $row?->source,
                    'countries' => $linked->map(fn (Country $country) => [
                        'id' => $country->id,
                        'name' => $country->name,
                        'name_fr' => $country->name_fr,
                        'iso_code' => $country->iso_code,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'default' => $default,
            'spread_percent' => $spread * 100,
            'decimals' => $fx->decimals(),
            'countries' => $countries->map(fn (Country $country) => [
                'id' => $country->id,
                'name' => $country->name,
                'name_fr' => $country->name_fr,
                'iso_code' => $country->iso_code,
                'currency_code' => $country->currency_code,
                'currency_symbol' => $country->currency_symbol,
                'tax_name' => $country->tax_name,
                'tax_rate' => $country->tax_rate,
            ])->values()->all(),
            'tax' => [
                'inclusive' => false,
            ],
            'currencies' => $currencies,
        ];
    }
}
