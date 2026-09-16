<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsProduct;

class KamsFinanceService
{
    public function summarize(?int $companyId = null): array
    {
        $orders = KamsOrder::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', '!=', 'cancelled');

        $revenue = (float) (clone $orders)->sum('total');
        $receivables = (float) (clone $orders)->where('status', 'confirmed')->sum('total');
        $expenses = (float) KamsCampaign::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->sum('budget');
        $inventory = (float) KamsProduct::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('COALESCE(SUM(company_price * stock), 0) as value')
            ->value('value');

        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'profit' => round($revenue - $expenses, 2),
            'cash' => round($revenue * 0.62, 2),
            'assets' => round($inventory + ($revenue * 0.25), 2),
            'liabilities' => round($expenses * 0.18, 2),
            'receivables' => round($receivables, 2),
            'payables' => round($expenses * 0.12, 2),
        ];
    }
}
