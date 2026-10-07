<?php

namespace App\Modules\Kams\Services;

use App\Models\Office;
use App\Modules\Kams\Models\KamsAsset;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockReturnItem;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class KamsFinanceService
{
    /**
     * @param  list<int>  $officeIds
     * @return array{
     *     revenue: float,
     *     expenses: float,
     *     profit: float,
     *     cash: float,
     *     assets: float,
     *     liabilities: float,
     *     receivables: float,
     *     payables: float,
     *     patrimoine_value: float
     * }
     */
    public function summarize(?int $companyId = null, ?string $from = null, ?string $to = null, array $officeIds = []): array
    {
        $sales = $this->sales($companyId, $officeIds, $from, $to);
        $revenue = round((float) (clone $sales)->sum('subtotal') - $this->returnedRevenue($companyId, $officeIds, $from, $to), 2);
        $expenses = $this->expenses($companyId, $officeIds, $from, $to);
        $margin = round((float) (clone $sales)->sum('margin') - $this->returnedMargin($companyId, $officeIds, $from, $to), 2);
        $receivables = round((float) (clone $sales)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->selectRaw('COALESCE(SUM(subtotal - paid_amount), 0) as value')
            ->value('value'), 2);
        $cash = $this->cash($companyId, $officeIds);
        $inventory = $this->inventoryValue($companyId, $officeIds);
        $patrimoine = $this->patrimoineValue($companyId, $officeIds);
        $payables = $this->payables($companyId, $officeIds);
        $savings = $this->savings($companyId, $officeIds);

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $margin,
            'cash' => $cash,
            'assets' => round($inventory + $cash + max(0, $receivables) + $patrimoine, 2),
            'liabilities' => round($savings + $payables, 2),
            'receivables' => max(0, $receivables),
            'payables' => $payables,
            'patrimoine_value' => $patrimoine,
        ];
    }

    /**
     * Revenue per office for the given period (sales subtotal minus validated returns).
     *
     * @param  list<int>  $officeIds
     * @return list<array{id: int, name: string, code: ?string, revenue: float}>
     */
    public function revenueByOffice(?int $companyId, string $from, string $to, array $officeIds = []): array
    {
        $offices = Office::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereIn('id', $officeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return $offices
            ->map(function (Office $office) use ($companyId, $from, $to) {
                $ids = [(int) $office->id];
                $revenue = round(
                    (float) $this->sales($companyId, $ids, $from, $to)->sum('subtotal')
                    - $this->returnedRevenue($companyId, $ids, $from, $to),
                    2
                );

                return [
                    'id' => (int) $office->id,
                    'name' => $office->name,
                    'code' => $office->code,
                    'revenue' => $revenue,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array{from: string, to: string, revenue: float, expenses: float, profit: float}>
     */
    public function trend(?int $companyId, string $from, string $to, array $officeIds = []): array
    {
        $series = $this->emptyBuckets($from, $to, ['revenue' => 0.0, 'expenses' => 0.0, 'profit' => 0.0]);

        $sales = $this->sales($companyId, $officeIds, $from, $to)->get(['sold_at', 'subtotal', 'margin']);
        foreach ($sales as $sale) {
            $key = $this->bucketKey(Carbon::parse($sale->sold_at), $series);
            if ($key === null) {
                continue;
            }
            $series[$key]['revenue'] = round($series[$key]['revenue'] + (float) $sale->subtotal, 2);
            $series[$key]['profit'] = round($series[$key]['profit'] + (float) $sale->margin, 2);
        }

        $returns = KamsStockReturnItem::query()
            ->whereHas('stockReturn', fn ($query) => $this->scopeReturns($query, $companyId, $officeIds, $from, $to))
            ->with('stockReturn:id,validated_at')
            ->get(['return_id', 'quantity', 'unit_price', 'unit_cost']);
        foreach ($returns as $line) {
            if (! $line->stockReturn?->validated_at) {
                continue;
            }
            $key = $this->bucketKey(Carbon::parse($line->stockReturn->validated_at), $series);
            if ($key === null) {
                continue;
            }
            $qty = (int) $line->quantity;
            $series[$key]['revenue'] = round($series[$key]['revenue'] - ($qty * (float) $line->unit_price), 2);
            $series[$key]['profit'] = round($series[$key]['profit'] - ($qty * ((float) $line->unit_price - (float) $line->unit_cost)), 2);
        }

        $entries = $this->expenseEntries($companyId, $officeIds, $from, $to)
            ->get(['amount', 'occurred_on', 'created_at']);
        foreach ($entries as $entry) {
            $key = $this->bucketKey(Carbon::parse($entry->occurred_on ?: $entry->created_at), $series);
            if ($key === null) {
                continue;
            }
            $series[$key]['expenses'] = round($series[$key]['expenses'] + abs((float) $entry->amount), 2);
        }

        return array_values($series);
    }

    /**
     * Operating cash movements from posted treasury entries.
     *
     * @param  list<int>  $officeIds
     * @return list<array{from: string, to: string, deposits: float, withdrawals: float, expenses: float, net: float}>
     */
    public function cashFlow(?int $companyId, string $from, string $to, array $officeIds = []): array
    {
        $series = $this->emptyBuckets($from, $to, [
            'deposits' => 0.0,
            'withdrawals' => 0.0,
            'expenses' => 0.0,
            'net' => 0.0,
        ]);

        $entries = KamsTreasuryEntry::query()
            ->where('status', 'posted')
            ->whereIn('type', ['deposit', 'withdrawal', 'expense'])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereIn('office_id', $officeIds))
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('occurred_on', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to) {
                        $query->whereNull('occurred_on')
                            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                    });
            })
            ->get(['type', 'amount', 'occurred_on', 'created_at']);

        foreach ($entries as $entry) {
            $key = $this->bucketKey(Carbon::parse($entry->occurred_on ?: $entry->created_at), $series);
            if ($key === null) {
                continue;
            }
            $amount = abs((float) $entry->amount);
            if ($entry->type === 'deposit') {
                $series[$key]['deposits'] = round($series[$key]['deposits'] + $amount, 2);
            } elseif ($entry->type === 'withdrawal') {
                $series[$key]['withdrawals'] = round($series[$key]['withdrawals'] + $amount, 2);
            } else {
                $series[$key]['expenses'] = round($series[$key]['expenses'] + $amount, 2);
            }
        }

        return array_values(array_map(function (array $row) {
            $row['net'] = round((float) $row['deposits'] - (float) $row['withdrawals'] - (float) $row['expenses'], 2);

            return $row;
        }, $series));
    }

    /**
     * @param  list<int>  $officeIds
     * @return array{guy: float, manager: float, assistant_manager: float, secretary: float, divisionnaire: float, total: float}
     */
    public function savingsByKind(?int $companyId = null, array $officeIds = []): array
    {
        $kinds = ['guy', 'manager', 'assistant_manager', 'secretary', 'divisionnaire'];
        $totals = array_fill_keys($kinds, 0.0);
        $rows = KamsSavingsAccount::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->selectRaw('kind, COALESCE(SUM(balance), 0) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        foreach ($kinds as $kind) {
            $totals[$kind] = round((float) ($rows[$kind] ?? 0), 2);
        }
        $totals['total'] = round(array_sum($totals), 2);

        return $totals;
    }

    /**
     * @param  array<string, float>  $metrics
     * @return array<string, array{from: string, to: string}&array<string, float>>
     */
    private function emptyBuckets(string $from, string $to, array $metrics): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $span = $start->diffInDays($end) + 1;
        $bucket = $span > 120 ? 'month' : ($span > 31 ? 'week' : 'day');
        $series = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($bucket === 'month') {
                $bucketFrom = $cursor->copy()->startOfMonth()->lt($start) ? $start->copy() : $cursor->copy()->startOfMonth();
                $bucketTo = $cursor->copy()->endOfMonth()->startOfDay();
                $cursor = $cursor->copy()->addMonth()->startOfMonth();
            } elseif ($bucket === 'week') {
                $weekStart = $cursor->copy()->startOfWeek(Carbon::MONDAY);
                $bucketFrom = $weekStart->lt($start) ? $start->copy() : $weekStart;
                $bucketTo = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
                $cursor = $bucketTo->copy()->addDay();
            } else {
                $bucketFrom = $cursor->copy();
                $bucketTo = $cursor->copy();
                $cursor = $cursor->copy()->addDay();
            }
            if ($bucketTo->gt($end)) {
                $bucketTo = $end->copy();
            }

            $series[$bucketFrom->toDateString()] = array_merge([
                'from' => $bucketFrom->toDateString(),
                'to' => $bucketTo->toDateString(),
            ], $metrics);
        }

        return $series;
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function sales(?int $companyId, array $officeIds, ?string $from, ?string $to): Builder
    {
        return KamsStockSale::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->when($from && $to, fn ($query) => $query->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']));
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function expenses(?int $companyId, array $officeIds, ?string $from, ?string $to): float
    {
        return round((float) $this->expenseEntries($companyId, $officeIds, $from, $to)
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) as value')
            ->value('value'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function expenseEntries(?int $companyId, array $officeIds, ?string $from, ?string $to): Builder
    {
        return KamsTreasuryEntry::query()
            ->where('status', 'posted')
            ->where('type', 'expense')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereIn('office_id', $officeIds))
            ->when($from && $to, function ($query) use ($from, $to) {
                $query->where(function ($query) use ($from, $to) {
                    $query->whereBetween('occurred_on', [$from, $to])
                        ->orWhere(function ($query) use ($from, $to) {
                            $query->whereNull('occurred_on')
                                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                        });
                });
            });
    }

    /**
     * Central balances already include every office, so company-wide cash uses
     * office accounts plus only the central account's own posted entries.
     *
     * @param  list<int>  $officeIds
     */
    private function cash(?int $companyId, array $officeIds): float
    {
        if ($officeIds !== []) {
            return round((float) KamsTreasuryAccount::query()
                ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
                ->whereIn('office_id', $officeIds)
                ->sum('balance'), 2);
        }

        $offices = (float) KamsTreasuryAccount::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereNotNull('office_id')
            ->sum('balance');
        $central = (float) KamsTreasuryEntry::query()
            ->where('status', 'posted')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereHas('account', fn ($account) => $account->where(function ($account) {
                $account->where('code', 'CENTRAL')->orWhereNull('office_id');
            }))
            ->sum('amount');

        return round($offices + $central, 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function inventoryValue(?int $companyId, array $officeIds): float
    {
        return round((float) KamsStockBalance::query()
            ->when($companyId, fn ($query) => $query->where('kams_stock_balances.company_id', $companyId))
            ->where('kams_stock_balances.condition', 'available')
            ->when($officeIds !== [], fn ($query) => $query->whereHas('depot', fn ($depot) => $depot->whereIn('office_id', $officeIds)))
            ->join('kams_products', 'kams_products.id', '=', 'kams_stock_balances.product_id')
            ->selectRaw('COALESCE(SUM(kams_stock_balances.quantity * COALESCE(NULLIF(kams_products.company_price, 0), NULLIF(kams_products.avg_cost, 0), kams_products.cost)), 0) as value')
            ->value('value'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    public function patrimoineValue(?int $companyId, array $officeIds = []): float
    {
        return round((float) KamsAsset::query()
            ->where('status', 'active')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereIn('office_id', $officeIds))
            ->selectRaw('COALESCE(SUM(quantity * unit_value), 0) as value')
            ->value('value'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function payables(?int $companyId, array $officeIds): float
    {
        return round((float) KamsSellerCommission::query()
            ->where('status', 'payable')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->selectRaw('COALESCE(SUM(payable_amount - paid_amount), 0) as value')
            ->value('value'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function savings(?int $companyId, array $officeIds): float
    {
        return round((float) KamsSavingsAccount::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($query) => $query->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->sum('balance'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function returnedRevenue(?int $companyId, array $officeIds, ?string $from, ?string $to): float
    {
        return round((float) $this->returnItems($companyId, $officeIds, $from, $to)
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as value')
            ->value('value'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function returnedMargin(?int $companyId, array $officeIds, ?string $from, ?string $to): float
    {
        return round((float) $this->returnItems($companyId, $officeIds, $from, $to)
            ->selectRaw('COALESCE(SUM(quantity * (unit_price - unit_cost)), 0) as value')
            ->value('value'), 2);
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function returnItems(?int $companyId, array $officeIds, ?string $from, ?string $to): Builder
    {
        return KamsStockReturnItem::query()
            ->whereHas('stockReturn', fn ($query) => $this->scopeReturns($query, $companyId, $officeIds, $from, $to));
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function scopeReturns(Builder $query, ?int $companyId, array $officeIds, ?string $from, ?string $to): void
    {
        $query->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->where('type', 'customer')
            ->whereIn('status', ['restocked', 'quarantine'])
            ->when($officeIds !== [], fn ($query) => $query->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->when($from && $to, fn ($query) => $query->whereBetween('validated_at', [$from.' 00:00:00', $to.' 23:59:59']));
    }

    /**
     * @param  array<string, array{from: string, to: string, revenue: float, expenses: float}>  $series
     */
    private function bucketKey(Carbon $date, array $series): ?string
    {
        $day = $date->toDateString();
        foreach ($series as $key => $row) {
            if ($day >= $row['from'] && $day <= $row['to']) {
                return $key;
            }
        }

        return null;
    }
}
