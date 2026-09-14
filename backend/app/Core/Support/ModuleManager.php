<?php

namespace App\Core\Support;

use App\Core\Contracts\BusinessModule;
use App\Core\Contracts\FinancialContributor;
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

        foreach ($this->financialContributors() as $contributor) {
            if ($companyIds === null) {
                $this->merge($totals, $contributor->financialSummary(null));
                continue;
            }

            foreach ($companyIds as $companyId) {
                $this->merge($totals, $contributor->financialSummary((int) $companyId));
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
