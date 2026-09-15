<?php

namespace App\Core\Support;

use App\Core\Contracts\BusinessModule;
use App\Core\Contracts\FinancialContributor;
use App\Core\Support\CurrencyConverter;
use App\Models\Company;
use App\Models\SystemModule;

class ModuleManager
{
    /**
     * @return array<string, BusinessModule>
     */
    public function instances(): array
    {
        $modules = [];

        foreach (config('lhms.modules', []) as $slug => $class) {
            if (! class_exists($class)) {
                continue;
            }

            $instance = app($class);

            if ($instance instanceof BusinessModule) {
                $modules[$slug] = $instance;
            }
        }

        return $modules;
    }

    /**
     * @return array<string, FinancialContributor>
     */
    public function financialContributors(): array
    {
        return array_filter(
            $this->instances(),
            fn ($module) => $module instanceof FinancialContributor
        );
    }

    public function consolidateFinances(?array $companyIds = null): array
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
        ];

        $ids = $companyIds === null
            ? Company::query()->pluck('id')->all()
            : array_values(array_unique(array_map('intval', $companyIds)));

        if ($ids === []) {
            return $totals;
        }

        $fx = app(CurrencyConverter::class);
        $default = $fx->defaultCode();
        $companies = Company::query()->whereIn('id', $ids)->get()->keyBy('id');
        $contributors = $this->financialContributors();

        foreach ($ids as $companyId) {
            $from = strtoupper((string) ($companies[$companyId]->currency_code ?? $default));
            foreach ($contributors as $contributor) {
                $summary = $fx->convertBag($contributor->financialSummary($companyId), $from, $default);
                $this->merge($totals, $summary);
            }
        }

        return $totals;
    }

    public function registered()
    {
        return SystemModule::query()->orderBy('sort_order')->get();
    }

    private function merge(array &$totals, array $summary): void
    {
        foreach ($totals as $key => $value) {
            $totals[$key] = round($value + (float) ($summary[$key] ?? 0), 2);
        }
    }
}
