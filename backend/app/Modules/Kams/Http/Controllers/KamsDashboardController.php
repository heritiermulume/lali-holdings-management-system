<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Modules\Kams\KamsModule;
use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Services\RecruitmentMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KamsDashboardController extends Controller
{
    use RespondsWithJson;

    public function __invoke(Request $request, KamsModule $module, RecruitmentMetrics $metrics): JsonResponse
    {
        $companyId = $this->companyId($request);

        return $this->ok([
            'stats' => [
                'clients' => KamsClient::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->count(),
                'products' => KamsProduct::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->count(),
                'orders' => KamsOrder::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->count(),
                'campaigns' => KamsCampaign::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->count(),
            ],
            'finance' => $module->financialSummary($companyId),
            'recent_orders' => $module->recentOrders($companyId, 6),
            'top_products' => KamsProduct::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->orderByDesc('stock')
                ->limit(5)
                ->get(),
            ...$metrics->summarize($companyId),
        ]);
    }

    private function companyId(Request $request): ?int
    {
        if ($request->filled('company_id')) {
            $requested = (int) $request->integer('company_id');
            abort_unless($request->user()->canAccessCompany($requested), 403);

            return $requested;
        }

        if ($request->user()->isGroupAdmin()) {
            return null;
        }

        $primary = $request->user()->primary_company_id;

        if ($primary && ! $request->user()->canAccessCompany((int) $primary)) {
            abort(403);
        }

        return $primary;
    }
}
