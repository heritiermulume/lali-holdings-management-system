<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsOfficeHistory;
use App\Modules\Kams\Models\KamsRevenueRule;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use App\Modules\Kams\Services\RevenueRuleService;
use App\Modules\Kams\Services\RevenueShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KamsRevenueController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    public function __construct(
        private readonly RevenueRuleService $rules,
        private readonly RevenueShareService $shares,
        private readonly KamsAccountProvisioner $accounts,
    ) {}

    public function rules(Request $request): JsonResponse
    {
        $companyId = $this->requireCompany($request);

        return $this->ok($this->rules->listFor($companyId));
    }

    public function updateRule(Request $request, KamsRevenueRule $rule): JsonResponse
    {
        $this->assertCompany($request, (int) $rule->company_id);
        $data = $request->validate([
            'percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'divisor' => ['nullable', 'numeric', 'min:0.0001'],
            'is_active' => ['boolean'],
            'label' => ['sometimes', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        return $this->ok($this->rules->update($rule, $data, $request->user()->id));
    }

    public function summary(Request $request): JsonResponse
    {
        $member = $this->memberFor($request);

        return $this->ok($this->shares->summaryForMember($member));
    }

    public function sale(Request $request, KamsStockSale $sale): JsonResponse
    {
        $this->assertCompany($request, (int) $sale->company_id);

        return $this->ok($this->shares->saleBreakdown($sale));
    }

    public function offices(Request $request): JsonResponse
    {
        $companyId = $this->requireCompany($request);
        $offices = Office::query()
            ->with([
                'parent:id,name,code',
                'creator:id,name',
                'manager:id,name',
                'recordedBy:id,name',
                'children:id,parent_office_id,name,code',
            ])
            ->where('company_id', $companyId)
            ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('id', $request->user()->accessibleOfficeIds() ?: [0]))
            ->orderBy('name')
            ->get();

        return $this->ok($offices);
    }

    public function officeHistory(Request $request, Office $office): JsonResponse
    {
        abort_unless($request->user()->canAccessOffice((int) $office->id), 403);

        return $this->ok(
            KamsOfficeHistory::query()
                ->with('changedBy:id,name')
                ->where('office_id', $office->id)
                ->latest()
                ->limit(80)
                ->get()
        );
    }

    public function postSalary(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->shares->postSalary($member, $data['period'], $request->user()->id, $data['notes'] ?? null));
    }

    private function requireCompany(Request $request): int
    {
        $companyId = $this->kamsCompanyId($request);
        abort_unless($companyId, 422, __('No company is available for this account.'));

        return $companyId;
    }

    private function memberFor(Request $request): KamsMember
    {
        if ($request->filled('member_id') && $request->user()->hasAnyPermission('kams.finance.dashboard.view', 'kams.finance.company.view')) {
            $member = KamsMember::query()->with(['role', 'office'])->findOrFail((int) $request->integer('member_id'));
            $this->assertCompany($request, (int) $member->company_id);

            return $member;
        }

        return $this->accounts->forUser($request->user(), $this->kamsCompanyId($request));
    }
}
