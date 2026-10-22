<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\RoleCatalog;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsFixedCharge;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use App\Modules\Kams\Services\FixedChargeService;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use App\Modules\Kams\Services\KamsFinanceService;
use App\Modules\Kams\Services\OfficeCommissionService;
use App\Modules\Kams\Services\SavingsLedger;
use App\Modules\Kams\Services\StockMetrics;
use App\Modules\Kams\Services\TreasuryLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class KamsFinanceController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    public function dashboard(
        Request $request,
        StockMetrics $metrics,
        KamsFinanceService $finance,
        TreasuryLedger $treasury,
    ): JsonResponse {
        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $officeIds = $officeState['office_ids'];
        $canFilter = $officeState['can_filter'];
        $selectedOfficeId = $officeState['selected_office_id'];
        $filterOffices = $officeState['offices'];
        $restricted = ! $request->user()->isGroupAdmin() && $request->user()->isOfficeScoped();

        $groupBy = in_array($request->string('group_by')->toString(), ['day', 'week', 'month', 'custom'], true)
            ? $request->string('group_by')->toString()
            : 'week';
        $range = $this->resolveFinanceRange($groupBy, $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null);
        $previous = $this->previousFinanceRange($range['from'], $range['to']);

        $payload = $metrics->finance('custom', $companyId, $officeIds, $range['from'], $range['to']);
        $position = $finance->summarize($companyId, $range['from'], $range['to'], $officeIds);
        $previousPosition = $finance->summarize($companyId, $previous['from'], $previous['to'], $officeIds);
        $savings = $finance->savingsByKind($companyId, $officeIds);
        $inventory = round((float) ($payload['totals']['inventory_value'] ?? 0), 2);
        $patrimoine = round((float) ($position['patrimoine_value'] ?? 0), 2);
        $equity = round((float) $position['assets'] - (float) $position['liabilities'], 2);
        $includeCentral = $selectedOfficeId === null && ! $restricted;
        $treasuryAccounts = $companyId
            ? $treasury->overview((int) $companyId, $officeIds, $includeCentral)
            : [];
        $treasuryBalance = round(array_sum(array_map(
            fn (array $account) => (float) ($account['balance'] ?? 0),
            $treasuryAccounts,
        )), 2);
        $cashFlow = $finance->cashFlow($companyId, $range['from'], $range['to'], $officeIds);
        $cashFlowTotals = [
            'deposits' => round(array_sum(array_column($cashFlow, 'deposits')), 2),
            'withdrawals' => round(array_sum(array_column($cashFlow, 'withdrawals')), 2),
            'expenses' => round(array_sum(array_column($cashFlow, 'expenses')), 2),
            'net' => round(array_sum(array_column($cashFlow, 'net')), 2),
        ];

        $payload['group_by'] = $groupBy;
        $payload['range'] = $range;
        $payload['previous_range'] = $previous;
        $payload['position'] = array_merge($position, [
            'equity' => $equity,
            'inventory_value' => $inventory,
            'patrimoine_value' => $patrimoine,
            'net_margin' => round((float) $position['profit'] - (float) $position['expenses'] - (float) ($position['fixed_charges'] ?? 0), 2),
            'treasury_balance' => $treasuryBalance,
        ]);
        $payload['position_delta'] = $this->deltaMap($position, $previousPosition);
        $payload['trend'] = $finance->trend($companyId, $range['from'], $range['to'], $officeIds);
        $payload['cash_flow'] = $cashFlow;
        $payload['cash_flow_totals'] = $cashFlowTotals;
        $payload['treasury'] = [
            'balance' => $treasuryBalance,
            'accounts' => $treasuryAccounts,
        ];
        $payload['savings'] = $savings;
        $payload = array_merge($payload, $this->officeFilterPayload($officeState, $companyId));

        return $this->ok($payload);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function resolveFinanceRange(string $groupBy, ?string $from, ?string $to): array
    {
        $hasCustomDates = is_string($from) && is_string($to)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);

        if ($hasCustomDates) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->startOfDay();
            $today = now()->startOfDay();
            if ($end->greaterThan($today)) {
                $end = $today;
            }
            if ($start->greaterThan($end)) {
                [$start, $end] = [$end, $start];
            }

            return ['from' => $start->toDateString(), 'to' => $end->toDateString()];
        }

        $end = now()->startOfDay();

        return match ($groupBy) {
            'day' => ['from' => $end->toDateString(), 'to' => $end->toDateString()],
            'month' => [
                'from' => $end->copy()->startOfMonth()->subMonths(11)->toDateString(),
                'to' => $end->toDateString(),
            ],
            default => [
                'from' => $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(7)->toDateString(),
                'to' => $end->toDateString(),
            ],
        };
    }

    /**
     * @return array{from: string, to: string}
     */
    private function previousFinanceRange(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return [
            'from' => $prevStart->toDateString(),
            'to' => $prevEnd->toDateString(),
        ];
    }

    /**
     * @param  array<string, float|int>  $current
     * @param  array<string, float|int>  $previous
     * @return array<string, float|null>
     */
    private function deltaMap(array $current, array $previous): array
    {
        $deltas = [];
        foreach ($current as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $prev = (float) ($previous[$key] ?? 0);
            $curr = (float) $value;
            if (abs($prev) < 0.00001) {
                $deltas[$key] = abs($curr) < 0.00001 ? 0.0 : null;
                continue;
            }
            $deltas[$key] = round((($curr - $prev) / abs($prev)) * 100, 1);
        }

        return $deltas;
    }

    public function memberAccounts(Request $request, SavingsLedger $ledger): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $payload = $ledger->memberAccounts(
            $companyId,
            $officeState['office_ids'],
        );
        $payload = array_merge($payload, $this->officeFilterPayload($officeState));

        return $this->ok($payload);
    }

    public function account(Request $request, SavingsLedger $ledger, KamsAccountProvisioner $accounts): JsonResponse
    {
        if ($request->filled('member_id')) {
            $member = $this->memberFor($request, $accounts);

            return $this->ok($this->accountPayload($request, $member, $ledger, $accounts));
        }

        $member = $accounts->tryForUser($request->user(), $this->kamsCompanyId($request));
        if (! $member) {
            return $this->ok(null);
        }

        return $this->ok($this->accountPayload($request, $member, $ledger, $accounts));
    }

    public function deposit(Request $request, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $member = $this->memberFor($request, $accounts);
        abort_unless($this->ownsAccount($request, $member), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'account_kind' => ['nullable', 'string', 'in:guy,salary,percentage,manager,assistant_manager,secretary,divisionnaire'],
        ]);
        $entry = $this->postsOwnMovementsDirectly($request)
            ? $treasury->deposit(
                $treasury->accountForMember($member),
                (float) $data['amount'],
                (string) ($data['notes'] ?? ''),
                $request->user()->id,
                $member,
                $data['account_kind'] ?? null,
            )
            : $treasury->requestDeposit(
                $member,
                (float) $data['amount'],
                (string) ($data['notes'] ?? ''),
                $request->user()->id,
                $data['account_kind'] ?? null,
            );

        return $this->created(
            $this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts),
            $this->postsOwnMovementsDirectly($request) ? __('Deposit recorded.') : __('Deposit requested.'),
        );
    }

    public function withdraw(Request $request, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $member = $this->memberFor($request, $accounts);
        abort_unless($this->ownsAccount($request, $member), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'account_kind' => ['nullable', 'string', 'in:guy,salary,percentage,manager,assistant_manager,secretary,divisionnaire'],
        ]);
        $entry = $this->postsOwnMovementsDirectly($request)
            ? $treasury->withdraw(
                $treasury->accountForMember($member),
                (float) $data['amount'],
                (string) ($data['notes'] ?? ''),
                $request->user()->id,
                $member,
                $data['account_kind'] ?? null,
            )
            : $treasury->requestWithdrawal(
                $member,
                (float) $data['amount'],
                (string) ($data['notes'] ?? ''),
                $request->user()->id,
                $data['account_kind'] ?? null,
            );

        return $this->created(
            $this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts),
            $this->postsOwnMovementsDirectly($request) ? __('Withdrawal recorded.') : __('Withdrawal requested.'),
        );
    }

    public function postDeposit(Request $request, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        abort_unless($this->canPostAgentMovement($request, 'deposit'), 403);
        $member = $this->memberFor($request, $accounts);
        $this->assertCashierCanAccessMember($request, $member);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'member_id' => ['required', 'integer', 'exists:kams_members,id'],
        ]);
        $account = $treasury->accountForMember($member);
        $entry = $treasury->deposit($account, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id, $member);

        return $this->created($this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts), __('Deposit recorded.'));
    }

    public function postWithdraw(Request $request, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        abort_unless($this->canPostAgentMovement($request, 'withdraw'), 403);
        $member = $this->memberFor($request, $accounts);
        $this->assertCashierCanAccessMember($request, $member);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'member_id' => ['required', 'integer', 'exists:kams_members,id'],
        ]);
        $account = $treasury->accountForMember($member);
        $entry = $treasury->withdraw($account, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id, $member);

        return $this->created($this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts), __('Withdrawal recorded.'));
    }

    public function accountApprove(Request $request, KamsSavingsEntry $savingsEntry, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        return $this->accountDecide($request, $savingsEntry, $ledger, $treasury, $accounts, 'approve');
    }

    public function accountReject(Request $request, KamsSavingsEntry $savingsEntry, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        return $this->accountDecide($request, $savingsEntry, $ledger, $treasury, $accounts, 'reject');
    }

    public function companyApprove(Request $request, KamsTreasuryAccount $treasuryAccount, KamsTreasuryEntry $treasuryEntry, TreasuryLedger $treasury): JsonResponse
    {
        return $this->companyDecide($request, $treasuryAccount, $treasuryEntry, $treasury, 'approve');
    }

    public function companyReject(Request $request, KamsTreasuryAccount $treasuryAccount, KamsTreasuryEntry $treasuryEntry, TreasuryLedger $treasury): JsonResponse
    {
        return $this->companyDecide($request, $treasuryAccount, $treasuryEntry, $treasury, 'reject');
    }

    public function companyAccounts(Request $request, TreasuryLedger $treasury): JsonResponse
    {
        abort_unless($this->canViewCompanyAccounts($request), 403);
        $companyId = $this->kamsCompanyId($request);
        if (! $companyId) {
            return $this->ok([]);
        }
        $officeScoped = ! $request->user()->isGroupAdmin() && $request->user()->isOfficeScoped();
        $officeIds = $officeScoped ? $request->user()->accessibleOfficeIds() : [];

        // Principal/central treasury is company-wide only — office-scoped roles
        // (manager, secretary, …) see their office accounts, not the central one.
        return $this->ok($treasury->overview((int) $companyId, $officeIds, ! $officeScoped));
    }

    public function companyAccount(Request $request, KamsTreasuryAccount $treasuryAccount, TreasuryLedger $treasury): JsonResponse
    {
        $this->assertTreasuryAccess($request, $treasuryAccount);

        return $this->ok($this->treasuryPayload($request, $treasuryAccount, $treasury));
    }

    public function companyDeposit(Request $request, KamsTreasuryAccount $treasuryAccount, TreasuryLedger $treasury): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.deposit');
        abort_unless(! $treasuryAccount->isCentral(), 422, __('Deposits to the principal account must be requested from an office account.'));

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $treasury->requestOfficeBankDeposit(
            $treasuryAccount,
            (float) $data['amount'],
            (string) $data['occurred_on'],
            (string) ($data['notes'] ?? ''),
            $request->user()->id,
        );

        return $this->created(
            $this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury),
            __('Deposit requested. Waiting for principal account validation.'),
        );
    }

    public function companyWithdraw(Request $request, KamsTreasuryAccount $treasuryAccount, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.withdraw');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'member_id' => ['nullable', 'integer', 'exists:kams_members,id'],
        ]);
        $member = $this->memberForTreasury($request, $treasuryAccount, $accounts, $data['member_id'] ?? null);
        $entry = $treasury->withdraw($treasuryAccount, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id, $member);

        return $this->created($this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury), __('Withdrawal recorded.'));
    }

    public function companyExpense(Request $request, KamsTreasuryAccount $treasuryAccount, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.expense');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'member_id' => ['nullable', 'integer', 'exists:kams_members,id'],
        ]);
        $member = $this->memberForTreasury($request, $treasuryAccount, $accounts, $data['member_id'] ?? null);
        $entry = $treasury->expense($treasuryAccount, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id, $member);

        return $this->created($this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury), __('Expense recorded.'));
    }

    public function companyCommissions(Request $request, KamsTreasuryAccount $treasuryAccount, OfficeCommissionService $commissions): JsonResponse
    {
        $this->assertTreasuryAccess($request, $treasuryAccount);
        abort_unless($treasuryAccount->office_id, 422, __('Commissions are only available on office accounts.'));
        $treasuryAccount->loadMissing('office:id,name,code');
        $beneficiaries = $commissions->beneficiariesForOffice($treasuryAccount);

        return $this->ok([
            'account' => [
                'id' => $treasuryAccount->id,
                'office' => $treasuryAccount->office?->only(['id', 'name', 'code']),
            ],
            'beneficiaries' => $beneficiaries,
            'total' => round(array_sum(array_column($beneficiaries, 'amount')), 2),
            'can_transfer' => $this->hasFinancePermission($request, 'kams.finance.company.deposit'),
        ]);
    }

    public function companyCommissionDetail(
        Request $request,
        KamsTreasuryAccount $treasuryAccount,
        KamsMember $member,
        OfficeCommissionService $commissions,
    ): JsonResponse {
        $this->assertTreasuryAccess($request, $treasuryAccount);
        abort_unless($treasuryAccount->office_id, 422, __('Commissions are only available on office accounts.'));
        $this->assertCompany($request, (int) $member->company_id);

        return $this->ok($commissions->beneficiaryDetail($treasuryAccount, $member));
    }

    public function companyCommissionTransfer(
        Request $request,
        KamsTreasuryAccount $treasuryAccount,
        KamsMember $member,
        OfficeCommissionService $commissions,
        TreasuryLedger $treasury,
    ): JsonResponse {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.deposit');
        abort_unless($treasuryAccount->office_id, 422, __('Commissions are only available on office accounts.'));
        $this->assertCompany($request, (int) $member->company_id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $treasuryAccount->loadMissing('office:id,name,code');
        $commissions->transfer(
            $treasuryAccount,
            $member,
            $request->user()->id,
            (float) $data['amount'],
            $data['notes'] ?? null,
        );
        $beneficiaries = $commissions->beneficiariesForOffice($treasuryAccount);

        return $this->created(
            [
                'commissions' => [
                    'account' => [
                        'id' => $treasuryAccount->id,
                        'office' => $treasuryAccount->office?->only(['id', 'name', 'code']),
                    ],
                    'beneficiaries' => $beneficiaries,
                    'total' => round(array_sum(array_column($beneficiaries, 'amount')), 2),
                    'can_transfer' => $this->hasFinancePermission($request, 'kams.finance.company.deposit'),
                ],
                'statement' => $this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury),
            ],
            __('Commission transfer requested. Waiting for the beneficiary to accept it in My account.'),
        );
    }

    public function fixedCharges(Request $request, KamsTreasuryAccount $treasuryAccount): JsonResponse
    {
        $this->assertTreasuryAccess($request, $treasuryAccount);

        return $this->ok($this->fixedChargePayload($request, $treasuryAccount));
    }

    public function storeFixedCharge(Request $request, KamsTreasuryAccount $treasuryAccount): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.expense');
        $data = $this->chargeData($request);

        KamsFixedCharge::query()->create([
            'company_id' => $treasuryAccount->company_id,
            'office_id' => $treasuryAccount->office_id,
            'name' => $data['name'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return $this->created($this->fixedChargePayload($request, $treasuryAccount), __('Saved.'));
    }

    public function updateFixedCharge(Request $request, KamsTreasuryAccount $treasuryAccount, KamsFixedCharge $fixedCharge): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.expense');
        $this->assertFixedChargeAccount($treasuryAccount, $fixedCharge);
        $data = $this->chargeData($request);
        $fixedCharge->update($data);

        return $this->ok($this->fixedChargePayload($request, $treasuryAccount), __('Saved.'));
    }

    public function destroyFixedCharge(Request $request, KamsTreasuryAccount $treasuryAccount, KamsFixedCharge $fixedCharge): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.expense');
        $this->assertFixedChargeAccount($treasuryAccount, $fixedCharge);
        $fixedCharge->delete();

        return $this->ok($this->fixedChargePayload($request, $treasuryAccount), __('Deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixedChargePayload(Request $request, KamsTreasuryAccount $treasuryAccount): array
    {
        $charges = KamsFixedCharge::query()
            ->where('company_id', $treasuryAccount->company_id)
            ->when(
                $treasuryAccount->office_id,
                fn ($query) => $query->where('office_id', $treasuryAccount->office_id),
                fn ($query) => $query->whereNull('office_id'),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'amount', 'starts_on', 'days', 'notes', 'is_active']);

        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        return [
            'charges' => $charges,
            'monthly_total' => round((float) $charges->sum('amount'), 2),
            'period_total' => app(FixedChargeService::class)->sum($charges, $from, $to),
            'can_manage' => $this->hasFinancePermission($request, 'kams.finance.company.expense'),
        ];
    }

    /**
     * @return array{name: string, amount: float, notes: ?string}
     */
    private function chargeData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function assertFixedChargeAccount(KamsTreasuryAccount $account, KamsFixedCharge $charge): void
    {
        abort_unless((int) $charge->company_id === (int) $account->company_id, 404);
        abort_unless((int) ($charge->office_id ?? 0) === (int) ($account->office_id ?? 0), 404);
    }

    private function accountPayload(Request $request, KamsMember $member, SavingsLedger $ledger, KamsAccountProvisioner $accounts): array
    {
        $member->loadMissing(['office:id,name,code', 'role:id,name,slug']);
        $payload = $ledger->statement($member);
        $owns = $this->ownsAccount($request, $member);
        $canViewAgents = $this->canViewAgentAccounts($request);
        $payload['can_deposit'] = $owns && $this->hasFinancePermission($request, 'kams.finance.account.deposit');
        $payload['can_withdraw'] = $owns && $this->hasFinancePermission($request, 'kams.finance.account.withdraw');
        $payload['can_direct'] = $owns && $this->postsOwnMovementsDirectly($request);
        $payload['can_post_deposit'] = $this->canPostAgentMovement($request, 'deposit');
        $payload['can_post_withdraw'] = $this->canPostAgentMovement($request, 'withdraw');
        $payload['can_expense'] = false;
        $payload['can_decide'] = $this->hasFinancePermission($request, 'kams.finance.company.approve');
        // Owner can accept/reject incoming commission transfers on Mon compte.
        $payload['can_decide_incoming'] = $owns;
        $payload['can_browse_agents'] = $canViewAgents;
        $officeId = $request->filled('office_id') ? (int) $request->integer('office_id') : null;
        $payload['agents'] = $canViewAgents ? $this->agentOptions($request, $member, $officeId) : [];
        $payload['office_id'] = $officeId;

        return $payload;
    }

    private function companyDecide(
        Request $request,
        KamsTreasuryAccount $treasuryAccount,
        KamsTreasuryEntry $treasuryEntry,
        TreasuryLedger $treasury,
        string $decision,
    ): JsonResponse {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.approve');
        $data = $request->validate([
            'notes' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        abort_unless((int) $treasuryEntry->account_id === (int) $treasuryAccount->id, 404);

        if ($treasuryEntry->type === 'commission_transfer') {
            abort(422, __('Commission transfers must be accepted by the beneficiary in My account.'));
        }

        if ($treasuryEntry->type === 'transfer_deposit') {
            abort_unless($treasuryAccount->isCentral(), 422, __('Bank deposit requests are validated on the principal account.'));
            $user = $request->user();
            abort_unless(
                $user->isGroupAdmin() || ! $user->isOfficeScoped(),
                403,
                __('Only principal account administrators can validate this deposit.'),
            );
            $treasury->decideBankDeposit($treasuryEntry, $decision, $user->id, (string) ($data['notes'] ?? ''));
        } else {
            $treasury->decide($treasuryEntry, $decision, $request->user()->id, (string) ($data['notes'] ?? ''));
        }

        return $this->ok(
            $this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury),
            $decision === 'approve' ? __('Request approved.') : __('Request rejected.'),
        );
    }

    private function accountDecide(
        Request $request,
        KamsSavingsEntry $savingsEntry,
        SavingsLedger $ledger,
        TreasuryLedger $treasury,
        KamsAccountProvisioner $accounts,
        string $decision,
    ): JsonResponse {
        abort_unless($savingsEntry->status === 'pending', 422, __('This request cannot be processed.'));
        abort_unless(in_array($savingsEntry->type, ['deposit_request', 'withdrawal_request'], true), 422, __('This request cannot be processed.'));

        $savingsEntry->loadMissing(['member', 'source']);
        $member = $savingsEntry->member;
        abort_unless($member, 404);
        $this->assertCompany($request, (int) $member->company_id);
        $accounts->ensureWallet($member);

        $data = $request->validate([
            'notes' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        $treasuryEntry = $savingsEntry->source;
        if (! $treasuryEntry instanceof KamsTreasuryEntry) {
            $treasuryEntry = KamsTreasuryEntry::query()
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->whereIn('type', $savingsEntry->type === 'deposit_request'
                    ? ['deposit', 'commission_transfer']
                    : ['withdrawal'])
                ->where('amount', round(abs((float) $savingsEntry->amount), 2))
                ->orderByDesc('id')
                ->first();
        }
        abort_unless($treasuryEntry instanceof KamsTreasuryEntry, 422, __('This request cannot be processed.'));

        $isCommissionTransfer = $treasuryEntry->type === 'commission_transfer';
        if ($isCommissionTransfer) {
            // Beneficiary accepts/rejects their own commission transfer in Mon compte.
            abort_unless($this->ownsAccount($request, $member), 403);
            $treasury->decideCommissionTransfer(
                $treasuryEntry,
                $decision,
                $request->user()->id,
                (string) ($data['notes'] ?? ''),
            );
        } else {
            abort_unless($this->hasFinancePermission($request, 'kams.finance.company.approve'), 403);
            $this->assertCashierCanAccessMember($request, $member);
            $treasury->decide($treasuryEntry, $decision, $request->user()->id, (string) ($data['notes'] ?? ''));
        }

        $request->merge(['member_id' => $member->id]);

        return $this->ok(
            $this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts),
            $decision === 'approve' ? __('Request approved.') : __('Request rejected.'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agentOptions(Request $request, KamsMember $current, ?int $officeId = null): array
    {
        $companyId = $this->kamsCompanyId($request) ?: $current->company_id;
        $query = KamsMember::query()
            ->with('office:id,name,code')
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'verified'])
            ->orderBy('full_name');
        if ($officeId) {
            $query->where('office_id', $officeId);
        }
        if (! $request->user()->isGroupAdmin() && $request->user()->isOfficeScoped()) {
            $accessible = $request->user()->accessibleOfficeIds() ?: [0];
            if ($officeId) {
                abort_unless(in_array($officeId, $accessible, true), 403);
            } else {
                $query->whereIn('office_id', $accessible);
            }
        }

        return $query->get(['id', 'full_name', 'member_code', 'office_id', 'phone'])->map(fn (KamsMember $row) => [
            'id' => $row->id,
            'full_name' => $row->full_name,
            'member_code' => $row->member_code,
            'phone' => $row->phone,
            'office' => $row->office?->only(['id', 'name', 'code']),
        ])->all();
    }

    private function memberFor(Request $request, KamsAccountProvisioner $accounts): KamsMember
    {
        $user = $request->user();
        if ($request->filled('member_id')) {
            abort_unless($this->canViewAgentAccounts($request), 403);
            $member = KamsMember::query()->findOrFail((int) $request->integer('member_id'));
            $this->assertCompany($request, (int) $member->company_id);
            $this->assertCashierCanAccessMember($request, $member);
            $accounts->ensureWallet($member);

            return $member;
        }

        return $accounts->forUser($user, $this->kamsCompanyId($request));
    }

    private function hasFinancePermission(Request $request, string $slug): bool
    {
        $user = $request->user();

        return $user->isGroupAdmin() || $user->hasPermission($slug);
    }

    private function canViewAgentAccounts(Request $request): bool
    {
        return $this->hasFinancePermission($request, 'kams.finance.account.update')
            || $this->hasFinancePermission($request, 'kams.finance.dashboard.view')
            || $this->hasFinancePermission($request, 'kams.finance.company.view')
            || $this->hasFinancePermission($request, 'kams.finance.account.view');
    }

    private function canOperateAgentAccounts(Request $request): bool
    {
        return $this->hasFinancePermission($request, 'kams.finance.account.update');
    }

    private function postsOwnMovementsDirectly(Request $request): bool
    {
        $request->user()->loadMissing('roles');
        $allowed = ['super_administrator', 'president', 'divisionnaire', 'manager', 'secretary'];

        return $request->user()->roles->contains(
            fn ($role) => in_array((string) $role->slug, $allowed, true),
        );
    }

    private function canPostAgentMovement(Request $request, string $action): bool
    {
        $companyPerm = $action === 'withdraw' ? 'kams.finance.company.withdraw' : 'kams.finance.company.deposit';
        $accountPerm = $action === 'withdraw' ? 'kams.finance.account.withdraw' : 'kams.finance.account.deposit';

        if ($this->hasFinancePermission($request, $companyPerm)) {
            return true;
        }

        return $this->canOperateAgentAccounts($request)
            && $this->hasFinancePermission($request, $accountPerm);
    }

    private function canBrowseAgents(Request $request): bool
    {
        return $this->canViewAgentAccounts($request);
    }

    private function canFilterOffices(Request $request): bool
    {
        return $this->hasFinancePermission($request, 'kams.finance.dashboard.update');
    }

    /**
     * @return list<int>
     */
    private function ownOfficeIds(Request $request): array
    {
        $user = $request->user();
        $ids = collect();
        if ($user->primary_office_id) {
            $ids->push((int) $user->primary_office_id);
        }
        $user->loadMissing('scopes');
        foreach ($user->scopes as $scope) {
            if ($scope->scope_type === 'office' && $scope->scope_id) {
                $ids->push((int) $scope->scope_id);
            }
        }
        $memberOffice = KamsMember::query()->where('user_id', $user->id)->value('office_id');
        if ($memberOffice) {
            $ids->push((int) $memberOffice);
        }

        return $ids->filter()->unique()->values()->all();
    }

    private function canViewCompanyAccounts(Request $request): bool
    {
        return $this->hasFinancePermission($request, 'kams.finance.company.view');
    }

    private function ownsAccount(Request $request, KamsMember $member): bool
    {
        $user = $request->user();

        return (int) $member->user_id === (int) $user->id
            || strcasecmp((string) $member->email, (string) $user->email) === 0;
    }

    private function assertCashierCanAccessMember(Request $request, KamsMember $member): void
    {
        if ($request->user()->isGroupAdmin()) {
            return;
        }
        if ($request->user()->isOfficeScoped()) {
            abort_unless(in_array((int) $member->office_id, $request->user()->accessibleOfficeIds(), true), 403);
        }
    }

    private function assertTreasuryAccess(Request $request, KamsTreasuryAccount $account): void
    {
        abort_unless($this->canViewCompanyAccounts($request), 403);
        $this->assertCompany($request, (int) $account->company_id);
        if (! $request->user()->isGroupAdmin() && $request->user()->isOfficeScoped()) {
            // Office-scoped roles cannot open the company principal account.
            abort_unless(! $account->isCentral(), 403);
            abort_unless($account->office_id && in_array((int) $account->office_id, $request->user()->accessibleOfficeIds(), true), 403);
        }
    }

    private function assertTreasuryOperate(Request $request, KamsTreasuryAccount $account, string $permission): void
    {
        abort_unless($this->hasFinancePermission($request, $permission), 403);
        $this->assertTreasuryAccess($request, $account);
    }

    /**
     * @return array<string, mixed>
     */
    private function memberForTreasury(Request $request, KamsTreasuryAccount $account, KamsAccountProvisioner $accounts, mixed $memberId): ?KamsMember
    {
        if (! $memberId) {
            return null;
        }
        $member = KamsMember::query()->findOrFail((int) $memberId);
        $this->assertCompany($request, (int) $member->company_id);
        abort_unless((int) $member->company_id === (int) $account->company_id, 422, __('This member does not belong to this account.'));
        if ($account->office_id) {
            abort_unless((int) $member->office_id === (int) $account->office_id, 422, __('This member does not belong to this office.'));
        } else {
            $member->loadMissing('role');
            abort_unless(in_array((string) $member->role?->slug, RoleCatalog::kamsCentralSlugs(), true), 422, __('This member cannot use the central account.'));
        }
        $this->assertCashierCanAccessMember($request, $member);
        $accounts->ensureWallet($member);

        return $member;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membersForTreasury(KamsTreasuryAccount $account, SavingsLedger $ledger): array
    {
        $query = KamsMember::query()
            ->with('office:id,name,code')
            ->where('company_id', $account->company_id)
            ->whereIn('status', ['active', 'verified'])
            ->orderBy('full_name');
        if ($account->office_id) {
            $query->where('office_id', $account->office_id);
        } else {
            $query->whereHas('role', fn ($q) => $q->whereIn('slug', RoleCatalog::kamsCentralSlugs()));
        }
        $rows = $query->get(['id', 'full_name', 'member_code', 'office_id', 'role_id']);
        $memberIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $balances = $ledger->withdrawableBalancesFor($memberIds);

        return $rows->map(fn (KamsMember $row) => [
            'id' => $row->id,
            'full_name' => $row->full_name,
            'member_code' => $row->member_code,
            'office' => $row->office?->only(['id', 'name', 'code']),
            'balance' => $balances[$row->id] ?? 0.0,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function treasuryPayload(Request $request, KamsTreasuryAccount $account, TreasuryLedger $treasury): array
    {
        $payload = $treasury->statement($account);
        // Deposit = request transfer from office → principal bank (never a direct credit on the bank).
        $payload['can_deposit'] = ! $account->isCentral()
            && $this->hasFinancePermission($request, 'kams.finance.company.deposit');
        $payload['can_withdraw'] = $this->hasFinancePermission($request, 'kams.finance.company.withdraw');
        $payload['can_expense'] = $this->hasFinancePermission($request, 'kams.finance.company.expense');
        $payload['can_operate'] = $payload['can_deposit'] || $payload['can_withdraw'] || $payload['can_expense'];
        $payload['can_decide'] = $this->hasFinancePermission($request, 'kams.finance.company.approve');
        $payload['members'] = $this->membersForTreasury($account, app(SavingsLedger::class));

        return $payload;
    }
}
