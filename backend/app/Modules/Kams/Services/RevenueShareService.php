<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsRevenueRule;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSalaryEntry;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockSaleItem;
use App\Modules\Kams\Support\RevenueRuleCatalog;
use Illuminate\Support\Collection;

class RevenueShareService
{
    public function __construct(
        private readonly OfficeHierarchy $hierarchy,
        private readonly RevenueRuleService $rules,
        private readonly SavingsLedger $savings,
    ) {}

    public function accrue(KamsStockSale $sale): void
    {
        if (KamsRevenueShare::query()->where('sale_id', $sale->id)->exists()) {
            return;
        }
        $sale->loadMissing(['member.role', 'member.office', 'items.product', 'manager.role']);
        if (! $sale->member?->office_id) {
            return;
        }
        $rules = $this->rules->activeFor((int) $sale->company_id);
        $context = $this->hierarchy->snapshotForSale($sale);
        $publicSnapshot = $this->publicSnapshot($context);

        foreach ($sale->items as $item) {
            $this->accrueItem($sale, $item, $rules, $context, $publicSnapshot);
        }
    }

    public function post(KamsStockSale $sale): void
    {
        $this->accrue($sale);
        $shares = KamsRevenueShare::query()
            ->where('sale_id', $sale->id)
            ->whereIn('status', ['accrued', 'posted'])
            ->get();
        foreach ($shares as $share) {
            $member = $share->beneficiary_member_id
                ? KamsMember::query()->find($share->beneficiary_member_id)
                : null;
            if ($member && (float) $share->amount != 0.0) {
                $kind = RevenueRuleCatalog::savingsKind($share->rule_code);
                $this->savings->postCompensation(
                    $member,
                    $kind,
                    (float) $share->amount,
                    'revenue_share',
                    $share,
                    $sale->sold_at?->toDateString(),
                    trim(($share->formula ?: $share->rule_code).' · '.$sale->reference)
                );
            }
            if ($share->status === 'accrued') {
                $share->forceFill([
                    'status' => 'posted',
                    'posted_at' => now(),
                ])->save();
            }
        }
    }

    /**
     * Ensure posted revenue shares and salaries exist as savings ledger transactions.
     */
    public function syncMemberLedger(KamsMember $member): void
    {
        $shares = KamsRevenueShare::query()
            ->with('sale:id,reference,sold_at')
            ->where('beneficiary_member_id', $member->id)
            ->where('status', 'posted')
            ->where('amount', '!=', 0)
            ->get();

        foreach ($shares as $share) {
            $kind = RevenueRuleCatalog::savingsKind($share->rule_code);
            $sale = $share->sale;
            $this->savings->postCompensation(
                $member,
                $kind,
                (float) $share->amount,
                'revenue_share',
                $share,
                $sale?->sold_at?->toDateString() ?: $share->created_at?->toDateString(),
                trim(($share->formula ?: $share->rule_code).($sale?->reference ? ' · '.$sale->reference : ''))
            );
        }

        $salaries = KamsSalaryEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->where('amount', '!=', 0)
            ->get();

        foreach ($salaries as $salary) {
            $this->savings->postCompensation(
                $member,
                'secretary',
                (float) $salary->amount,
                'salary',
                $salary,
                $salary->created_at?->toDateString() ?: now()->toDateString(),
                'Fixed salary '.$salary->period
            );
        }
    }

