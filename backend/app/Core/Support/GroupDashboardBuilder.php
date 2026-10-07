<?php

namespace App\Core\Support;

use App\Models\AppNotification;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Office;
use App\Models\SystemModule;
use App\Models\Task;
use App\Models\User;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockSaleItem;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Modules\Kams\Services\KamsFinanceService;
use App\Modules\Kams\Services\RecruitmentMetrics;
use App\Modules\Kams\Services\RevenueShareService;
use App\Modules\Kams\Services\StockMetrics;
use App\Support\SettingsCatalog;
use Illuminate\Support\Carbon;

class GroupDashboardBuilder
{
    public function __construct(
        private readonly KamsFinanceService $finance,
        private readonly RevenueShareService $revenueShares,
        private readonly RecruitmentMetrics $recruitment,
        private readonly StockMetrics $stock,
        private readonly CurrencyConverter $fx,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $groupBy = 'week', ?string $from = null, ?string $to = null, ?int $companyId = null, ?int $officeId = null): array
    {
        $groupBy = in_array($groupBy, ['day', 'week', 'month', 'custom'], true) ? $groupBy : 'week';
        $range = $this->resolveRange($groupBy, $from, $to);
        $previous = $this->previousRange($range['from'], $range['to']);
        $currency = $this->fx->defaultCode();

        $accessibleCompanyIds = $user->accessibleCompanyIds() ?: [];
        $scopedOfficeIds = ! $user->isGroupAdmin() && $user->isOfficeScoped()
            ? ($user->accessibleOfficeIds() ?: [0])
            : [];

        if ($companyId !== null) {
            abort_unless(in_array($companyId, $accessibleCompanyIds, true), 403);
        }
        if ($officeId !== null) {
            $office = Office::query()->findOrFail($officeId);
            abort_unless(in_array((int) $office->company_id, $accessibleCompanyIds, true), 403);
            if ($scopedOfficeIds !== []) {
                abort_unless(in_array($officeId, $scopedOfficeIds, true), 403);
            }
            $companyId = (int) $office->company_id;
        }

        $companyIds = $companyId !== null
            ? [$companyId]
            : ($accessibleCompanyIds ?: [0]);

        $companies = Company::query()
            ->whereIn('id', $accessibleCompanyIds ?: [0])
            ->with(['module:id,slug,name,name_fr', 'country:id,name,name_fr'])
            ->withCount(['offices' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $filterOffices = $companyId !== null
            ? Office::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->when($scopedOfficeIds !== [], fn ($q) => $q->whereIn('id', $scopedOfficeIds))
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Office $office) => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'code' => $office->code,
                ])->values()->all()
            : [];

        // Single accessible office: lock scope and hide the office selector on the client.
        if ($officeId === null && count($filterOffices) === 1) {
            $officeId = (int) $filterOffices[0]['id'];
        }

        $officeIds = $officeId !== null
            ? [$officeId]
            : $scopedOfficeIds;

        $canSeeFinance = $user->hasPermission('administration.view')
            || $user->hasPermission('kams.finance.dashboard.view');
        $canSeeKams = $canSeeFinance;
        $canSeeRecruitment = $user->hasPermission('kams.recruitment.applications.view')
            || $user->hasPermission('kams.recruitment.reports.view')
            || $user->hasPermission('kams.dashboard.recruitment.view')
            || $user->hasPermission('administration.view');

        $kamsCompanies = $companies->filter(fn (Company $company) => $company->module?->slug === 'kams');
        $scopedKams = $kamsCompanies->whereIn('id', $companyIds);

        $finances = $canSeeFinance
            ? $this->consolidateFinances($scopedKams, $range['from'], $range['to'], $officeIds, $currency)
            : null;
        $previousFinances = $canSeeFinance
            ? $this->consolidateFinances($scopedKams, $previous['from'], $previous['to'], $officeIds, $currency)
            : null;

