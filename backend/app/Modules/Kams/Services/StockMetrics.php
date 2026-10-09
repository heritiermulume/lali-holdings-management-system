<?php

namespace App\Modules\Kams\Services;

use App\Models\Office;
use App\Modules\Kams\Models\KamsDepot;
use App\Support\SettingsCatalog;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Support\RevenueRuleCatalog;
use App\Modules\Kams\Models\KamsStockAssignmentItem;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockReturnItem;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockSaleItem;
use App\Modules\Kams\Models\KamsStockTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockMetrics
{
    public function __construct(
        private readonly StockInventory $inventory,
        private readonly RevenueShareService $revenueShares,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recap(string $groupBy = 'week', ?int $companyId = null, ?string $from = null, ?string $to = null, array $officeIds = []): array
    {
        $groupBy = in_array($groupBy, ['day', 'week', 'month', 'custom'], true) ? $groupBy : 'week';
        if ($groupBy === 'custom' && $from && $to) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
            if ($end->gt(now())) {
                $end = now()->endOfDay();
            }
            $span = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
            $bucketMode = $span > 120 ? 'month' : ($span > 31 ? 'week' : 'day');
            $series = $this->seriesBetween($start, $end, $bucketMode);
        } else {
            $groupBy = $groupBy === 'custom' ? 'week' : $groupBy;
            $today = now();
            $weekStart = ((int) SettingsCatalog::string('general', 'week_start')) === 0
                ? Carbon::SUNDAY
                : Carbon::MONDAY;
            [$start, $end] = match ($groupBy) {
                'day' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
                'month' => [$today->copy()->startOfMonth()->startOfDay(), $today->copy()->endOfMonth()->endOfDay()],
                default => [$today->copy()->startOfWeek($weekStart)->startOfDay(), $today->copy()->endOfWeek($weekStart === Carbon::SUNDAY ? Carbon::SATURDAY : Carbon::SUNDAY)->endOfDay()],
            };
            $bucketMode = $groupBy;
            $series = $this->seriesBetween($start, $end, $bucketMode);
        }

        if ($companyId) {
            $this->inventory->ensureOfficeDepots($companyId);
        }

        $threshold = (int) SettingsCatalog::number('kams', 'stock_alert_threshold');
        $products = KamsProduct::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $assignments = KamsStockAssignment::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds));
        $sales = KamsStockSale::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->whereBetween('sold_at', [$start, $end])
            ->get(['sold_at', 'paid_amount', 'margin', 'subtotal']);
        $opened = (clone $assignments)->whereBetween('assigned_at', [$start, $end])->get(['assigned_at']);
        $transfers = KamsStockTransfer::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], function ($q) use ($officeIds) {
                $q->where(function ($inner) use ($officeIds) {
                    $inner->whereHas('fromDepot', fn ($depot) => $depot->whereIn('office_id', $officeIds))
                        ->orWhereHas('toDepot', fn ($depot) => $depot->whereIn('office_id', $officeIds));
                });
            })
            ->whereBetween('transferred_at', [$start, $end])
            ->get(['transferred_at']);

        foreach ($sales as $row) {
            $bucket = $this->bucketFor($row->sold_at, $bucketMode, $series);
            if ($bucket === null) {
                continue;
            }
            $series[$bucket]['cash'] += (float) $row->paid_amount;
            $series[$bucket]['profit'] += (float) $row->margin;
            $series[$bucket]['sales']++;
        }
        foreach ($opened as $row) {
            $bucket = $this->bucketFor($row->assigned_at, $bucketMode, $series);
            if ($bucket) {
                $series[$bucket]['assignments']++;
            }
        }
        foreach ($transfers as $row) {
            $bucket = $this->bucketFor($row->transferred_at, $bucketMode, $series);
            if ($bucket) {
                $series[$bucket]['transfers']++;
            }
        }

        $officeScoped = $officeIds !== [];
        // Available stock = office depots only (never warehouses, never agents), always office-scoped when filtered.
        $warehouseUnits = $officeScoped
            ? 0
            : (int) KamsStockBalance::query()
                ->available()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->whereHas('depot', fn ($q) => $q->where('kind', 'warehouse'))
                ->sum('quantity');
        $depotUnits = (int) KamsStockBalance::query()
            ->available()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereHas('depot', fn ($q) => $q
                ->where('kind', 'depot')
                ->when($officeScoped, fn ($inner) => $inner->whereIn('office_id', $officeIds)))
            ->sum('quantity');
        $withAgents = (int) KamsStockBalance::query()
            ->available()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereHas('depot', fn ($q) => $q
                ->where('kind', 'seller')
                ->when($officeScoped, fn ($inner) => $inner->whereIn('office_id', $officeIds)))
            ->sum('quantity');
        $inTransit = (int) KamsStockBalance::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('condition', 'in_transit')
            ->whereHas('depot', fn ($depot) => $depot
                ->when(
                    $officeScoped,
                    fn ($inner) => $inner->whereIn('office_id', $officeIds)->where('kind', '!=', 'warehouse'),
                    fn ($inner) => $inner->whereIn('kind', ['warehouse', 'depot', 'seller']),
                ))
            ->sum('quantity');
        $quarantine = (int) KamsStockBalance::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('condition', ['quarantine', 'damaged'])
            ->whereHas('depot', fn ($depot) => $depot
                ->when(
                    $officeScoped,
                    fn ($inner) => $inner->whereIn('office_id', $officeIds)->where('kind', '!=', 'warehouse'),
                    fn ($inner) => $inner->whereIn('kind', ['warehouse', 'depot', 'seller']),
                ))
            ->sum('quantity');
        $inventoryValue = KamsProduct::sumStockSaleValue(
            KamsStockBalance::query()
                ->available()
                ->when($companyId, fn ($q) => $q->where('kams_stock_balances.company_id', $companyId))
                ->whereHas('depot', fn ($depot) => $depot
                    ->where('kind', 'depot')
                    ->when($officeScoped, fn ($inner) => $inner->whereIn('office_id', $officeIds)))
        );
        $periodSales = KamsStockSale::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
            ->whereBetween('sold_at', [$start, $end]);
        $soldUnits = (int) KamsStockSaleItem::query()
            ->whereHas('sale', fn ($q) => $q
                ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                ->when($officeIds !== [], fn ($inner) => $inner->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
                ->whereBetween('sold_at', [$start, $end]))
            ->sum('quantity');
        $returnedSold = (int) KamsStockReturnItem::query()
            ->whereHas('stockReturn', fn ($q) => $q
                ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                ->when($officeIds !== [], fn ($inner) => $inner->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
                ->where('type', 'customer')
                ->whereIn('status', ['restocked', 'quarantine'])
                ->whereBetween('validated_at', [$start, $end]))
            ->sum('quantity');
        $soldUnits = max(0, $soldUnits - $returnedSold);
        $returnedMargin = (float) KamsStockReturnItem::query()
            ->whereHas('stockReturn', fn ($q) => $q
                ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                ->when($officeIds !== [], fn ($inner) => $inner->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
                ->where('type', 'customer')
                ->whereIn('status', ['restocked', 'quarantine'])
                ->whereBetween('validated_at', [$start, $end]))
            ->selectRaw('COALESCE(SUM(quantity * (unit_price - unit_cost)), 0) as value')
            ->value('value');
        $commissionsPayable = (float) KamsRevenueShare::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->where('status', 'payable')
            ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
            ->sum('amount');
        $activeSellers = KamsDepot::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->where('kind', 'seller')
            ->whereHas('balances', fn ($q) => $q->where('condition', 'available')->where('quantity', '>', 0))
            ->count();

        $lowLocations = KamsStockBalance::query()
            ->with(['depot:id,name,kind,office_id', 'product:id,name,sku,price,cost,avg_cost,field_price,stock'])
            ->available()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereHas('depot', fn ($q) => $q
                ->where('kind', 'depot')
                ->when($officeScoped, fn ($inner) => $inner->whereIn('office_id', $officeIds)))
            ->where('quantity', '<=', $threshold)
            ->where('quantity', '>', 0)
            ->orderBy('quantity')
            ->limit(8)
            ->get();

        $officesMissingDepot = 0;
        if ($companyId) {
            $offices = Office::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->when($officeScoped, fn ($q) => $q->whereIn('id', $officeIds))
                ->pluck('id');
            $covered = KamsDepot::query()
                ->where('company_id', $companyId)
                ->where('kind', 'depot')
                ->whereIn('office_id', $offices)
                ->pluck('office_id');
            $officesMissingDepot = $offices->diff($covered)->count();
        }

        return [
            'group_by' => $groupBy,
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'totals' => [
                'products' => (clone $products)->count(),
                'warehouses' => KamsDepot::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'warehouse')->count(),
                'depots' => KamsDepot::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'depot')->count(),
                'warehouse_units' => $warehouseUnits,
                'depot_units' => $depotUnits,
                'with_agents' => $withAgents,
                'in_transit' => $inTransit,
                'quarantine' => $quarantine,
                // Available stock = sum of office depots only (exclude warehouses and agents).
                'units' => $depotUnits,
                'inventory_value' => round($inventoryValue, 2),
                'open_assignments' => (clone $assignments)->where('status', 'open')->count(),
                'sold_units' => $soldUnits,
                'active_sellers' => $activeSellers,
                'commissions_payable' => round($commissionsPayable, 2),
                'guy_savings' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'guy')->sum('balance'), 2),
                'manager_savings' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'manager')->sum('balance'), 2),
                'assistant_manager_savings' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'assistant_manager')->sum('balance'), 2),
                'cash' => round((float) (clone $periodSales)->sum('paid_amount'), 2),
                'profit' => round(max(0, (float) (clone $periodSales)->sum('margin') - $returnedMargin), 2),
                'loss' => 0,
                'expected' => round((float) (clone $periodSales)->sum('subtotal'), 2),
            ],
            'series' => array_values($series),
            'alerts' => $this->alerts($companyId, $threshold, $officesMissingDepot, $lowLocations),
            'recommendations' => $this->recommendations($companyId, $threshold),
            'low_stock' => SettingsCatalog::bool('kams', 'low_stock_notify')
                ? $lowLocations->map(fn (KamsStockBalance $row) => [
                    'product' => $row->product?->name,
                    'sku' => $row->product?->sku,
                    'location' => $row->depot?->name,
                    'kind' => $row->depot?->kind,
                    'quantity' => $row->quantity,
                ])->values()->all()
                : [],
            'settings' => [
                'stock_alert_threshold' => $threshold,
                'stock_loss_alert_percent' => SettingsCatalog::number('kams', 'stock_loss_alert_percent'),
                'low_stock_notify' => SettingsCatalog::bool('kams', 'low_stock_notify'),
                'allow_backorders' => SettingsCatalog::bool('kams', 'allow_backorders'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hierarchy(?int $companyId = null): array
    {
        if ($companyId) {
            $this->inventory->ensureOfficeDepots($companyId);
        }

        $warehouseUnits = (int) KamsStockBalance::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('condition', 'available')
            ->whereHas('depot', fn ($q) => $q->where('kind', 'warehouse'))
            ->sum('quantity');
        $offices = Office::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'code']);

        $tree = [];
        foreach ($offices as $office) {
            $depot = $this->inventory->officeDepot($office);
            $officeUnits = (int) KamsStockBalance::query()->where('depot_id', $depot->id)->where('condition', 'available')->sum('quantity');
            $sellerUnits = (int) KamsStockBalance::query()
                ->where('condition', 'available')
                ->whereHas('depot', fn ($q) => $q->where('kind', 'seller')->where('office_id', $office->id))
                ->sum('quantity');
            $received = (int) KamsStockTransfer::query()
                ->where('to_depot_id', $depot->id)
                ->whereIn('status', ['received', 'completed'])
                ->withSum('items', 'quantity')
                ->get()
                ->sum('items_sum_quantity');
            $value = KamsProduct::sumStockSaleValue(
                KamsStockBalance::query()
                    ->available()
                    ->whereIn('depot_id', KamsDepot::query()->where(function ($q) use ($depot, $office) {
                        $q->whereKey($depot->id)->orWhere(fn ($inner) => $inner->where('kind', 'seller')->where('office_id', $office->id));
                    })->pluck('id'))
            );

            $guys = KamsMember::query()
                ->where('office_id', $office->id)
                ->where('status', 'active')
                ->get(['id', 'full_name', 'member_code', 'manager_member_id', 'manager_name']);
            $managers = [];
            foreach ($guys->groupBy(fn (KamsMember $row) => $row->manager_member_id ?: ($row->manager_name ?: 'none')) as $key => $team) {
                $manager = $team->first()?->manager_member_id
                    ? KamsMember::query()->find($team->first()->manager_member_id)
                    : null;
                $managers[] = [
                    'manager' => $manager?->full_name ?: ($team->first()?->manager_name ?: '—'),
                    'savings' => round((float) KamsSavingsAccount::query()->where('kind', 'manager')->when($manager, fn ($q) => $q->where('member_id', $manager->id))->when(! $manager, fn ($q) => $q->whereRaw('1=0'))->sum('balance'), 2),
                    'guys' => $team->map(function (KamsMember $guy) {
                        $stock = (int) KamsStockBalance::query()
                            ->where('condition', 'available')
                            ->whereHas('depot', fn ($q) => $q->where('kind', 'seller')->where('member_id', $guy->id))
                            ->sum('quantity');

                        return [
                            'id' => $guy->id,
                            'name' => $guy->full_name,
                            'code' => $guy->member_code,
                            'stock' => $stock,
                            'savings' => round((float) KamsSavingsAccount::query()->where('member_id', $guy->id)->where('kind', 'guy')->value('balance'), 2),
                            'sales' => round((float) KamsStockSale::query()->where('member_id', $guy->id)->sum('field_total'), 2),
                        ];
                    })->values()->all(),
                ];
            }

            $tree[] = [
                'office_id' => $office->id,
                'office' => $office->name,
                'code' => $office->code,
                'received' => $received,
                'remaining_office' => $officeUnits,
                'remaining_sellers' => $sellerUnits,
                'remaining' => $officeUnits + $sellerUnits,
                'stock_value' => round($value, 2),
                'managers' => array_values($managers),
            ];
        }

        return [
            'head_office' => [
                'units' => $warehouseUnits,
                'kind' => 'warehouse',
            ],
            'offices' => $tree,
            'savings' => [
                'guys' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'guy')->sum('balance'), 2),
                'managers' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'manager')->sum('balance'), 2),
            ],
        ];
    }

    /**
     * @param  list<int>  $officeIds
     * @return array<string, mixed>
     */
    public function finance(string $groupBy = 'week', ?int $companyId = null, array $officeIds = [], ?string $from = null, ?string $to = null): array
    {
        $data = $this->recap($groupBy, $companyId, $from, $to, $officeIds);
        $start = $data['range']['from'];
        $end = $data['range']['to'];

        $offices = Office::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('id', $officeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $compensation = $this->revenueShares->dashboardTotals($companyId, $officeIds, $start, $end);
        $officeRows = $offices
            ->map(fn (Office $office) => $this->officeFinance($office, $start, $end, $compensation['by_office'][(int) $office->id] ?? null))
            ->values()
            ->all();

        $companyShare = (float) KamsStockSale::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds)))
            ->whereBetween('sold_at', [$start.' 00:00:00', $end.' 23:59:59'])
            ->sum('company_total');

        if ($officeIds !== []) {
            $data['totals']['cash'] = round(array_sum(array_column($officeRows, 'cash')), 2);
            $data['totals']['profit'] = round(array_sum(array_column($officeRows, 'profit')), 2);
            $data['totals']['expected'] = round(array_sum(array_column($officeRows, 'expected')), 2);
            $data['totals']['guy_savings'] = round(array_sum(array_column($officeRows, 'guy_savings')), 2);
            $data['totals']['manager_savings'] = round(array_sum(array_column($officeRows, 'manager_savings')), 2);
            $data['totals']['assistant_manager_savings'] = round(array_sum(array_column($officeRows, 'assistant_manager_savings')), 2);
            $data['totals']['commissions_payable'] = round(array_sum(array_column($officeRows, 'commissions_payable')), 2);
            // Keep recap inventory_value (offices + warehouses); per-office rows stay office-only.
            $data['series'] = $this->officeSeries($data['series'], $groupBy, $companyId, $officeIds, $start, $end);
        }

        $data['totals']['company_share'] = round($companyShare, 2);
        $data['totals']['compensation'] = $this->compensationSummary($compensation);
        $data['offices'] = $officeRows;
        $data['compensation'] = $this->compensationSummary($compensation);

        return $data;
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function officesForPeriod(?int $companyId, array $officeIds, string $from, string $to): array
    {
        $offices = Office::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('id', $officeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $compensation = $this->revenueShares->dashboardTotals($companyId, $officeIds, $from, $to);

        return $offices
            ->map(fn (Office $office) => $this->officeFinance($office, $from, $to, $compensation['by_office'][(int) $office->id] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    private function officeSeries(array $series, string $groupBy, ?int $companyId, array $officeIds, string $from, string $to): array
    {
        $keyed = [];
        foreach ($series as $row) {
            $row['cash'] = 0;
            $row['profit'] = 0;
            $row['sales'] = 0;
            $row['loss'] = 0;
            $keyed[$row['key']] = $row;
        }

        $sales = KamsStockSale::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds))
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['sold_at', 'paid_amount', 'margin']);

        foreach ($sales as $row) {
            $bucket = $this->bucketFor($row->sold_at, $groupBy, $keyed);
            if ($bucket === null) {
                continue;
            }
            $keyed[$bucket]['cash'] += (float) $row->paid_amount;
            $keyed[$bucket]['profit'] += (float) $row->margin;
            $keyed[$bucket]['sales']++;
        }

        return array_values($keyed);
    }

    /**
     * @param  array<string, float>|null  $compensation
     * @return array<string, mixed>
     */
    private function officeFinance(Office $office, string $from, string $to, ?array $compensation = null): array
    {
        $sales = KamsStockSale::query()
            ->whereHas('member', fn ($m) => $m->where('office_id', $office->id))
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $memberIds = KamsMember::query()->where('office_id', $office->id)->pluck('id');
        $depotIds = KamsDepot::query()
            ->where('office_id', $office->id)
            ->where('kind', 'depot')
            ->pluck('id');
        $inventory = $depotIds->isEmpty()
            ? 0.0
            : KamsProduct::sumStockSaleValue(
                KamsStockBalance::query()
                    ->available()
                    ->whereIn('depot_id', $depotIds)
            );

        $comp = $compensation ?? [
            'generated' => 0.0,
            'posted' => 0.0,
            'accrued' => 0.0,
            'remaining' => 0.0,
            'manager_sale' => 0.0,
            'assistant_manager' => 0.0,
            'manager_office' => 0.0,
            'manager_certified_office' => 0.0,
            'manager_descendant_office' => 0.0,
            'divisionnaire_office' => 0.0,
            'secretary_office' => 0.0,
            'salary_paid' => 0.0,
        ];

        return [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
            'cash' => round((float) (clone $sales)->sum('paid_amount'), 2),
            'profit' => round((float) (clone $sales)->sum('margin'), 2),
            'expected' => round((float) (clone $sales)->sum('subtotal'), 2),
            'company_share' => round((float) (clone $sales)->sum('company_total'), 2),
            'sales_count' => (clone $sales)->count(),
            'agents' => KamsMember::query()->where('office_id', $office->id)->whereIn('status', ['active', 'verified'])->count(),
            'guy_savings' => round((float) KamsSavingsAccount::query()->whereIn('member_id', $memberIds->all() ?: [0])->where('kind', 'guy')->sum('balance'), 2),
            'manager_savings' => round((float) KamsSavingsAccount::query()->whereIn('member_id', $memberIds->all() ?: [0])->whereIn('kind', ['manager', 'salary', 'percentage'])->sum('balance'), 2),
            'assistant_manager_savings' => round((float) KamsSavingsAccount::query()->whereIn('member_id', $memberIds->all() ?: [0])->where('kind', 'assistant_manager')->sum('balance'), 2),
            'commissions_payable' => round((float) KamsRevenueShare::query()
                ->where('office_id', $office->id)
                ->where('status', 'payable')
                ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
                ->sum('amount'), 2),
            'inventory_value' => round($inventory, 2),
            'compensation_generated' => round((float) ($comp['generated'] ?? 0), 2),
            'compensation_posted' => round((float) ($comp['posted'] ?? 0), 2),
            'compensation_remaining' => round((float) ($comp['remaining'] ?? $comp['accrued'] ?? 0), 2),
            'manager_sale' => round((float) ($comp['manager_sale'] ?? 0), 2),
            'assistant_share' => round((float) ($comp['assistant_manager'] ?? $comp['assistant_share'] ?? 0), 2),
            'manager_certified_share' => round((float) ($comp['manager_certified_office'] ?? 0), 2),
            'manager_office_share' => round((float) (($comp['manager_office'] ?? 0) + ($comp['manager_descendant_office'] ?? 0)), 2),
            'divisionnaire_share' => round((float) ($comp['divisionnaire_office'] ?? 0), 2),
            'secretary_share' => round((float) ($comp['secretary_office'] ?? 0), 2),
            'salary_paid' => round((float) ($comp['salary_paid'] ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $compensation
     * @return array<string, float>
     */
    private function compensationSummary(array $compensation): array
    {
        return [
            'generated' => round((float) ($compensation['generated'] ?? 0), 2),
            'posted' => round((float) ($compensation['posted'] ?? 0), 2),
            'accrued' => round((float) ($compensation['accrued'] ?? 0), 2),
            'remaining' => round((float) ($compensation['remaining'] ?? 0), 2),
            'assistant_share' => round((float) ($compensation['assistant_share'] ?? 0), 2),
            'manager_sale' => round((float) ($compensation['manager_sale'] ?? 0), 2),
            'manager_certified_office' => round((float) ($compensation['manager_certified_office'] ?? 0), 2),
            'manager_office' => round((float) (($compensation['manager_office'] ?? 0) + ($compensation['manager_descendant_office'] ?? 0)), 2),
            'divisionnaire_office' => round((float) ($compensation['divisionnaire_office'] ?? 0), 2),
            'secretary_office' => round((float) ($compensation['secretary_office'] ?? 0), 2),
            'salary_paid' => round((float) ($compensation['salary_paid'] ?? 0), 2),
        ];
    }

    /**
     * @param  Collection<int, KamsStockBalance>  $lowLocations
     * @return list<array{tone: string, key: string, count: int}>
     */
    private function alerts(?int $companyId, int $threshold, int $officesMissingDepot, $lowLocations): array
    {
        $openOld = KamsStockAssignment::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'open')
            ->where('assigned_at', '<=', now()->subDays(7))
            ->count();
        $inTransitTransfers = KamsStockTransfer::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'in_transit')
            ->count();
        $quarantineUnits = (int) KamsStockBalance::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('condition', ['quarantine', 'damaged'])
            ->sum('quantity');
        $payableCommissions = KamsRevenueShare::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'payable')
            ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
            ->distinct()
            ->count('beneficiary_member_id');
        $emptyWarehouse = KamsProduct::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'active')
            ->whereDoesntHave('balances', fn ($q) => $q->where('quantity', '>', 0)->whereHas('depot', fn ($d) => $d->where('kind', 'warehouse')))
            ->count();

        return array_values(array_filter([
            SettingsCatalog::bool('kams', 'low_stock_notify')
                ? ['tone' => 'amber', 'key' => 'lowStockLocations', 'count' => $lowLocations->count()]
                : null,
            ['tone' => 'red', 'key' => 'overdueAssignments', 'count' => $openOld],
            ['tone' => 'amber', 'key' => 'inTransitTransfers', 'count' => $inTransitTransfers],
            ['tone' => 'red', 'key' => 'quarantineUnits', 'count' => $quarantineUnits],
            ['tone' => 'amber', 'key' => 'payableCommissions', 'count' => $payableCommissions],
            ['tone' => 'red', 'key' => 'officesWithoutDepot', 'count' => $officesMissingDepot],
            ['tone' => 'amber', 'key' => 'emptyWarehouseProducts', 'count' => $emptyWarehouse],
            ['tone' => 'slate', 'key' => 'stockAlertThreshold', 'count' => $threshold],
        ], fn ($row) => $row && ($row['key'] === 'stockAlertThreshold' || $row['count'] > 0)));
    }

    /**
     * @return list<array{key: string, product?: string, from?: string, to?: string, quantity?: int, agent?: string}>
     */
    private function recommendations(?int $companyId, int $threshold): array
    {
        $rows = [];
        $warehouses = KamsDepot::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'warehouse')->with('balances')->get();
        $depots = KamsDepot::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'depot')->with(['balances', 'office'])->get();

        foreach ($depots as $depot) {
            foreach ($depot->balances as $balance) {
                if (($balance->condition ?? 'available') !== 'available' || $balance->quantity > $threshold) {
                    continue;
                }
                $target = max($threshold * 2, $threshold);
                $needed = max($target - (int) $balance->quantity, max(1, $threshold));
                foreach ($warehouses as $warehouse) {
                    $available = (int) $warehouse->balances->firstWhere('product_id', $balance->product_id)?->quantity;
                    if ($available >= $needed) {
                        $product = KamsProduct::query()->find($balance->product_id);
                        $rows[] = [
                            'key' => 'transferToOffice',
                            'product' => $product?->name,
                            'from' => $warehouse->name,
                            'to' => $depot->name,
                            'quantity' => min($needed, $available),
                        ];
                        break;
                    }
                }
            }
        }

        KamsStockAssignment::query()
            ->with('member:id,full_name')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'open')
            ->where('assigned_at', '<=', now()->subDays(7))
            ->limit(5)
            ->get()
            ->each(function (KamsStockAssignment $row) use (&$rows) {
                $rows[] = [
                    'key' => 'settleAssignment',
                    'agent' => $row->member?->full_name,
                ];
            });

        $lossy = KamsStockAssignmentItem::query()
            ->selectRaw('product_id, SUM(quantity_lost) as lost, SUM(quantity_out) as outed')
            ->whereHas('assignment', fn ($q) => $q->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))->where('status', 'settled'))
            ->groupBy('product_id')
            ->havingRaw('SUM(quantity_out) > 0')
            ->get()
            ->filter(function ($row) {
                $threshold = max(0, SettingsCatalog::number('kams', 'stock_loss_alert_percent')) / 100;

                return ($row->lost / max(1, $row->outed)) >= $threshold;
            })
            ->take(3);

        foreach ($lossy as $row) {
            $product = KamsProduct::query()->find($row->product_id);
            $rows[] = ['key' => 'investigateLoss', 'product' => $product?->name];
        }

        return array_slice($rows, 0, 8);
    }

    /**
     * @return array{0: Carbon, 1: array<string, array<string, mixed>>}
     */
    private function buildBuckets(Carbon $end, int $count, string $groupBy): array
    {
        $series = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            [$from, $to] = match ($groupBy) {
                'day' => [$end->copy()->subDays($i)->startOfDay(), $end->copy()->subDays($i)->endOfDay()],
                'week' => [
                    $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($i),
                    $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($i)->endOfWeek(Carbon::SUNDAY),
                ],
                default => [
                    $end->copy()->startOfMonth()->subMonths($i),
                    $end->copy()->startOfMonth()->subMonths($i)->endOfMonth(),
                ],
            };
            if ($to->gt($end)) {
                $to = $end->copy();
            }
            $key = $this->bucketKey($from, $groupBy);
            $series[$key] = [
                'key' => $key,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'transfers' => 0,
                'assignments' => 0,
                'sales' => 0,
                'settlements' => 0,
                'cash' => 0,
                'profit' => 0,
                'loss' => 0,
            ];
        }

        $start = Carbon::parse(reset($series)['from'])->startOfDay();

        return [$start, $series];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function seriesBetween(Carbon $start, Carbon $end, string $mode): array
    {
        $series = [];
        $cursor = $start->copy()->startOfDay();
        $limit = $end->copy()->startOfDay();

        while ($cursor->lte($limit)) {
            if ($mode === 'month') {
                $bucketFrom = $cursor->copy()->startOfMonth()->lt($start) ? $start->copy() : $cursor->copy()->startOfMonth();
                $bucketTo = $cursor->copy()->endOfMonth()->startOfDay();
                $cursor = $cursor->copy()->addMonth()->startOfMonth();
            } elseif ($mode === 'week') {
                $weekStart = $cursor->copy()->startOfWeek(Carbon::MONDAY);
                $bucketFrom = $weekStart->lt($start) ? $start->copy() : $weekStart;
                $bucketTo = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
                $cursor = $bucketTo->copy()->addDay();
            } else {
                $bucketFrom = $cursor->copy();
                $bucketTo = $cursor->copy();
                $cursor = $cursor->copy()->addDay();
            }
            if ($bucketTo->gt($limit)) {
                $bucketTo = $limit->copy();
            }
            $key = $this->bucketKey($bucketFrom, $mode);
            $series[$key] = [
                'key' => $key,
                'from' => $bucketFrom->toDateString(),
                'to' => $bucketTo->toDateString(),
                'transfers' => 0,
                'assignments' => 0,
                'sales' => 0,
                'settlements' => 0,
                'cash' => 0,
                'profit' => 0,
                'loss' => 0,
            ];
        }

        return $series;
    }

    /**
     * @param  array<string, array<string, mixed>>  $series
     */
    private function bucketFor(mixed $date, string $groupBy, array $series): ?string
    {
        if (! $date) {
            return null;
        }
        $key = $this->bucketKey(Carbon::parse($date), $groupBy);

        return array_key_exists($key, $series) ? $key : null;
    }

    private function bucketKey(Carbon $date, string $groupBy): string
    {
        return match ($groupBy) {
            'day' => $date->toDateString(),
            'week' => $date->copy()->startOfWeek(Carbon::MONDAY)->format('o-\WW'),
            default => $date->format('Y-m'),
        };
    }
}
