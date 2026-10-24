<?php

namespace App\Modules\Kams\Services;

use App\Models\Office;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsRevenueRule;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSalaryEntry;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
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
            ->with(['beneficiary:id,company_id,full_name,member_code,office_id,role_id', 'beneficiary.role:id,slug', 'parentShare'])
            ->where('sale_id', $sale->id)
            ->whereIn('status', ['accrued', 'posted', 'payable'])
            ->get();
        foreach ($shares as $share) {
            if (! $this->creditsOnDayClose($share)) {
                // Another office: stays payable until a manual transfer.
                if ($share->status === 'accrued') {
                    $share->forceFill([
                        'status' => 'payable',
                        'posted_at' => null,
                    ])->save();
                }
                continue;
            }

            $this->postShareToLedger($share, $sale);
            if (in_array($share->status, ['accrued', 'payable'], true)) {
                $share->forceFill([
                    'status' => 'posted',
                    'posted_at' => now(),
                ])->save();
            }
        }

        // Assistant payouts debit the leader salary only once that share is posted.
        foreach ($shares as $share) {
            if (! $this->isAssistantShare($share)) {
                continue;
            }
            $share->unsetRelation('parentShare');
            $this->postManagerAssistantPayout($share, $sale);
        }
    }

    /**
     * Ensure posted revenue shares and salaries exist as savings ledger transactions.
     */
    public function syncMemberLedger(KamsMember $member): void
    {
        $member->loadMissing('role:id,slug');
        if ($member->office_id) {
            $ownOfficeShares = KamsRevenueShare::query()
                ->with([
                    'sale:id,reference,sold_at',
                    'beneficiary:id,company_id,full_name,member_code,office_id,role_id',
                    'beneficiary.role:id,slug',
                    'parentShare',
                ])
                ->where('beneficiary_member_id', $member->id)
                ->where('office_id', $member->office_id)
                ->where('status', 'payable')
                ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
                ->where('amount', '!=', 0)
                ->get();
            foreach ($ownOfficeShares as $share) {
                $this->postShareToLedger($share, $share->sale);
                $share->forceFill([
                    'status' => 'posted',
                    'posted_at' => $share->posted_at ?: now(),
                ])->save();
                $this->reducePriorOfficeCollection($share);
            }
        }

        $shares = KamsRevenueShare::query()
            ->with([
                'sale:id,reference,sold_at',
                'beneficiary:id,company_id,full_name,member_code,office_id,role_id',
                'beneficiary.role:id,slug',
                'parentShare',
            ])
            ->where('beneficiary_member_id', $member->id)
            ->where('status', 'posted')
            ->where('amount', '!=', 0)
            ->get();

        foreach ($shares as $share) {
            $this->postShareToLedger($share, $share->sale);
        }

        // Backfill salary debits for assistants paid from this manager's office production.
        $managerSaleIds = KamsRevenueShare::query()
            ->where('beneficiary_member_id', $member->id)
            ->where('rule_code', RevenueRuleCatalog::MANAGER_SALE)
            ->where('status', 'posted')
            ->pluck('id')
            ->filter()
            ->all();
        if ($managerSaleIds !== []) {
            $childShares = KamsRevenueShare::query()
                ->with([
                    'sale:id,reference,sold_at',
                    'beneficiary:id,company_id,full_name,member_code,office_id,role_id',
                    'beneficiary.role:id,slug',
                    'parentShare',
                ])
                ->whereIn('parent_share_id', $managerSaleIds)
                ->where('status', 'posted')
                ->where('amount', '!=', 0)
                ->get();
            foreach ($childShares as $child) {
                $this->postManagerAssistantPayout($child, $child->sale);
            }
        }

        $salaries = KamsSalaryEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->where('amount', '!=', 0)
            ->get();

        $salaryKind = ! $this->hierarchy->remuneratedAsAssistant($member)
            && RevenueRuleCatalog::usesSplitCompensationAccounts($member->role?->slug)
            ? 'salary'
            : 'secretary';

        foreach ($salaries as $salary) {
            $this->savings->postCompensation(
                $member,
                $salaryKind,
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
     *   manager_certified_office: float,
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
            RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED => 0.0,
            RevenueRuleCatalog::MANAGER_OFFICE => 0.0,
            RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE => 0.0,
            RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE => 0.0,
            RevenueRuleCatalog::DIVISIONNAIRE_OFFICE => 0.0,
            RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE => 0.0,
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
            $isSettled = in_array((string) $row->status, ['posted', 'paid'], true);
            if ($isSettled) {
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
                if ($isSettled) {
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
            'assistant_share' => round(
                ($byRule[RevenueRuleCatalog::ASSISTANT_MANAGER] ?? 0)
                + ($byRule[RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED] ?? 0),
                2
            ),
            'manager_sale' => round($byRule[RevenueRuleCatalog::MANAGER_SALE] ?? 0, 2),
            'manager_office' => round($byRule[RevenueRuleCatalog::MANAGER_OFFICE] ?? 0, 2),
            'manager_certified_office' => round($byRule[RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE] ?? 0, 2),
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
            RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED => 0.0,
            RevenueRuleCatalog::MANAGER_OFFICE => 0.0,
            RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE => 0.0,
            RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE => 0.0,
            RevenueRuleCatalog::DIVISIONNAIRE_OFFICE => 0.0,
            RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE => 0.0,
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
            if (in_array((string) $row->status, ['posted', 'paid'], true)) {
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
                'assistant_share' => round(
                    ($byRule[RevenueRuleCatalog::ASSISTANT_MANAGER] ?? 0)
                    + ($byRule[RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED] ?? 0),
                    2
                ),
                'manager_office' => round($byRule[RevenueRuleCatalog::MANAGER_OFFICE] ?? 0, 2),
                'manager_certified_office' => round($byRule[RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE] ?? 0, 2),
                'manager_descendant_office' => round($byRule[RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE] ?? 0, 2),
                'divisionnaire_office' => round($byRule[RevenueRuleCatalog::DIVISIONNAIRE_OFFICE] ?? 0, 2),
                'secretary_office' => round($byRule[RevenueRuleCatalog::SECRETARY_OFFICE] ?? 0, 2),
                'salary_paid' => $salaryPaid,
                'has_opened_offices' => $member->user_id
                    ? Office::query()->where('creator_user_id', $member->user_id)->exists()
                    : false,
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
            $member->loadMissing('role:id,slug');
            $this->savings->postCompensation(
                $member,
                ! $this->hierarchy->remuneratedAsAssistant($member)
                    && RevenueRuleCatalog::usesSplitCompensationAccounts($member->role?->slug) ? 'salary' : 'secretary',
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
        $managerPercent = $this->managerPercent($product);
        $office = $context['office_model'];
        $manager = $context['manager'];
        $lineSnapshot = array_merge($publicSnapshot, [
            'product' => $product ? ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku] : null,
            'unit_price' => $price,
            'quantity' => $qty,
            'sales' => $sales,
            'manager_percent' => $managerPercent,
        ]);

        // Local office production share (product %): manager, VP, partner or divisionnaire
        // acting as office manager all receive the same package on their office sales.
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
                array_merge($lineSnapshot, [
                    'office_manager_role' => $this->hierarchy->roleSlug($manager),
                ])
            );
        }

        $amRule = $rules->get(RevenueRuleCatalog::ASSISTANT_MANAGER);
        $assistant = $context['assistant'];
        $amDeducted = 0.0;
        // Keep manager_sale GROSS on the share; AM payouts are debited from Compte Salaire at post time.
        $remainingForAm = $managerShare ? (float) $managerShare->amount : 0.0;
        $amPercent = $amRule ? (float) $amRule->percent : 0.0;
        if ($assistant && $amRule && $amPercent > 0 && $managerShare) {
            $divisor = (float) ($amRule->divisor ?? 0);
            if ($divisor <= 0) {
                $divisor = 0;
            }
            $amAmount = RevenueRuleCatalog::assistantShare($sales, $divisor, $amPercent);
            $amAmount = min($amAmount, $remainingForAm);
            if ($amAmount > 0) {
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $amRule,
                    $assistant,
                    $sales,
                    $amPercent,
                    $amAmount,
                    RevenueRuleCatalog::formula(RevenueRuleCatalog::ASSISTANT_MANAGER, $sales, $amPercent, $divisor),
                    array_merge($lineSnapshot, [
                        'team_sales' => $sales,
                        'divisor' => $divisor,
                        'assistant_percent' => $amPercent,
                        'paid_from_manager_salary' => true,
                    ]),
                    $managerShare->id
                );
                $amDeducted += $amAmount;
                $remainingForAm = round($remainingForAm - $amAmount, 2);
            }

            // Certified upline AM: +1/8 of this sub-AM's team production, paid from manager Salaire.
            $certifiedSponsor = $context['certified_assistant_sponsor_model'] ?? null;
            if (
                $certifiedSponsor
                && $remainingForAm > 0
                && $this->memberIsCertified($certifiedSponsor)
            ) {
                $certifiedDivisor = RevenueRuleCatalog::CERTIFIED_ASSISTANT_DIVISOR;
                $certifiedAmount = RevenueRuleCatalog::certifiedAssistantShare($sales, $certifiedDivisor);
                $certifiedAmount = min($certifiedAmount, $remainingForAm);
                if ($certifiedAmount > 0) {
                    $this->storeShare(
                        $sale,
                        $item,
                        $office?->id,
                        $amRule,
                        $certifiedSponsor,
                        $sales,
                        0,
                        $certifiedAmount,
                        RevenueRuleCatalog::formula(
                            RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED,
                            $sales,
                            0,
                            $certifiedDivisor
                        ),
                        array_merge($lineSnapshot, [
                            'team_sales' => $sales,
                            'divisor' => $certifiedDivisor,
                            'sub_assistant_manager' => $context['assistant_manager'] ?? null,
                            'assistant_certified_surplus' => true,
                            'paid_from_manager_salary' => true,
                        ]),
                        $managerShare->id,
                        RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED
                    );
                    $amDeducted += $certifiedAmount;
                    $remainingForAm = round($remainingForAm - $certifiedAmount, 2);
                }
            }

            if ($amDeducted > 0) {
                $gross = (float) $managerShare->amount;
                $managerShare->forceFill([
                    'amount' => round($gross - $amDeducted, 2),
                    'formula' => RevenueRuleCatalog::formula(RevenueRuleCatalog::MANAGER_SALE, $sales, $managerPercent)
                        .' − AM '.$amDeducted.' (Salaire)',
                    'snapshot' => array_merge($managerShare->snapshot ?? [], [
                        'assistant_deducted' => $amDeducted,
                        'manager_sale_gross' => $gross,
                        'manager_sale_net_after_am' => round($gross - $amDeducted, 2),
                        'pay_assistants_from_salary' => true,
                    ]),
                ])->save();
            }
        }

        $directRule = $rules->get(RevenueRuleCatalog::MANAGER_OFFICE);
        $descendantRule = $rules->get(RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE);
        // Hierarchy % uses the office leader's share before assistant payouts.
        // Assistants are paid from that leader's own money, so they do not reduce this base.
        $leaderRole = $this->hierarchy->roleSlug($manager);
        $leaderLabel = $leaderRole === 'divisionnaire' ? 'divisionnaire' : 'manager';
        $leaderGross = $managerShare
            ? round((float) $managerShare->amount, 2)
            : RevenueRuleCatalog::percentOf($sales, $managerPercent);
        $officeBase = RevenueRuleCatalog::officeSalesAfterLeaderShare($sales, $leaderGross);
        $hierarchySnapshot = array_merge($lineSnapshot, [
            'leader_share_before_assistants' => $leaderGross,
            'leader_role' => $leaderLabel,
            'assistant_deducted' => $amDeducted,
        ]);

        // First cut is the office leader (manager or divisionnaire), already removed as
        // their gross share before assistants. Their own office % is taken next.
        // Everyone else in the hierarchy is then subtracted from what remains.
        $remaining = $officeBase;
        $leaderId = $manager?->id ? (int) $manager->id : null;
        $divRule = $rules->get(RevenueRuleCatalog::DIVISIONNAIRE_OFFICE);
        $divPercent = $divRule ? (float) $divRule->percent : 0.0;
        $divDivisor = $divRule && $divRule->divisor !== null ? (float) $divRule->divisor : null;
        $secretaryRule = $rules->get(RevenueRuleCatalog::SECRETARY_OFFICE);

        $deduct = function (float $amount) use (&$remaining): void {
            $remaining = max(0.0, round($remaining - $amount, 2));
        };

        if ($manager && $leaderLabel === 'divisionnaire' && $divRule && $divPercent > 0 && $remaining > 0) {
            $base = $remaining;
            $divAmount = RevenueRuleCatalog::scaledShare($base, $divPercent, $divDivisor);
            if ($divAmount > 0) {
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $divRule,
                    $manager,
                    $base,
                    $divPercent,
                    $divAmount,
                    RevenueRuleCatalog::formula($divRule->code, $base, $divPercent, $divDivisor),
                    array_merge($hierarchySnapshot, [
                        'divisor' => $divDivisor,
                        'cascade_base' => $base,
                        'office_leader' => true,
                    ])
                );
                $deduct($divAmount);
            }
        } elseif ($manager && $directRule && (float) $directRule->percent > 0 && $remaining > 0) {
            $base = $remaining;
            $percent = (float) $directRule->percent;
            $amount = RevenueRuleCatalog::percentOf($base, $percent);
            if ($amount > 0) {
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $directRule,
                    $manager,
                    $base,
                    $percent,
                    $amount,
                    RevenueRuleCatalog::formula($directRule->code, $base, $percent),
                    array_merge($hierarchySnapshot, [
                        'cascade_base' => $base,
                        'office_leader' => true,
                    ])
                );
                $deduct($amount);
            }
        }

        /** @var array<int, array{member: KamsMember, rule: KamsRevenueRule, percent: float, divisor: float|null, code: string}> $certifiedQueue */
        $certifiedQueue = [];
        $queueCertified = function (KamsMember $beneficiary, KamsRevenueRule $rule, float $percent, ?float $divisor, string $code) use (&$certifiedQueue): void {
            $certifiedQueue[(int) $beneficiary->id] = [
                'member' => $beneficiary,
                'rule' => $rule,
                'percent' => $percent,
                'divisor' => $divisor,
                'code' => $code,
            ];
        };
        if ($manager && $leaderLabel === 'divisionnaire' && $divRule && $divPercent > 0) {
            $queueCertified($manager, $divRule, $divPercent, $divDivisor, RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE);
        } elseif ($manager && $directRule && (float) $directRule->percent > 0) {
            $queueCertified($manager, $directRule, (float) $directRule->percent, null, RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE);
        }

        $paidIds = $leaderId ? [$leaderId => true] : [];

        $payMember = function (
            KamsMember $beneficiary,
            KamsRevenueRule $rule,
            float $percent,
            ?float $divisor,
            ?string $ruleCode,
            array $extra,
        ) use (&$remaining, &$paidIds, $deduct, $sale, $item, $office, $hierarchySnapshot): void {
            $memberId = (int) $beneficiary->id;
            if (isset($paidIds[$memberId]) || $remaining <= 0 || $percent <= 0) {
                return;
            }
            $base = $remaining;
            $amount = RevenueRuleCatalog::scaledShare($base, $percent, $divisor);
            if ($amount <= 0) {
                return;
            }
            $code = $ruleCode ?: $rule->code;
            $this->storeShare(
                $sale,
                $item,
                $office?->id,
                $rule,
                $beneficiary,
                $base,
                $percent,
                $amount,
                RevenueRuleCatalog::formula($code, $base, $percent, $divisor),
                array_merge($hierarchySnapshot, $extra, [
                    'cascade_base' => $base,
                    'divisor' => $divisor,
                ]),
                null,
                $ruleCode,
            );
            $deduct($amount);
            $paidIds[$memberId] = true;
        };

        // 2. Divisionnaires above this office, closest first.
        if ($divRule && $divPercent > 0) {
            foreach ($context['divisionnaire_models'] as $divisionnaire) {
                $queueCertified($divisionnaire, $divRule, $divPercent, $divDivisor, RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE);
                $payMember($divisionnaire, $divRule, $divPercent, $divDivisor, null, []);
            }
        }

        // 3. Manager or divisionnaire who opened this office.
        $creator = $context['creator'] ?? null;
        if ($creator instanceof KamsMember) {
            if ($this->hierarchy->roleSlug($creator) === 'divisionnaire' && $divRule && $divPercent > 0) {
                $queueCertified($creator, $divRule, $divPercent, $divDivisor, RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE);
                $payMember($creator, $divRule, $divPercent, $divDivisor, null, ['office_opener' => true]);
            } elseif ($directRule && (float) $directRule->percent > 0) {
                $queueCertified($creator, $directRule, (float) $directRule->percent, null, RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE);
                $payMember($creator, $directRule, (float) $directRule->percent, null, null, ['office_opener' => true]);
            }
        }

        // 4. Secretary only on the direct child office of her manager, never on grandchildren.
        if ($secretaryRule && (float) $secretaryRule->percent > 0 && $remaining > 0) {
            foreach ($context['secretary_models'] as $secretary) {
                $base = $remaining;
                $percent = (float) $secretaryRule->percent;
                $amount = RevenueRuleCatalog::percentOf($base, $percent);
                if ($amount <= 0) {
                    continue;
                }
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $secretaryRule,
                    $secretary,
                    $base,
                    $percent,
                    $amount,
                    RevenueRuleCatalog::formula($secretaryRule->code, $base, $percent),
                    array_merge($hierarchySnapshot, [
                        'cascade_base' => $base,
                        'secretary_direct_child_only' => true,
                    ])
                );
                $deduct($amount);
            }
        }

        // 5+. Whoever opened each office above, on what remains.
        if ($office) {
            foreach ($this->hierarchy->ancestorOpeners($office) as $opener) {
                if ($this->hierarchy->roleSlug($opener) === 'divisionnaire' && $divRule && $divPercent > 0) {
                    $queueCertified($opener, $divRule, $divPercent, $divDivisor, RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE);
                    $payMember($opener, $divRule, $divPercent, $divDivisor, null, ['ancestor_opener' => true]);
                } elseif ($descendantRule && (float) $descendantRule->percent > 0) {
                    $queueCertified($opener, $descendantRule, (float) $descendantRule->percent, null, RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE);
                    $payMember($opener, $descendantRule, (float) $descendantRule->percent, null, null, ['ancestor_opener' => true]);
                }
            }
        }

        // Certified surplus is taken on the remainder left after every hierarchy payment.
        $finalBase = $remaining;
        if ($finalBase > 0) {
            foreach ($certifiedQueue as $row) {
                if (! $this->memberIsCertified($row['member'])) {
                    continue;
                }
                $certifiedAmount = RevenueRuleCatalog::scaledShare($finalBase, $row['percent'], $row['divisor']);
                if ($certifiedAmount <= 0) {
                    continue;
                }
                $this->storeShare(
                    $sale,
                    $item,
                    $office?->id,
                    $row['rule'],
                    $row['member'],
                    $finalBase,
                    $row['percent'],
                    $certifiedAmount,
                    RevenueRuleCatalog::formula($row['code'], $finalBase, $row['percent'], $row['divisor']),
                    array_merge($hierarchySnapshot, [
                        'certified_surplus' => true,
                        'certified_base' => $finalBase,
                        'cascade_base' => $finalBase,
                        'divisor' => $row['divisor'],
                        'after_all_hierarchy_payments' => true,
                    ]),
                    null,
                    $row['code'],
                );
            }
        }
    }

    private function managerPercent(?KamsProduct $product): float
    {
        // Manager sale share is defined per product (not via finance settings).
        if (! $product || $product->manager_share_percent === null) {
            return 0.0;
        }

        return max(0.0, (float) $product->manager_share_percent);
    }

    private function memberIsCertified(?KamsMember $member): bool
    {
        if (! $member?->user_id) {
            return false;
        }

        $user = User::query()->with('roles:id,slug')->find($member->user_id);
        if (! $user || ! $user->is_certified) {
            return false;
        }

        $slugs = $user->roles->pluck('slug')->filter()->all();
        $memberSlug = $member->role?->slug ?: \App\Models\Role::query()->whereKey($member->role_id)->value('slug');
        if ($memberSlug) {
            $slugs[] = $memberSlug;
        }

        return collect($slugs)->intersect(User::CERTIFIABLE_ROLE_SLUGS)->isNotEmpty();
    }

    private function creditsOnDayClose(KamsRevenueShare $share): bool
    {
        $member = $share->beneficiary;
        if (! $member && $share->beneficiary_member_id) {
            $member = KamsMember::query()->find($share->beneficiary_member_id);
        }

        return RevenueRuleCatalog::creditsOnDayClose(
            (string) $share->rule_code,
            $share->office_id ? (int) $share->office_id : null,
            $member?->office_id ? (int) $member->office_id : null,
        );
    }

    /**
     * A day already closed booked the office deposit before this own-office share
     * was credited. Shrink that deposit by the same amount the new wallet mirror adds.
     */
    private function reducePriorOfficeCollection(KamsRevenueShare $share): void
    {
        $reference = $share->sale?->reference;
        $amount = round((float) $share->amount, 2);
        if (! $reference || $amount <= 0) {
            return;
        }

        $entry = KamsTreasuryEntry::query()
            ->where('type', 'deposit')
            ->where('status', 'posted')
            ->where('notes', 'Encaissement clôture '.$reference)
            ->first();
        if (! $entry) {
            return;
        }

        $entry->forceFill([
            'amount' => round((float) $entry->amount - $amount, 2),
        ])->save();

        $account = KamsTreasuryAccount::query()->find($entry->account_id);
        if ($account) {
            app(TreasuryLedger::class)->refreshBalance($account);
        }
    }

    private function isAssistantShare(KamsRevenueShare $share): bool
    {
        return in_array((string) $share->rule_code, [
            RevenueRuleCatalog::ASSISTANT_MANAGER,
            RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED,
        ], true);
    }

    private function postShareToLedger(KamsRevenueShare $share, ?KamsStockSale $sale = null): void
    {
        $member = $share->beneficiary;
        if (! $member && $share->beneficiary_member_id) {
            $member = KamsMember::query()->with('role:id,slug')->find($share->beneficiary_member_id);
        }
        if (! $member || (float) $share->amount == 0.0) {
            return;
        }
        $member->loadMissing('role:id,slug');

        $kind = RevenueRuleCatalog::compensationAccountKind(
            (string) $share->rule_code,
            $member->role?->slug,
            $share->office_id ? (int) $share->office_id : null,
            $member->office_id ? (int) $member->office_id : null,
        );
        $date = $sale?->sold_at?->toDateString()
            ?: $share->created_at?->toDateString()
            ?: now()->toDateString();
        $reference = $sale?->reference ? ' · '.$sale->reference : '';
        $this->savings->postCompensation(
            $member,
            $kind,
            (float) $share->amount,
            'revenue_share',
            $share,
            $date,
            trim(($share->formula ?: $share->rule_code).$reference)
        );

        if ($this->isAssistantShare($share)) {
            $this->postManagerAssistantPayout($share, $sale);
        }
    }

    /**
     * Debit the office manager's Compte Salaire for an assistant payout (traceable counterpart of the AM credit).
     */
    private function postManagerAssistantPayout(KamsRevenueShare $share, ?KamsStockSale $sale = null): void
    {
        if (! $this->isAssistantShare($share) || ! $share->parent_share_id || (float) $share->amount == 0.0) {
            return;
        }
        $snapshot = is_array($share->snapshot) ? $share->snapshot : [];
        if (! ($snapshot['paid_from_manager_salary'] ?? false)) {
            return;
        }

        $parent = $share->parentShare;
        if (! $parent) {
            $parent = KamsRevenueShare::query()->find($share->parent_share_id);
        }
        if (! $parent?->beneficiary_member_id) {
            return;
        }
        // Manager share stays payable on the office and is already net of assistants.
        if (in_array((string) $parent->status, ['accrued', 'payable'], true)) {
            return;
        }

        $manager = KamsMember::query()->with('role:id,slug')->find($parent->beneficiary_member_id);
        if (! $manager) {
            return;
        }

        $assistant = $share->beneficiary;
        if (! $assistant && $share->beneficiary_member_id) {
            $assistant = KamsMember::query()->find($share->beneficiary_member_id);
        }
        $assistantName = $assistant?->full_name ?: ('#'.$share->beneficiary_member_id);
        $date = $sale?->sold_at?->toDateString()
            ?: $share->created_at?->toDateString()
            ?: now()->toDateString();
        $reference = $sale?->reference ?: '';
        $ruleLabel = (string) $share->rule_code === RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED
            ? 'AM certifié'
            : 'AM';
        $notes = trim(sprintf(
            'Versement assistant %s (%s)%s',
            $assistantName,
            $ruleLabel,
            $reference !== '' ? ' · '.$reference : ''
        ));

        $this->savings->postCompensation(
            $manager,
            'salary',
            -abs((float) $share->amount),
            'assistant_payout',
            $share,
            $date,
            $notes
        );
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
        ?string $ruleCodeOverride = null,
    ): KamsRevenueShare {
        return KamsRevenueShare::query()->create([
            'company_id' => $sale->company_id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'product_id' => $item->product_id,
            'office_id' => $officeId,
            'rule_id' => $rule->id,
            'rule_code' => $ruleCodeOverride ?: $rule->code,
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
            'certified_assistant_sponsor' => $context['certified_assistant_sponsor'] ?? null,
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
