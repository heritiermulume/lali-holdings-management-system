<?php

namespace App\Modules\Kams;

use App\Core\Contracts\BusinessModule;
use App\Core\Contracts\FinancialContributor;
use App\Core\Support\PermissionCatalog;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Services\KamsFinanceService;

class KamsModule implements BusinessModule, FinancialContributor
{
    public function __construct(private readonly KamsFinanceService $finance) {}

    public function slug(): string
    {
        return 'kams';
    }

    public function name(): array
    {
        return [
            'en' => 'King Attitude Management System',
            'fr' => 'King Attitude Management System',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Commercial operations, clients, collections and sales for King Attitude Traders.',
            'fr' => 'Opérations commerciales, clients, collections et ventes pour King Attitude Traders.',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function permissions(): array
    {
        return PermissionCatalog::forKams();
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function financialSummary(?int $companyId = null): array
    {
        return $this->finance->summarize($companyId);
    }

    public function recentOrders(?int $companyId = null, int $limit = 5)
    {
        return KamsOrder::query()
            ->with('client:id,name')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest('ordered_at')
            ->limit($limit)
            ->get();
    }
}
