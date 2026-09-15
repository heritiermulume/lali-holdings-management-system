<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Core\Support\CurrencyConverter;
use App\Core\Support\CurrencySync;
use App\Core\Support\TaxSettings;
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
        $this->ensureBaseCurrencies();
        if (ExchangeRate::query()->doesntExist()) {
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
            ->map(fn (array $meta, string $code) => [
                'code' => $code,
                'name' => $meta['name'],
                'name_fr' => $meta['name_fr'],
                'symbol' => $meta['symbol'],
                'enabled' => in_array($code, $enabled, true),
            ])
            ->values()
            ->all();

        return $this->ok($items);
    }

    public function storeCurrency(Request $request, CurrencyConverter $fx): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3'],
        ]);
        $code = strtoupper($data['code']);
        $meta = CurrencyCatalog::find($code);

        $currency = Currency::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $meta['name'],
                'name_fr' => $meta['name_fr'],
                'symbol' => $meta['symbol'],
                'is_active' => true,
                'is_default' => Currency::query()->count() === 0,
                'sort_order' => (int) Currency::query()->max('sort_order') + 1,
            ]
        );

        if (Currency::query()->where('is_default', true)->doesntExist()) {
            $this->makeDefault($currency);
        }

        $fx->syncFromMarket();
        CurrencySync::ensureFromCountries();
        AuditLogger::record('create', $currency);

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

        AuditLogger::record('update', $currency);

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
        AuditLogger::record('delete', $currency);

        return $this->ok($this->payload($fx));
    }

    public function syncRates(CurrencyConverter $fx): JsonResponse
    {
        $sync = $fx->syncFromMarket();

        return $this->ok(array_merge($this->payload($fx), ['sync' => $sync]));
    }

    public function convert(Request $request, CurrencyConverter $fx): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
        ]);

        $from = strtoupper($data['from']);
        $to = strtoupper($data['to']);
        $amount = (float) $data['amount'];
        $rates = $fx->bankRates($from, $to);

        return $this->ok([
            'amount' => $amount,
            'from' => $from,
            'to' => $to,
            'rates' => $rates,
            'mid' => $fx->convert($amount, $from, $to, 'mid'),
            'buy' => $fx->convert($amount, $from, $to, 'buy'),
            'sell' => $fx->convert($amount, $from, $to, 'sell'),
            'tax' => [
                'name' => TaxSettings::name(),
                'rate' => TaxSettings::rate(),
                'inclusive' => TaxSettings::inclusive(),
            ],
        ]);
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

    private function ensureBaseCurrencies(): void
    {
        if (Currency::query()->exists()) {
            return;
        }

        $order = 0;
        foreach (['USD', 'CDF', 'EUR', 'TZS'] as $code) {
            $meta = CurrencyCatalog::find($code);
            Currency::query()->create([
                'code' => $code,
                'name' => $meta['name'],
                'name_fr' => $meta['name_fr'],
                'symbol' => $meta['symbol'],
                'is_default' => $code === 'USD',
                'is_active' => true,
                'sort_order' => $order++,
            ]);
        }
    }

    private function payload(CurrencyConverter $fx): array
    {
        CurrencySync::ensureFromCountries();
        $default = $fx->defaultCode();
        $spread = $fx->spread();
        $countries = Country::query()->where('is_active', true)->orderBy('name')->get();
        $currencies = Currency::query()->orderBy('sort_order')->orderBy('code')->get()
            ->map(function (Currency $currency) use ($fx, $default, $countries) {
                $vsDefault = $fx->bankRates($default, $currency->code);
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
                'inclusive' => TaxSettings::inclusive(),
            ],
            'currencies' => $currencies,
        ];
    }
}
