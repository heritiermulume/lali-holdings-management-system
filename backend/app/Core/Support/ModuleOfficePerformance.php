<?php

namespace App\Core\Support;

use App\Models\Company;
use App\Models\SystemModule;
use App\Models\User;
use App\Modules\Kams\Services\RevenueShareService;
use App\Modules\Kams\Services\StockMetrics;
use Illuminate\Support\Carbon;

class ModuleOfficePerformance
{
    public function __construct(
        private readonly StockMetrics $stock,
        private readonly RevenueShareService $revenueShares,
        private readonly CurrencyConverter $fx,
    ) {}

    /**
     * @return array{group_by: string, range: array{from: string, to: string}, currency: string, modules: list<array<string, mixed>>}
     */
    public function build(User $user, string $groupBy = 'week', ?string $slug = null, ?string $from = null, ?string $to = null): array
    {
        $groupBy = in_array($groupBy, ['day', 'week', 'month', 'custom'], true) ? $groupBy : 'week';
        $range = $this->resolveRange($groupBy, $from, $to);
        $companyIds = $user->accessibleCompanyIds() ?: [0];
        $scopedOfficeIds = ! $user->isGroupAdmin() && $user->isOfficeScoped()
            ? ($user->accessibleOfficeIds() ?: [0])
            : [];
        $canSeeFinance = $user->hasPermission('finance.view')
            || $user->hasPermission('administration.view')
            || $user->hasPermission('kams.finance.dashboard.view');
        $allowedSlugs = $user->accessibleModuleSlugs();
        $default = $this->fx->defaultCode();
        $items = [];

        $modules = SystemModule::query()
            ->where('is_core', false)
            ->when($slug, fn ($query) => $query->where('slug', $slug))
            ->orderBy('sort_order')
            ->get();

        foreach ($modules as $module) {
            if (! in_array($module->slug, $allowedSlugs, true)) {
                continue;
            }

            $companies = Company::query()
                ->where('system_module_id', $module->id)
                ->whereIn('id', $companyIds)
                ->get();

            if ($companies->isEmpty()) {
                continue;
            }

            $offices = [];
            $moduleCompensation = [
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
            $moduleSavings = [
                'guy_savings' => 0.0,
                'assistant_manager_savings' => 0.0,
                'manager_savings' => 0.0,
            ];
            foreach ($companies as $company) {
                $fromCurrency = strtoupper((string) ($company->currency_code ?: $default));
                if ($module->slug === 'kams' && $canSeeFinance) {
                    $companyComp = $this->revenueShares->dashboardTotals((int) $company->id, $scopedOfficeIds, $range['from'], $range['to']);
                    $convertedComp = $this->fx->convertBag([
                        'generated' => (float) $companyComp['generated'],
                        'posted' => (float) $companyComp['posted'],
                        'remaining' => (float) $companyComp['remaining'],
                        'manager_sale' => (float) $companyComp['manager_sale'],
                        'assistant_share' => (float) $companyComp['assistant_share'],
                        'manager_office' => (float) (($companyComp['manager_office'] ?? 0) + ($companyComp['manager_descendant_office'] ?? 0)),
                        'divisionnaire_office' => (float) $companyComp['divisionnaire_office'],
                        'secretary_office' => (float) $companyComp['secretary_office'],
                        'salary_paid' => (float) $companyComp['salary_paid'],
                    ], $fromCurrency, $default);
                    foreach ($convertedComp as $key => $value) {
                        $moduleCompensation[$key] = round($moduleCompensation[$key] + (float) $value, 2);
                    }

                    foreach ($this->stock->officesForPeriod((int) $company->id, $scopedOfficeIds, $range['from'], $range['to']) as $row) {
                        $converted = $this->fx->convertBag([
                            'revenue' => (float) ($row['expected'] ?? 0),
                            'profit' => (float) ($row['profit'] ?? 0),
                            'cash' => (float) ($row['cash'] ?? 0),
                            'compensation' => (float) ($row['compensation_generated'] ?? 0),
                            'manager_sale' => (float) ($row['manager_sale'] ?? 0),
                            'assistant_share' => (float) ($row['assistant_share'] ?? 0),
                            'manager_office_share' => (float) ($row['manager_office_share'] ?? 0),
                            'divisionnaire_share' => (float) ($row['divisionnaire_share'] ?? 0),
                            'secretary_share' => (float) ($row['secretary_share'] ?? 0),
                            'guy_savings' => (float) ($row['guy_savings'] ?? 0),
                            'assistant_manager_savings' => (float) ($row['assistant_manager_savings'] ?? 0),
                            'manager_savings' => (float) ($row['manager_savings'] ?? 0),
                        ], $fromCurrency, $default);
                        $moduleSavings['guy_savings'] = round($moduleSavings['guy_savings'] + (float) $converted['guy_savings'], 2);
                        $moduleSavings['assistant_manager_savings'] = round($moduleSavings['assistant_manager_savings'] + (float) $converted['assistant_manager_savings'], 2);
                        $moduleSavings['manager_savings'] = round($moduleSavings['manager_savings'] + (float) $converted['manager_savings'], 2);
                        $offices[] = [
                            'id' => $row['id'],
                            'name' => $row['name'],
                            'code' => $row['code'] ?? '',
                            ...$converted,
                        ];
                    }

                    continue;
                }

                $query = $company->offices()->where('is_active', true)->orderBy('name');
                if ($scopedOfficeIds !== []) {
                    $query->whereIn('id', $scopedOfficeIds);
                }
                foreach ($query->get(['id', 'name', 'code']) as $office) {
                    $offices[] = [
                        'id' => $office->id,
                        'name' => $office->name,
                        'code' => $office->code,
                        'revenue' => 0,
                        'profit' => 0,
                        'cash' => 0,
                    ];
                }
            }

            $items[] = [
                'slug' => $module->slug,
                'name' => $module->name,
                'name_fr' => $module->name_fr,
                'offices' => $offices,
                'compensation' => $module->slug === 'kams' ? $moduleCompensation : null,
                'savings' => $module->slug === 'kams' ? $moduleSavings : null,
            ];
        }

        return [
            'group_by' => $from && $to ? 'custom' : $groupBy,
            'range' => $range,
            'currency' => $default,
            'modules' => $items,
        ];
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

        return $this->fallbackRange($groupBy);
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

    /**
     * @return array{from: string, to: string}
     */
    private function fallbackRange(string $groupBy): array
    {
        $end = now()->endOfDay();
        $start = match ($groupBy) {
            'day' => $end->copy()->subDays(13)->startOfDay(),
            'month' => $end->copy()->startOfMonth()->subMonths(11)->startOfDay(),
            default => $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(7)->startOfDay(),
        };

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }
}
