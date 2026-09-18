<?php

namespace App\Core\Support;

use App\Models\Company;
use App\Models\SystemModule;
use App\Models\User;
use App\Modules\Kams\Services\StockMetrics;
use Illuminate\Support\Carbon;

class ModuleOfficePerformance
{
    public function __construct(
        private readonly StockMetrics $stock,
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
            foreach ($companies as $company) {
                $fromCurrency = strtoupper((string) ($company->currency_code ?: $default));
                if ($module->slug === 'kams' && $canSeeFinance) {
                    foreach ($this->stock->officesForPeriod((int) $company->id, $scopedOfficeIds, $range['from'], $range['to']) as $row) {
                        $converted = $this->fx->convertBag([
                            'revenue' => (float) ($row['expected'] ?? 0),
                            'profit' => (float) ($row['profit'] ?? 0),
                            'cash' => (float) ($row['cash'] ?? 0),
                        ], $fromCurrency, $default);
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
