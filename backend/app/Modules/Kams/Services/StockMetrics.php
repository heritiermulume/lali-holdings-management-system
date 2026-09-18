<?php

namespace App\Modules\Kams\Services;

use App\Models\Office;
use App\Models\Setting;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAssignment;
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
    public function __construct(private readonly StockInventory $inventory) {}

    /**
     * @return array<string, mixed>
     */
    public function recap(string $groupBy = 'week', ?int $companyId = null): array
    {
        $groupBy = in_array($groupBy, ['day', 'week', 'month'], true) ? $groupBy : 'week';
        $end = now()->endOfDay();
        [$start, $series] = match ($groupBy) {
            'day' => $this->buildBuckets($end, 14, 'day'),
            'week' => $this->buildBuckets($end, 8, 'week'),
            'month' => $this->buildBuckets($end, 12, 'month'),
        };

        if ($companyId) {
            $this->inventory->ensureOfficeDepots($companyId);
        }

        $threshold = (int) Setting::getValue('kams', 'stock_alert_threshold', 5);
        $products = KamsProduct::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $balances = KamsStockBalance::query()
            ->with(['depot:id,name,kind,office_id', 'product:id,name,sku,price,cost,avg_cost,stock'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $available = (clone $balances)->where('condition', 'available');
        $assignments = KamsStockAssignment::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $sales = KamsStockSale::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('sold_at', [$start, $end])
            ->get(['sold_at', 'paid_amount', 'margin', 'subtotal']);
        $opened = (clone $assignments)->whereBetween('assigned_at', [$start, $end])->get(['assigned_at']);
        $transfers = KamsStockTransfer::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('transferred_at', [$start, $end])
            ->get(['transferred_at']);

        foreach ($sales as $row) {
            $bucket = $this->bucketFor($row->sold_at, $groupBy, $series);
            if ($bucket === null) {
                continue;
            }
            $series[$bucket]['cash'] += (float) $row->paid_amount;
            $series[$bucket]['profit'] += (float) $row->margin;
            $series[$bucket]['sales']++;
        }
        foreach ($opened as $row) {
            $bucket = $this->bucketFor($row->assigned_at, $groupBy, $series);
            if ($bucket) {
                $series[$bucket]['assignments']++;
            }
        }
        foreach ($transfers as $row) {
            $bucket = $this->bucketFor($row->transferred_at, $groupBy, $series);
            if ($bucket) {
                $series[$bucket]['transfers']++;
            }
        }

        $warehouseUnits = (int) (clone $available)->whereHas('depot', fn ($q) => $q->where('kind', 'warehouse'))->sum('quantity');
        $depotUnits = (int) (clone $available)->whereHas('depot', fn ($q) => $q->where('kind', 'depot'))->sum('quantity');
        $withAgents = (int) (clone $available)->whereHas('depot', fn ($q) => $q->where('kind', 'seller'))->sum('quantity');
        $inTransit = (int) (clone $balances)->where('condition', 'in_transit')->sum('quantity');
        $quarantine = (int) (clone $balances)->whereIn('condition', ['quarantine', 'damaged'])->sum('quantity');
        $inventoryValue = (float) KamsStockBalance::query()
            ->when($companyId, fn ($q) => $q->where('kams_stock_balances.company_id', $companyId))
            ->join('kams_products', 'kams_products.id', '=', 'kams_stock_balances.product_id')
            ->selectRaw('COALESCE(SUM(kams_stock_balances.quantity * COALESCE(NULLIF(kams_products.company_price, 0), NULLIF(kams_products.avg_cost, 0), kams_products.cost)), 0) as value')
            ->value('value');
        $periodSales = KamsStockSale::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('sold_at', [$start, $end]);
        $soldUnits = (int) KamsStockSaleItem::query()
            ->whereHas('sale', fn ($q) => $q->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))->whereBetween('sold_at', [$start, $end]))
            ->sum('quantity');
        $returnedSold = (int) KamsStockReturnItem::query()
            ->whereHas('stockReturn', fn ($q) => $q
                ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                ->where('type', 'customer')
                ->whereIn('status', ['restocked', 'quarantine'])
                ->whereBetween('validated_at', [$start, $end]))
            ->sum('quantity');
        $soldUnits = max(0, $soldUnits - $returnedSold);
        $returnedMargin = (float) KamsStockReturnItem::query()
            ->whereHas('stockReturn', fn ($q) => $q
                ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                ->where('type', 'customer')
                ->whereIn('status', ['restocked', 'quarantine'])
                ->whereBetween('validated_at', [$start, $end]))
            ->selectRaw('COALESCE(SUM(quantity * (unit_price - unit_cost)), 0) as value')
            ->value('value');
        $commissionsPayable = (float) KamsSellerCommission::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'payable')
            ->sum('payable_amount');
        $activeSellers = KamsDepot::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('kind', 'seller')
            ->whereHas('balances', fn ($q) => $q->where('condition', 'available')->where('quantity', '>', 0))
            ->count();

        $lowLocations = (clone $available)
            ->where('quantity', '<=', $threshold)
            ->where('quantity', '>', 0)
            ->orderBy('quantity')
            ->limit(8)
            ->get();

        $officesMissingDepot = 0;
        if ($companyId) {
            $offices = Office::query()->where('company_id', $companyId)->where('is_active', true)->pluck('id');
            $covered = KamsDepot::query()->where('company_id', $companyId)->where('kind', 'depot')->whereIn('office_id', $offices)->pluck('office_id');
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
                'units' => $warehouseUnits + $depotUnits + $withAgents,
                'inventory_value' => round($inventoryValue, 2),
                'open_assignments' => (clone $assignments)->where('status', 'open')->count(),
                'sold_units' => $soldUnits,
                'active_sellers' => $activeSellers,
                'commissions_payable' => round($commissionsPayable, 2),
                'guy_savings' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'guy')->sum('balance'), 2),
                'manager_savings' => round((float) KamsSavingsAccount::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('kind', 'manager')->sum('balance'), 2),
                'cash' => round((float) (clone $periodSales)->sum('paid_amount'), 2),
                'profit' => round(max(0, (float) (clone $periodSales)->sum('margin') - $returnedMargin), 2),
                'loss' => 0,
                'expected' => round((float) (clone $periodSales)->sum('subtotal'), 2),
            ],
            'series' => array_values($series),
            'alerts' => $this->alerts($companyId, $threshold, $officesMissingDepot, $lowLocations),
            'recommendations' => $this->recommendations($companyId, $threshold),
            'low_stock' => $lowLocations->map(fn (KamsStockBalance $row) => [
                'product' => $row->product?->name,
                'sku' => $row->product?->sku,
                'location' => $row->depot?->name,
                'kind' => $row->depot?->kind,
                'quantity' => $row->quantity,
            ])->values()->all(),
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
            $value = (float) KamsStockBalance::query()
                ->whereIn('depot_id', KamsDepot::query()->where(function ($q) use ($depot, $office) {
                    $q->whereKey($depot->id)->orWhere(fn ($inner) => $inner->where('kind', 'seller')->where('office_id', $office->id));
                })->pluck('id'))
                ->join('kams_products', 'kams_products.id', '=', 'kams_stock_balances.product_id')
                ->where('kams_stock_balances.condition', 'available')
                ->selectRaw('COALESCE(SUM(kams_stock_balances.quantity * COALESCE(NULLIF(kams_products.company_price, 0), kams_products.cost)), 0) as value')
                ->value('value');

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
    public function finance(string $groupBy = 'week', ?int $companyId = null, array $officeIds = []): array
    {
        $data = $this->recap($groupBy, $companyId);
        $start = $data['range']['from'];
        $end = $data['range']['to'];

        $offices = Office::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('id', $officeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $officeRows = $offices->map(fn (Office $office) => $this->officeFinance($office, $start, $end))->values()->all();

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
            $data['totals']['commissions_payable'] = round(array_sum(array_column($officeRows, 'commissions_payable')), 2);
            $data['totals']['inventory_value'] = round(array_sum(array_column($officeRows, 'inventory_value')), 2);
            $data['series'] = $this->officeSeries($data['series'], $groupBy, $companyId, $officeIds, $start, $end);
        }

        $data['totals']['company_share'] = round($companyShare, 2);
        $data['offices'] = $officeRows;

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

        return $offices->map(fn (Office $office) => $this->officeFinance($office, $from, $to))->values()->all();
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
     * @return array<string, mixed>
     */
    private function officeFinance(Office $office, string $from, string $to): array
    {
        $sales = KamsStockSale::query()
            ->whereHas('member', fn ($m) => $m->where('office_id', $office->id))
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $memberIds = KamsMember::query()->where('office_id', $office->id)->pluck('id');
        $depotIds = KamsDepot::query()->where('office_id', $office->id)->pluck('id');
        $inventory = $depotIds->isEmpty()
            ? 0
            : (float) KamsStockBalance::query()
                ->whereIn('depot_id', $depotIds)
                ->where('condition', 'available')
                ->join('kams_products', 'kams_products.id', '=', 'kams_stock_balances.product_id')
                ->selectRaw('COALESCE(SUM(kams_stock_balances.quantity * COALESCE(NULLIF(kams_products.company_price, 0), NULLIF(kams_products.avg_cost, 0), kams_products.cost)), 0) as value')
                ->value('value');

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
            'manager_savings' => round((float) KamsSavingsAccount::query()->whereIn('member_id', $memberIds->all() ?: [0])->where('kind', 'manager')->sum('balance'), 2),
            'commissions_payable' => round((float) KamsSellerCommission::query()->whereIn('member_id', $memberIds->all() ?: [0])->where('status', 'payable')->sum('payable_amount'), 2),
            'inventory_value' => round($inventory, 2),
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
        $payableCommissions = KamsSellerCommission::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'payable')
            ->count();
        $emptyWarehouse = KamsProduct::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'active')
            ->whereDoesntHave('balances', fn ($q) => $q->where('quantity', '>', 0)->whereHas('depot', fn ($d) => $d->where('kind', 'warehouse')))
            ->count();

        return array_values(array_filter([
            ['tone' => 'amber', 'key' => 'lowStockLocations', 'count' => $lowLocations->count()],
            ['tone' => 'red', 'key' => 'overdueAssignments', 'count' => $openOld],
            ['tone' => 'amber', 'key' => 'inTransitTransfers', 'count' => $inTransitTransfers],
            ['tone' => 'red', 'key' => 'quarantineUnits', 'count' => $quarantineUnits],
            ['tone' => 'amber', 'key' => 'payableCommissions', 'count' => $payableCommissions],
            ['tone' => 'red', 'key' => 'officesWithoutDepot', 'count' => $officesMissingDepot],
            ['tone' => 'amber', 'key' => 'emptyWarehouseProducts', 'count' => $emptyWarehouse],
            ['tone' => 'slate', 'key' => 'stockAlertThreshold', 'count' => $threshold],
        ], fn ($row) => $row['key'] === 'stockAlertThreshold' || $row['count'] > 0));
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
                $needed = max($threshold + 5 - $balance->quantity, 5);
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
            ->filter(fn ($row) => ($row->lost / max(1, $row->outed)) >= 0.1)
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