    /**
     * Company / office compensation rollup for dashboards.
     *
     * @param  list<int>  $officeIds
     * @return array{
     *   generated: float,
     *   posted: float,
     *   accrued: float,
     *   remaining: float,
     *   assistant_share: float,
     *   manager_sale: float,
     *   manager_office: float,
     *   manager_descendant_office: float,
     *   divisionnaire_office: float,
     *   secretary_office: float,
     *   salary_paid: float,
     *   by_rule: array<string, float>,
     *   by_office: array<int, array<string, float>>
     * }
     */
    public function dashboardTotals(?int $companyId, array $officeIds, string $from, string $to): array
    {
        $emptyRules = [
            RevenueRuleCatalog::MANAGER_SALE => 0.0,
            RevenueRuleCatalog::ASSISTANT_MANAGER => 0.0,
            RevenueRuleCatalog::MANAGER_OFFICE => 0.0,
            RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE => 0.0,
            RevenueRuleCatalog::DIVISIONNAIRE_OFFICE => 0.0,
            RevenueRuleCatalog::SECRETARY_OFFICE => 0.0,
        ];

        $query = KamsRevenueShare::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->whereHas('sale', fn ($sale) => $sale->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']));

        $rows = (clone $query)
            ->selectRaw('office_id, rule_code, status, coalesce(sum(amount), 0) as total')
            ->groupBy('office_id', 'rule_code', 'status')
            ->get();

        $byRule = $emptyRules;
        $byOffice = [];
        $generated = 0.0;
        $posted = 0.0;
        $accrued = 0.0;

        foreach ($rows as $row) {
            $amount = round((float) $row->total, 2);
            $code = (string) $row->rule_code;
            $officeId = (int) ($row->office_id ?: 0);
            $generated += $amount;
            if ($row->status === 'posted') {
                $posted += $amount;
            } else {
                $accrued += $amount;
            }
            if (! array_key_exists($code, $byRule)) {
                $byRule[$code] = 0.0;
            }
            $byRule[$code] = round($byRule[$code] + $amount, 2);

            if ($officeId > 0) {
                if (! isset($byOffice[$officeId])) {
                    $byOffice[$officeId] = array_merge($emptyRules, [
                        'generated' => 0.0,
                        'posted' => 0.0,
                        'accrued' => 0.0,
                        'remaining' => 0.0,
                        'salary_paid' => 0.0,
                    ]);
                }
                $byOffice[$officeId]['generated'] = round($byOffice[$officeId]['generated'] + $amount, 2);
                if ($row->status === 'posted') {
                    $byOffice[$officeId]['posted'] = round($byOffice[$officeId]['posted'] + $amount, 2);
                } else {
                    $byOffice[$officeId]['accrued'] = round($byOffice[$officeId]['accrued'] + $amount, 2);
                }
                if (array_key_exists($code, $emptyRules)) {
                    $byOffice[$officeId][$code] = round($byOffice[$officeId][$code] + $amount, 2);
                }
            }
        }

        $salaryQuery = KamsSalaryEntry::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->where('status', 'posted')
            ->whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)]);
        $salaryPaid = round((float) (clone $salaryQuery)->sum('amount'), 2);
        foreach ((clone $salaryQuery)->selectRaw('office_id, coalesce(sum(amount), 0) as total')->groupBy('office_id')->get() as $row) {
            $officeId = (int) ($row->office_id ?: 0);
            if ($officeId <= 0) {
                continue;
            }
            if (! isset($byOffice[$officeId])) {
                $byOffice[$officeId] = array_merge($emptyRules, [
                    'generated' => 0.0,
                    'posted' => 0.0,
                    'accrued' => 0.0,
                    'remaining' => 0.0,
                    'salary_paid' => 0.0,
                ]);
            }
            $byOffice[$officeId]['salary_paid'] = round((float) $row->total, 2);
        }

        foreach ($byOffice as $officeId => $values) {
            $byOffice[$officeId]['remaining'] = round((float) $values['accrued'], 2);
        }

        return [
            'generated' => round($generated, 2),
            'posted' => round($posted, 2),
            'accrued' => round($accrued, 2),
            'remaining' => round($accrued, 2),
            'assistant_share' => round($byRule[RevenueRuleCatalog::ASSISTANT_MANAGER] ?? 0, 2),
            'manager_sale' => round($byRule[RevenueRuleCatalog::MANAGER_SALE] ?? 0, 2),
            'manager_office' => round($byRule[RevenueRuleCatalog::MANAGER_OFFICE] ?? 0, 2),
            'manager_descendant_office' => round($byRule[RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE] ?? 0, 2),
            'divisionnaire_office' => round($byRule[RevenueRuleCatalog::DIVISIONNAIRE_OFFICE] ?? 0, 2),
            'secretary_office' => round($byRule[RevenueRuleCatalog::SECRETARY_OFFICE] ?? 0, 2),
            'salary_paid' => $salaryPaid,
            'by_rule' => array_map(fn ($value) => round((float) $value, 2), $byRule),
            'by_office' => $byOffice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForMember(KamsMember $member): array
    {
        $base = KamsRevenueShare::query()->where('beneficiary_member_id', $member->id);

        $ruleRows = (clone $base)
            ->selectRaw('rule_code, status, coalesce(sum(amount), 0) as total')
            ->groupBy('rule_code', 'status')
            ->get();

        $emptyRules = [
            RevenueRuleCatalog::MANAGER_SALE => 0.0,
            RevenueRuleCatalog::ASSISTANT_MANAGER => 0.0,
            RevenueRuleCatalog::MANAGER_OFFICE => 0.0,
            RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE => 0.0,
            RevenueRuleCatalog::DIVISIONNAIRE_OFFICE => 0.0,
            RevenueRuleCatalog::SECRETARY_OFFICE => 0.0,
        ];
        $byRule = $emptyRules;
        $generated = 0.0;
        $posted = 0.0;
        $accrued = 0.0;

        foreach ($ruleRows as $row) {
            $amount = round((float) $row->total, 2);
            $code = (string) $row->rule_code;
            $generated += $amount;
            if ($row->status === 'posted') {
                $posted += $amount;
            } else {
                $accrued += $amount;
            }
            if (! array_key_exists($code, $byRule)) {
                $byRule[$code] = 0.0;
            }
            $byRule[$code] = round($byRule[$code] + $amount, 2);
        }

        $managerSaleIds = (clone $base)
            ->where('rule_code', RevenueRuleCatalog::MANAGER_SALE)
            ->pluck('id')
            ->filter()
            ->all();
        $toAssistants = KamsRevenueShare::query()
            ->whereIn('parent_share_id', $managerSaleIds ?: [0])
            ->sum('amount');

        $shares = (clone $base)
            ->with(['sale:id,reference,sold_at,field_total', 'product:id,name,sku', 'office:id,name,code'])
            ->latest()
            ->limit(80)
            ->get();

        $salaries = KamsSalaryEntry::query()
            ->where('member_id', $member->id)
            ->latest()
            ->limit(12)
            ->get();
        $salaryPaid = round((float) $salaries->sum('amount'), 2);

        return [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_code' => $member->member_code,
                'role' => $member->role?->only(['id', 'name', 'slug']),
                'fixed_salary' => round((float) $member->fixed_salary, 2),
            ],
            'totals' => [
                'generated' => round($generated, 2),
                'posted' => round($posted, 2),
                'accrued' => round($accrued, 2),
                'remaining' => round($accrued, 2),
                'distributed_to_assistants' => round((float) $toAssistants, 2),
                'manager_remaining' => round(max(0, ($byRule[RevenueRuleCatalog::MANAGER_SALE] ?? 0) - (float) $toAssistants), 2),
                'manager_sale' => round($byRule[RevenueRuleCatalog::MANAGER_SALE] ?? 0, 2),
                'assistant_share' => round($byRule[RevenueRuleCatalog::ASSISTANT_MANAGER] ?? 0, 2),
                'manager_office' => round($byRule[RevenueRuleCatalog::MANAGER_OFFICE] ?? 0, 2),
                'manager_descendant_office' => round($byRule[RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE] ?? 0, 2),
                'divisionnaire_office' => round($byRule[RevenueRuleCatalog::DIVISIONNAIRE_OFFICE] ?? 0, 2),
                'secretary_office' => round($byRule[RevenueRuleCatalog::SECRETARY_OFFICE] ?? 0, 2),
                'salary_paid' => $salaryPaid,
            ],
            'by_rule' => collect($byRule)->map(fn (float $amount, string $code) => [
                'code' => $code,
                'amount' => $amount,
            ])->values()->all(),
            'shares' => $shares->map(fn (KamsRevenueShare $share) => $this->presentShare($share))->all(),
            'salaries' => $salaries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saleBreakdown(KamsStockSale $sale): array
    {
        $this->accrue($sale);
        $shares = KamsRevenueShare::query()
            ->with(['beneficiary:id,full_name,member_code', 'product:id,name,sku', 'office:id,name,code'])
            ->where('sale_id', $sale->id)
            ->get();

        return [
            'sale' => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'field_total' => round((float) $sale->field_total, 2),
                'sold_at' => $sale->sold_at?->toDateString(),
            ],
            'shares' => $shares->map(fn (KamsRevenueShare $share) => $this->presentShare($share))->all(),
        ];
    }

    public function postSalary(KamsMember $member, string $period, ?int $userId, ?string $notes = null): KamsSalaryEntry
    {
        $amount = round((float) $member->fixed_salary, 2);
        abort_unless($amount > 0, 422, __('No fixed salary is configured for this member.'));

        $entry = KamsSalaryEntry::query()->firstOrCreate(
            ['member_id' => $member->id, 'period' => $period],
            [
                'company_id' => $member->company_id,
                'office_id' => $member->office_id,
                'amount' => $amount,
                'status' => 'posted',
                'notes' => $notes,
                'created_by' => $userId,
            ]
        );
        if ($entry->wasRecentlyCreated) {
            $this->savings->postCompensation(
                $member,
                'secretary',
                $amount,
                'salary',
                $entry,
                now()->toDateString(),
                'Fixed salary '.$period
            );
        }

        return $entry->fresh('member');
    }

    /**
     * @param  Collection<string, KamsRevenueRule>  $rules
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $publicSnapshot
     */
    private function accrueItem(
        KamsStockSale $sale,
        KamsStockSaleItem $item,
        Collection $rules,
        array $context,
        array $publicSnapshot,
    ): void {
        $qty = (int) $item->quantity;
        $price = (float) ($item->field_price ?: $item->unit_price);
        $sales = round($price * $qty, 2);
        $product = $item->product;
        $managerRule = $rules->get(RevenueRuleCatalog::MANAGER_SALE);
        $managerPercent = $this->managerPercent($product, $managerRule);
        $office = $context['office_model'];
        $manager = $context['manager'];
        $lineSnapshot = array_merge($publicSnapshot, [
            'product' => $product ? ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku] : null,
            'unit_price' => $price,
            'quantity' => $qty,
            'sales' => $sales,
            'manager_percent' => $managerPercent,
        ]);

        $managerShare = null;
        if ($manager && $managerRule && $managerPercent > 0) {
            $amount = RevenueRuleCatalog::percentOf($sales, $managerPercent);
            $managerShare = $this->storeShare(
                $sale,
                $item,
                $office?->id,
                $managerRule,
                $manager,
                $sales,
                $managerPercent,
                $amount,
                RevenueRuleCatalog::formula(RevenueRuleCatalog::MANAGER_SALE, $sales, $managerPercent),
                $lineSnapshot
            );
        }

        $amRule = $rules->get(RevenueRuleCatalog::ASSISTANT_MANAGER);
        $assistant = $context['assistant'];
        if ($assistant && $amRule && $managerPercent > 0 && $managerShare) {
            $divisor = (float) ($amRule->divisor ?? 0);
            if ($divisor <= 0) {
                $divisor = 0;
            }
            $amAmount = RevenueRuleCatalog::assistantShare($sales, $divisor, $managerPercent);
            $amAmount = min($amAmount, (float) $managerShare->amount);
            if ($amAmount > 0) {
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $amRule,
                    $assistant,
                    $sales,
                    $managerPercent,
                    $amAmount,
                    RevenueRuleCatalog::formula(RevenueRuleCatalog::ASSISTANT_MANAGER, $sales, $managerPercent, $divisor),
                    array_merge($lineSnapshot, ['team_sales' => $sales, 'divisor' => $divisor]),
                    $managerShare->id
                );
                $managerShare->amount = round((float) $managerShare->amount - $amAmount, 2);
                $managerShare->formula = RevenueRuleCatalog::formula(RevenueRuleCatalog::MANAGER_SALE, $sales, $managerPercent)
                    .' − AM '.$amAmount;
                $managerShare->save();
            }
        }

        $directRule = $rules->get(RevenueRuleCatalog::MANAGER_OFFICE);
        $descendantRule = $rules->get(RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE);
        foreach ($context['manager_models'] as $row) {
            /** @var KamsMember $upline */
            $upline = $row['member'];
            $direct = (bool) $row['direct'];
            $rule = $direct ? $directRule : $descendantRule;
            if (! $rule || (float) $rule->percent <= 0) {
                continue;
            }
            $this->storeShare(
                $sale,
                $item,
                $office?->id,
                $rule,
                $upline,
                $sales,
                (float) $rule->percent,
                RevenueRuleCatalog::percentOf($sales, (float) $rule->percent),
                RevenueRuleCatalog::formula($rule->code, $sales, (float) $rule->percent),
                $lineSnapshot
            );
        }

        $divRule = $rules->get(RevenueRuleCatalog::DIVISIONNAIRE_OFFICE);
        if ($divRule && (float) $divRule->percent > 0) {
            foreach ($context['divisionnaire_models'] as $divisionnaire) {
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $divRule,
                    $divisionnaire,
                    $sales,
                    (float) $divRule->percent,
                    RevenueRuleCatalog::percentOf($sales, (float) $divRule->percent),
                    RevenueRuleCatalog::formula($divRule->code, $sales, (float) $divRule->percent),
                    $lineSnapshot
                );
            }
        }

        $secretaryRule = $rules->get(RevenueRuleCatalog::SECRETARY_OFFICE);
        if ($secretaryRule && (float) $secretaryRule->percent > 0 && $context['creator']) {
            foreach ($context['secretary_models'] as $secretary) {
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $secretaryRule,
                    $secretary,
                    $sales,
                    (float) $secretaryRule->percent,
                    RevenueRuleCatalog::percentOf($sales, (float) $secretaryRule->percent),
                    RevenueRuleCatalog::formula($secretaryRule->code, $sales, (float) $secretaryRule->percent),
                    $lineSnapshot
                );
            }
        }
    }

    private function managerPercent(?KamsProduct $product, ?KamsRevenueRule $rule): float
    {
        if ($product && $product->manager_share_percent !== null && (float) $product->manager_share_percent > 0) {
            return (float) $product->manager_share_percent;
        }

        return $rule ? (float) $rule->percent : 0;
    }

    private function storeShare(
        KamsStockSale $sale,
        KamsStockSaleItem $item,
        ?int $officeId,
        KamsRevenueRule $rule,
        KamsMember $beneficiary,
        float $base,
        float $percent,
        float $amount,
        string $formula,
        array $snapshot,
        ?int $parentId = null,
    ): KamsRevenueShare {
        return KamsRevenueShare::query()->create([
            'company_id' => $sale->company_id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'product_id' => $item->product_id,
            'office_id' => $officeId,
            'rule_id' => $rule->id,
            'rule_code' => $rule->code,
            'role_slug' => $rule->role_slug,
            'beneficiary_user_id' => $beneficiary->user_id,
            'beneficiary_member_id' => $beneficiary->id,
            'base_amount' => $base,
            'percent' => $percent,
            'divisor' => $rule->divisor,
            'amount' => $amount,
            'formula' => $formula,
            'status' => 'accrued',
            'parent_share_id' => $parentId,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function publicSnapshot(array $context): array
    {
        return [
            'seller' => $context['seller'],
            'assistant_manager' => $context['assistant_manager'],
            'office' => $context['office'],
            'office_creator' => $context['office_creator'],
            'office_manager' => $context['office_manager'],
            'upline_managers' => $context['upline_managers'],
            'divisionnaires' => $context['divisionnaires'],
            'secretaries' => $context['secretaries'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShare(KamsRevenueShare $share): array
    {
        return [
            'id' => $share->id,
            'rule_code' => $share->rule_code,
            'role_slug' => $share->role_slug,
            'status' => $share->status,
            'base_amount' => round((float) $share->base_amount, 2),
            'percent' => (float) $share->percent,
            'divisor' => $share->divisor !== null ? (float) $share->divisor : null,
            'amount' => round((float) $share->amount, 2),
            'formula' => $share->formula,
            'snapshot' => $share->snapshot,
            'sale' => $share->relationLoaded('sale') ? $share->sale : null,
            'product' => $share->relationLoaded('product') ? $share->product : null,
            'office' => $share->relationLoaded('office') ? $share->office : null,
            'beneficiary' => $share->relationLoaded('beneficiary') ? $share->beneficiary : null,
        ];
    }
}