        $operations = $this->operations($scopedKams, $range['from'], $range['to'], $officeIds, $currency);
        $previousOperations = $this->operations($scopedKams, $previous['from'], $previous['to'], $officeIds, $currency);

        $compensation = $canSeeKams
            ? $this->compensation($scopedKams, $range['from'], $range['to'], $officeIds, $currency)
            : null;
        $savings = $canSeeKams
            ? $this->savings($scopedKams, $officeIds, $currency)
            : null;

        $recruitment = $canSeeRecruitment
            ? $this->recruitmentTotals($scopedKams, $range['from'], $range['to'], $officeIds)
            : null;

        $performance = $canSeeFinance
            ? $this->companyPerformance($companies, $range['from'], $range['to'], $previous['from'], $previous['to'], $scopedOfficeIds, $currency)
            : $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'module_slug' => $company->module?->slug,
                'module_name' => $company->module?->name,
                'module_name_fr' => $company->module?->name_fr,
                'country' => $company->country?->name,
                'offices_count' => $company->offices_count,
                'revenue' => 0,
                'profit' => 0,
                'cash' => 0,
                'receivables' => 0,
                'payables' => 0,
                'agents' => 0,
                'clients' => 0,
                'sales_count' => 0,
                'delta_revenue' => null,
                'delta_profit' => null,
            ])->values()->all();

        $offices = null;
        if ($companyId !== null && $canSeeFinance) {
            $company = $companies->firstWhere('id', $companyId);
            $offices = $company?->module?->slug === 'kams'
                ? $this->officePerformance($company, $range['from'], $range['to'], $officeIds, $currency)
                : Office::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->when($scopedOfficeIds !== [], fn ($q) => $q->whereIn('id', $scopedOfficeIds))
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->map(fn (Office $office) => [
                        'id' => $office->id,
                        'name' => $office->name,
                        'code' => $office->code,
                        'revenue' => 0,
                        'profit' => 0,
                        'cash' => 0,
                        'sales_count' => 0,
                        'agents' => 0,
                        'inventory_value' => 0,
                        'commissions_payable' => 0,
                        'compensation' => 0,
                    ])->all();
        }

        $alerts = $this->alerts($user, $scopedKams, $finances, $operations, $compensation, $officeIds);

        return [
            'welcome' => [
                'name' => $user->name,
                'role' => $user->roles->first()?->name,
                'company' => $user->primaryCompany?->name,
            ],
            'scope' => [
                'level' => $officeId !== null ? 'office' : ($companyId !== null ? 'company' : 'group'),
                'company_id' => $companyId,
                'office_id' => $officeId,
                'companies' => $companies->map(fn (Company $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'module_slug' => $company->module?->slug,
                ])->values()->all(),
                'offices' => $filterOffices,
            ],
            'group_by' => $from && $to ? 'custom' : $groupBy,
            'range' => $range,
            'previous_range' => $previous,
            'currency' => $currency,
            'stats' => [
                'countries' => Country::query()->where('is_active', true)->count(),
                'cities' => City::query()->count(),
                'companies' => $companies->count(),
                'offices' => Office::query()
                    ->whereIn('company_id', $accessibleCompanyIds ?: [0])
                    ->where('is_active', true)
                    ->when($scopedOfficeIds !== [], fn ($q) => $q->whereIn('id', $scopedOfficeIds))
                    ->count(),
                'users' => User::query()->count(),
                'active_modules' => SystemModule::query()->where('status', 'active')->where('is_core', false)->count(),
                'agents' => $operations['agents'],
                'clients' => $operations['clients'],
                'products' => $operations['products'],
                'sales_count' => $operations['sales_count'],
            ],
            'finances' => $finances,
            'finances_previous' => $previousFinances,
            'finances_delta' => $this->deltaMap($finances, $previousFinances),
            'operations' => $operations,
            'operations_previous' => $previousOperations,
            'operations_delta' => $this->deltaMap($operations, $previousOperations, ['agents', 'clients', 'products', 'inventory_units', 'inventory_value', 'commissions_payable', 'sales_count', 'sold_units']),
            'kams_compensation' => $compensation,
            'kams_savings' => $savings,
            'recruitment' => $recruitment,
            'modules' => SystemModule::query()->orderBy('sort_order')->get(),
            'notifications' => AppNotification::query()
                ->where('user_id', $user->id)
                ->latest()
                ->limit(8)
                ->get(),
            'tasks' => Task::query()
                ->where('user_id', $user->id)
                ->where('status', '!=', 'completed')
                ->orderBy('due_date')
                ->limit(8)
                ->get(),
            'activity' => [],
            'performance' => $performance,
            'offices_performance' => $offices,
            'alerts' => $alerts,
            'presence' => $this->presence($accessibleCompanyIds),
            'trend' => $canSeeFinance
                ? $this->trend($scopedKams, $range['from'], $range['to'], $officeIds, $currency)
                : [],
            'comparison' => $canSeeFinance
                ? collect($performance)
                    ->sortByDesc('revenue')
                    ->take(8)
                    ->map(fn (array $row) => [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'revenue' => $row['revenue'],
                        'profit' => $row['profit'],
                        'cash' => $row['cash'],
                    ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $officeIds
     * @return array<string, float>
     */
    private function consolidateFinances($companies, string $from, string $to, array $officeIds, string $currency): array
    {
        $totals = [
            'revenue' => 0.0,
            'expenses' => 0.0,
            'profit' => 0.0,
            'cash' => 0.0,
            'assets' => 0.0,
            'liabilities' => 0.0,
            'receivables' => 0.0,
            'payables' => 0.0,
            'patrimoine_value' => 0.0,
        ];

        foreach ($companies as $company) {
            if ($company->module?->slug !== 'kams') {
                continue;
            }
            $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));
            $summary = $this->fx->convertBag(
                $this->finance->summarize((int) $company->id, $from, $to, $officeIds),
                $fromCurrency,
                $currency,
            );
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($summary[$key] ?? 0), 2);
            }
        }

        return $totals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $officeIds
     * @return array<string, float|int>
     */
    private function operations($companies, string $from, string $to, array $officeIds, string $currency): array
    {
        $companyIds = $companies->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0];
        $agents = KamsMember::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', ['active', 'verified'])
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->count();
        $clients = KamsClient::query()
            ->whereIn('company_id', $companyIds)
            ->count();
        $products = KamsProduct::query()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'active')
            ->count();
        $salesQuery = KamsStockSale::query()
            ->whereIn('company_id', $companyIds)
            ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds)))
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $soldUnits = (int) KamsStockSaleItem::query()
            ->whereHas('sale', function ($sale) use ($companyIds, $officeIds, $from, $to) {
                $sale->whereIn('company_id', $companyIds)
                    ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds)))
                    ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
            })
            ->sum('quantity');
        $inventoryUnits = (int) KamsStockBalance::query()
            ->whereIn('company_id', $companyIds)
            ->where('condition', 'available')
            ->when($officeIds !== [], fn ($q) => $q->whereHas('depot', fn ($d) => $d->whereIn('office_id', $officeIds)))
            ->sum('quantity');
        $inventoryValue = 0.0;
        $commissions = 0.0;
        foreach ($companies as $company) {
            if ($company->module?->slug !== 'kams') {
                continue;
            }
            $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));
            $value = (float) KamsStockBalance::query()
                ->where('kams_stock_balances.company_id', $company->id)
                ->where('condition', 'available')
                ->when($officeIds !== [], fn ($q) => $q->whereHas('depot', fn ($d) => $d->whereIn('office_id', $officeIds)))
                ->join('kams_products', 'kams_products.id', '=', 'kams_stock_balances.product_id')
                ->selectRaw('COALESCE(SUM(kams_stock_balances.quantity * '.KamsProduct::inventoryUnitCostSql().'), 0) as value')
                ->value('value');
            $payable = (float) KamsSellerCommission::query()
                ->where('company_id', $company->id)
                ->where('status', 'payable')
                ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds)))
                ->sum('payable_amount');
            $inventoryValue += $this->fx->convert($value, $fromCurrency, $currency);
            $commissions += $this->fx->convert($payable, $fromCurrency, $currency);
        }

        return [
            'agents' => $agents,
            'clients' => $clients,
            'products' => $products,
            'sales_count' => (clone $salesQuery)->count(),
            'sold_units' => $soldUnits,
            'inventory_units' => $inventoryUnits,
            'inventory_value' => round($inventoryValue, 2),
            'commissions_payable' => round($commissions, 2),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $officeIds
     * @return array<string, mixed>
     */
    private function compensation($companies, string $from, string $to, array $officeIds, string $currency): array
    {
        $totals = [
            'generated' => 0.0,
            'posted' => 0.0,
            'remaining' => 0.0,
            'manager_sale' => 0.0,
            'assistant_share' => 0.0,
            'manager_office' => 0.0,
            'divisionnaire_office' => 0.0,
            'secretary_office' => 0.0,
            'salary_paid' => 0.0,
        ];

        foreach ($companies as $company) {
            $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));
            $row = $this->revenueShares->dashboardTotals((int) $company->id, $officeIds, $from, $to);
            $converted = $this->fx->convertBag([
                'generated' => (float) $row['generated'],
                'posted' => (float) $row['posted'],
                'remaining' => (float) $row['remaining'],
                'manager_sale' => (float) $row['manager_sale'],
                'assistant_share' => (float) $row['assistant_share'],
                'manager_office' => (float) (($row['manager_office'] ?? 0) + ($row['manager_descendant_office'] ?? 0)),
                'divisionnaire_office' => (float) $row['divisionnaire_office'],
                'secretary_office' => (float) $row['secretary_office'],
                'salary_paid' => (float) $row['salary_paid'],
            ], $fromCurrency, $currency);
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($converted[$key] ?? 0), 2);
            }
        }

        $totals['range'] = ['from' => $from, 'to' => $to];

        return $totals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $officeIds
     * @return array<string, float>
     */
    private function savings($companies, array $officeIds, string $currency): array
    {
        $totals = [
            'guy_savings' => 0.0,
            'assistant_manager_savings' => 0.0,
            'manager_savings' => 0.0,
            'divisionnaire_savings' => 0.0,
        ];

        foreach ($companies as $company) {
            $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));
            $query = KamsSavingsAccount::query()
                ->where('company_id', $company->id)
                ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds)));
            $row = [
                'guy_savings' => (float) (clone $query)->where('kind', 'guy')->sum('balance'),
                'assistant_manager_savings' => (float) (clone $query)->where('kind', 'assistant_manager')->sum('balance'),
                'manager_savings' => (float) (clone $query)->where('kind', 'manager')->sum('balance'),
                'divisionnaire_savings' => (float) (clone $query)->where('kind', 'divisionnaire')->sum('balance'),
            ];
            $converted = $this->fx->convertBag($row, $fromCurrency, $currency);
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($converted[$key] ?? 0), 2);
            }
        }

        return $totals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $officeIds
     * @return array<string, float|int>
     */
    private function recruitmentTotals($companies, string $from, string $to, array $officeIds): array
    {
        $totals = [
            'applications' => 0,
            'interviews' => 0,
            'observations' => 0,
            'approved' => 0,
            'rejected' => 0,
            'conversion_rate' => 0.0,
        ];

        foreach ($companies as $company) {
            $row = $this->recruitment->summarize((int) $company->id, $officeIds ?: null, $from, $to)['recruitment'];
            $totals['applications'] += (int) ($row['applications'] ?? 0);
            $totals['interviews'] += (int) ($row['interviews'] ?? 0);
            $totals['observations'] += (int) ($row['observations'] ?? 0);
            $totals['approved'] += (int) ($row['approved'] ?? 0);
            $totals['rejected'] += (int) ($row['rejected'] ?? 0);
        }

        $totals['conversion_rate'] = $totals['applications'] > 0
            ? round(($totals['approved'] / $totals['applications']) * 100, 1)
            : 0.0;

        return $totals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $scopedOfficeIds
     * @return list<array<string, mixed>>
     */
    private function companyPerformance($companies, string $from, string $to, string $prevFrom, string $prevTo, array $scopedOfficeIds, string $currency): array
    {
        return $companies->map(function (Company $company) use ($from, $to, $prevFrom, $prevTo, $scopedOfficeIds, $currency) {
            $isKams = $company->module?->slug === 'kams';
            $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));
            $finance = $isKams
                ? $this->fx->convertBag($this->finance->summarize((int) $company->id, $from, $to, $scopedOfficeIds), $fromCurrency, $currency)
                : ['revenue' => 0, 'profit' => 0, 'cash' => 0, 'receivables' => 0, 'payables' => 0];
            $previous = $isKams
                ? $this->fx->convertBag($this->finance->summarize((int) $company->id, $prevFrom, $prevTo, $scopedOfficeIds), $fromCurrency, $currency)
                : ['revenue' => 0, 'profit' => 0];
            $salesCount = $isKams
                ? KamsStockSale::query()
                    ->where('company_id', $company->id)
                    ->when($scopedOfficeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $scopedOfficeIds)))
                    ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59'])
                    ->count()
                : 0;

            return [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'module_slug' => $company->module?->slug,
                'module_name' => $company->module?->name,
                'module_name_fr' => $company->module?->name_fr,
                'country' => $company->country?->name,
                'offices_count' => $company->offices_count,
                'revenue' => round((float) ($finance['revenue'] ?? 0), 2),
                'profit' => round((float) ($finance['profit'] ?? 0), 2),
                'cash' => round((float) ($finance['cash'] ?? 0), 2),
                'receivables' => round((float) ($finance['receivables'] ?? 0), 2),
                'payables' => round((float) ($finance['payables'] ?? 0), 2),
                'agents' => $isKams
                    ? KamsMember::query()
                        ->where('company_id', $company->id)
                        ->whereIn('status', ['active', 'verified'])
                        ->when($scopedOfficeIds !== [], fn ($q) => $q->whereIn('office_id', $scopedOfficeIds))
                        ->count()
                    : 0,
                'clients' => $isKams ? KamsClient::query()->where('company_id', $company->id)->count() : 0,
                'sales_count' => $salesCount,
                'delta_revenue' => $this->deltaPercent((float) ($finance['revenue'] ?? 0), (float) ($previous['revenue'] ?? 0)),
                'delta_profit' => $this->deltaPercent((float) ($finance['profit'] ?? 0), (float) ($previous['profit'] ?? 0)),
            ];
        })->sortByDesc('revenue')->values()->all();
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    private function officePerformance(Company $company, string $from, string $to, array $officeIds, string $currency): array
    {
        $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));

        return collect($this->stock->officesForPeriod((int) $company->id, $officeIds, $from, $to))
            ->map(function (array $row) use ($fromCurrency, $currency) {
                $converted = $this->fx->convertBag([
                    'revenue' => (float) ($row['expected'] ?? 0),
                    'profit' => (float) ($row['profit'] ?? 0),
                    'cash' => (float) ($row['cash'] ?? 0),
                    'inventory_value' => (float) ($row['inventory_value'] ?? 0),
                    'commissions_payable' => (float) ($row['commissions_payable'] ?? 0),
                    'compensation' => (float) ($row['compensation_generated'] ?? 0),
                ], $fromCurrency, $currency);

                return [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'code' => $row['code'] ?? '',
                    'revenue' => $converted['revenue'],
                    'profit' => $converted['profit'],
                    'cash' => $converted['cash'],
                    'sales_count' => (int) ($row['sales_count'] ?? 0),
                    'agents' => (int) ($row['agents'] ?? 0),
                    'inventory_value' => $converted['inventory_value'],
                    'commissions_payable' => $converted['commissions_payable'],
                    'compensation' => $converted['compensation'],
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  list<int>  $officeIds
     * @return list<array{label: string, from: string, to: string, revenue: float, expenses: float, profit: float}>
     */
    private function trend($companies, string $from, string $to, array $officeIds, string $currency): array
    {
        $merged = [];

        foreach ($companies as $company) {
            if ($company->module?->slug !== 'kams') {
                continue;
            }
            $fromCurrency = strtoupper((string) ($company->currency_code ?: $currency));
            foreach ($this->finance->trend((int) $company->id, $from, $to, $officeIds) as $point) {
                $key = $point['from'].'|'.$point['to'];
                if (! isset($merged[$key])) {
                    $merged[$key] = [
                        'label' => $this->trendLabel($point['from'], $point['to']),
                        'from' => $point['from'],
                        'to' => $point['to'],
                        'revenue' => 0.0,
                        'expenses' => 0.0,
                        'profit' => 0.0,
                    ];
                }
                $revenue = $this->fx->convert((float) $point['revenue'], $fromCurrency, $currency);
                $expenses = $this->fx->convert((float) $point['expenses'], $fromCurrency, $currency);
                $merged[$key]['revenue'] = round($merged[$key]['revenue'] + $revenue, 2);
                $merged[$key]['expenses'] = round($merged[$key]['expenses'] + $expenses, 2);
                $merged[$key]['profit'] = round($merged[$key]['revenue'] - $merged[$key]['expenses'], 2);
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  array<string, float>|null  $finances
     * @param  array<string, float|int>  $operations
     * @param  array<string, mixed>|null  $compensation
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    private function alerts(User $user, $companies, ?array $finances, array $operations, ?array $compensation, array $officeIds): array
    {
        $items = [];

        if (($finances['receivables'] ?? 0) > 0) {
            $items[] = [
                'tone' => 'amber',
                'key' => 'receivables',
                'count' => 1,
                'value' => $finances['receivables'],
            ];
        }
        if (($finances['payables'] ?? 0) > 0) {
            $items[] = [
                'tone' => 'red',
                'key' => 'payables',
                'count' => 1,
                'value' => $finances['payables'],
            ];
        }
        if (($operations['commissions_payable'] ?? 0) > 0) {
            $items[] = [
                'tone' => 'amber',
                'key' => 'commissionsPayable',
                'count' => 1,
                'value' => $operations['commissions_payable'],
            ];
        }
        if (($compensation['remaining'] ?? 0) > 0) {
            $items[] = [
                'tone' => 'amber',
                'key' => 'compensationRemaining',
                'count' => 1,
                'value' => $compensation['remaining'],
            ];
        }

        $companyIds = $companies->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0];
        $threshold = (int) SettingsCatalog::number('kams', 'stock_alert_threshold');
        $lowStock = SettingsCatalog::bool('kams', 'low_stock_notify')
            ? KamsStockBalance::query()
                ->whereIn('company_id', $companyIds)
                ->where('condition', 'available')
                ->where('quantity', '>', 0)
                ->where('quantity', '<=', $threshold)
                ->whereHas('depot', fn ($q) => $q->where('kind', 'depot')->when($officeIds !== [], fn ($d) => $d->whereIn('office_id', $officeIds)))
                ->count()
            : 0;
        if ($lowStock > 0) {
            $items[] = ['tone' => 'amber', 'key' => 'lowStockLocations', 'count' => $lowStock];
        }
        $overdue = KamsStockAssignment::query()
            ->whereIn('company_id', $companyIds)
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->where('status', 'open')
            ->where('assigned_at', '<=', now()->subDays(7))
            ->count();
        if ($overdue > 0) {
            $items[] = ['tone' => 'red', 'key' => 'overdueAssignments', 'count' => $overdue];
        }
        $inTransit = KamsStockTransfer::query()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'in_transit')
            ->count();
        if ($inTransit > 0) {
            $items[] = ['tone' => 'amber', 'key' => 'inTransitTransfers', 'count' => $inTransit];
        }
        $quarantine = (int) KamsStockBalance::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('condition', ['quarantine', 'damaged'])
            ->when($officeIds !== [], fn ($q) => $q->whereHas('depot', fn ($d) => $d->whereIn('office_id', $officeIds)))
            ->sum('quantity');
        if ($quarantine > 0) {
            $items[] = ['tone' => 'red', 'key' => 'quarantineUnits', 'count' => $quarantine];
        }

        $unread = AppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
        if ($unread > 0) {
            $items[] = [
                'tone' => 'slate',
                'key' => 'unreadNotifications',
                'count' => $unread,
            ];
        }

        $openTasks = Task::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'completed')
            ->count();
        if ($openTasks > 0) {
            $items[] = [
                'tone' => 'slate',
                'key' => 'openTasks',
                'count' => $openTasks,
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function presence(array $companyIds): array
    {
        return Country::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withCount([
                'companies' => fn ($q) => $q->whereIn('id', $companyIds ?: [0]),
                'offices' => fn ($q) => $q->whereIn('company_id', $companyIds ?: [0]),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (Country $country) => $country->companies_count > 0 || $country->offices_count > 0)
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'name' => $country->name,
                'name_fr' => $country->name_fr,
                'iso_code' => $country->iso_code,
                'latitude' => $country->latitude,
                'longitude' => $country->longitude,
                'companies_count' => $country->companies_count,
                'offices_count' => $country->offices_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, float|int>|null  $current
     * @param  array<string, float|int>|null  $previous
     * @param  list<string>|null  $keys
     * @return array<string, float|null>
     */
    private function deltaMap(?array $current, ?array $previous, ?array $keys = null): array
    {
        if ($current === null || $previous === null) {
            return [];
        }

        $keys ??= array_keys($current);
        $deltas = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $current)) {
                continue;
            }
            $deltas[$key] = $this->deltaPercent((float) ($current[$key] ?? 0), (float) ($previous[$key] ?? 0));
        }

        return $deltas;
    }

    private function deltaPercent(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.00001) {
            return abs($current) < 0.00001 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function resolveRange(string $groupBy, ?string $from, ?string $to): array
    {
        $customFrom = $this->parseDate($from);
        $customTo = $this->parseDate($to);

        if ($customFrom && $customTo) {
            if ($customFrom->gt($customTo)) {
                [$customFrom, $customTo] = [$customTo, $customFrom];
            }

            return [
                'from' => $customFrom->toDateString(),
                'to' => $customTo->toDateString(),
            ];
        }

        $end = now()->endOfDay();
        $start = match ($groupBy) {
            'day' => $end->copy()->startOfDay(),
            'month' => $end->copy()->startOfMonth()->subMonths(11)->startOfDay(),
            default => $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(7)->startOfDay(),
        };

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function previousRange(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return [
            'from' => $prevStart->toDateString(),
            'to' => $prevEnd->toDateString(),
        ];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function trendLabel(string $from, string $to): string
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($start->equalTo($end)) {
            return $start->format('d/m');
        }
        if ($start->isSameMonth($end) && $start->day === 1 && $end->day >= 28) {
            return $start->translatedFormat('M Y');
        }

        return $start->format('d/m').'–'.$end->format('d/m');
    }
}
