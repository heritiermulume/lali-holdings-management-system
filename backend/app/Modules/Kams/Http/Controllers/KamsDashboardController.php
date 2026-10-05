<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\KamsModule;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsStockAdjustment;
use App\Modules\Kams\Models\KamsStockAssignmentItem;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockSaleItem;
use App\Modules\Kams\Services\KamsFinanceService;
use App\Modules\Kams\Services\RecruitmentMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class KamsDashboardController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    public function __invoke(Request $request, KamsModule $module, RecruitmentMetrics $metrics, KamsFinanceService $finance): JsonResponse
    {
        $companyId = $this->companyId($request);
        $groupBy = in_array($request->string('group_by')->toString(), ['day', 'week', 'month', 'custom'], true)
            ? $request->string('group_by')->toString()
            : 'week';
        [$from, $to] = $groupBy === 'custom'
            ? $this->customRange($request)
            : $this->range($groupBy);

        $user = $request->user();
        $offices = $this->kamsOffices($request)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $accessibleIds = $offices->pluck('id')->map(fn ($id) => (int) $id)->all();
        $canFilter = count($accessibleIds) > 1;
        $officeIds = $user->isGroupAdmin() || ! $user->isOfficeScoped() ? [] : $accessibleIds;
        if (! $canFilter) {
            $officeIds = $accessibleIds;
        } elseif ($request->filled('office_id')) {
            $selected = (int) $request->integer('office_id');
            $this->assertKamsOffice($request, $selected);
            $officeIds = [$selected];
        }
        $canProducts = $user->hasPermission('kams.dashboard.products.view');
        $canOrders = $user->hasPermission('kams.dashboard.orders.view');
        $canFinance = $user->hasPermission('kams.dashboard.finance.view');
        $canRecruitment = $user->hasPermission('kams.dashboard.recruitment.view');

        $stats = [];
        if ($canProducts) {
            $stats['stock'] = (int) KamsStockBalance::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeIds !== [], fn ($q) => $q->whereHas('depot', fn ($depot) => $depot->whereIn('office_id', $officeIds)))
                ->where('condition', 'available')
                ->sum('quantity');
        }
        if ($canOrders) {
            $stats['sales'] = (int) KamsStockSaleItem::query()
                ->whereHas('sale', function ($sale) use ($companyId, $officeIds, $from, $to) {
                    $sale->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                        ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeIds)))
                        ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
                })
                ->sum('quantity');
            $stats['losses'] = (int) KamsStockAssignmentItem::query()
                ->whereHas('assignment', function ($assignment) use ($companyId, $officeIds, $from, $to) {
                    $assignment->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                        ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
                        ->where('status', 'settled')
                        ->whereBetween('settled_at', [$from.' 00:00:00', $to.' 23:59:59']);
                })
                ->sum('quantity_lost');
            $stats['defectives'] = (int) KamsStockAdjustment::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeIds !== [], fn ($q) => $q->whereHas('depot', fn ($depot) => $depot->whereIn('office_id', $officeIds)))
                ->where('type', 'defective')
                ->whereBetween('adjusted_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->sum('quantity');
        }

        return $this->ok([
            'group_by' => $groupBy,
            'range' => ['from' => $from, 'to' => $to],
            'can_filter_offices' => $canFilter,
            'office_id' => count($officeIds) === 1 ? $officeIds[0] : null,
            'filter_offices' => $canFilter ? $offices->map(fn ($office) => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ])->values()->all() : [],
            'stats' => $stats,
            'finance' => $canFinance ? $module->financialSummary($companyId, $from, $to, $officeIds) : null,
            'trend' => $canFinance ? $finance->trend($companyId, $from, $to, $officeIds) : [],
            'revenue_by_office' => ($canFinance && $canFilter && ! $request->filled('office_id'))
                ? $finance->revenueByOffice($companyId, $from, $to, $accessibleIds)
                : [],
            'top_products' => KamsProduct::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->orderByDesc('stock')
                ->limit(5)
                ->get(),
            'recruitment' => $canRecruitment ? $metrics->summarize($companyId, $officeIds, $from, $to)['recruitment'] : null,
            'recruitment_trend' => $canRecruitment ? $metrics->trend($companyId, $officeIds, $from, $to) : [],
            'recruitment_by_office' => ($canRecruitment && $canFilter && ! $request->filled('office_id'))
                ? $metrics->byOffice($companyId, $from, $to, $accessibleIds)
                : [],
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(string $groupBy): array
    {
        $today = now();

        return match ($groupBy) {
            'day' => [$today->toDateString(), $today->toDateString()],
            'month' => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()],
            default => [
                $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function customRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to), 422);
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $today = now()->startOfDay();
        if ($end->greaterThan($today)) {
            $end = $today;
        }
        abort_unless($start->lessThanOrEqualTo($end), 422);

        return [$start->toDateString(), $end->toDateString()];
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
