<?php

namespace App\Core\Contracts;

interface FinancialContributor
{
    /**
     * Return consolidated financial figures for a company without exposing
     * the module's internal tables to LHMS Core.
     *
     * @return array{
     *     revenue: float,
     *     expenses: float,
     *     profit: float,
     *     cash: float,
     *     assets: float,
     *     liabilities: float,
     *     receivables: float,
     *     payables: float
     * }
     */
    public function financialSummary(?int $companyId = null): array;
}
