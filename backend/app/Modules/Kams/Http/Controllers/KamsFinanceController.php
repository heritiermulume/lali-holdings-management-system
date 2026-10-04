<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\AuditLogger;
use App\Core\Support\RoleCatalog;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use App\Modules\Kams\Services\SavingsLedger;
use App\Modules\Kams\Services\StockMetrics;
use App\Modules\Kams\Services\TreasuryLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KamsFinanceController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    public function dashboard(Request $request, StockMetrics $metrics): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $user = $request->user();
        $restricted = ! $user->isGroupAdmin() && $user->isOfficeScoped();
        $scopedIds = $restricted ? $this->ownOfficeIds($request) : [];

        $filterOffices = $this->kamsOffices($request)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->when($restricted, fn ($q) => $q->whereIn('id', $scopedIds ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $officeIds = $restricted
            ? $filterOffices->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0]
            : [];
        $canFilter = $this->canFilterOffices($request) && $filterOffices->count() > 1;

        if ($canFilter && $request->filled('office_id')) {
            $officeId = (int) $request->integer('office_id');
            $this->assertKamsOffice($request, $officeId);
            $officeIds = [$officeId];
        }

        $payload = $metrics->finance(
            $request->string('group_by')->toString() ?: 'week',
            $companyId,
            $officeIds,
        );
        $payload['can_filter_offices'] = $canFilter;
        $payload['office_id'] = $canFilter && $request->filled('office_id') ? (int) $request->integer('office_id') : null;
        $payload['filter_offices'] = $canFilter ? $filterOffices->map(fn ($office) => [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
        ])->values()->all() : [];

        return $this->ok($payload);
    }

    public function memberAccounts(Request $request, SavingsLedger $ledger): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $user = $request->user();
        $restricted = ! $user->isGroupAdmin() && $user->isOfficeScoped();
        $scopedIds = $restricted ? $this->ownOfficeIds($request) : [];

        $filterOffices = $this->kamsOffices($request)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->when($restricted, fn ($q) => $q->whereIn('id', $scopedIds ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $officeIds = $restricted
            ? $filterOffices->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0]
            : [];
        $canFilter = $this->canFilterOffices($request) && $filterOffices->count() > 1;

        if ($canFilter && $request->filled('office_id')) {
            $officeId = (int) $request->integer('office_id');
            $this->assertKamsOffice($request, $officeId);
            $officeIds = [$officeId];
        }

        $payload = $ledger->memberAccounts($companyId, $officeIds);
        $payload['can_filter_offices'] = $canFilter;
        $payload['office_id'] = $canFilter && $request->filled('office_id') ? (int) $request->integer('office_id') : null;
        $payload['filter_offices'] = $canFilter ? $filterOffices->map(fn ($office) => [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
        ])->values()->all() : [];

        return $this->ok($payload);
    }

    public function account(Request $request, SavingsLedger $ledger, KamsAccountProvisioner $accounts): JsonResponse
    {
        $member = $this->memberFor($request, $accounts);

        return $this->ok($this->accountPayload($request, $member, $ledger, $accounts));
    }

    public function deposit(Request $request, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $member = $this->memberFor($request, $accounts);
        abort_unless($this->ownsAccount($request, $member), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
        ]);
        $entry = $treasury->requestDeposit($member, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id);
        AuditLogger::record('create', $entry, [], $data);

        return $this->created($this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts), __('Deposit requested.'));
    }

    public function withdraw(Request $request, SavingsLedger $ledger, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $member = $this->memberFor($request, $accounts);
        abort_unless($this->ownsAccount($request, $member), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
        ]);
        $entry = $treasury->requestWithdrawal($member, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id);
        AuditLogger::record('create', $entry, [], $data);

        return $this->created($this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts), __('Withdrawal requested.'));
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
        AuditLogger::record('create', $entry, [], $data);

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
        AuditLogger::record('create', $entry, [], $data);

        return $this->created($this->accountPayload($request, $member->fresh(['office', 'role']), $ledger, $accounts), __('Withdrawal recorded.'));
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
        abort_unless($companyId, 422, __('No KAMS company is available to open an account.'));
        $officeScoped = ! $request->user()->isGroupAdmin() && $request->user()->isOfficeScoped();
        $officeIds = $officeScoped ? $request->user()->accessibleOfficeIds() : [];

        return $this->ok($treasury->overview((int) $companyId, $officeIds, ! $officeScoped));
    }

    public function companyAccount(Request $request, KamsTreasuryAccount $treasuryAccount, TreasuryLedger $treasury): JsonResponse
    {
        $this->assertTreasuryAccess($request, $treasuryAccount);

        return $this->ok($this->treasuryPayload($request, $treasuryAccount, $treasury));
    }

    public function companyDeposit(Request $request, KamsTreasuryAccount $treasuryAccount, TreasuryLedger $treasury, KamsAccountProvisioner $accounts): JsonResponse
    {
        $this->assertTreasuryOperate($request, $treasuryAccount, 'kams.finance.company.deposit');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'max:500'],
            'member_id' => ['nullable', 'integer', 'exists:kams_members,id'],
        ]);
        $member = $this->memberForTreasury($request, $treasuryAccount, $accounts, $data['member_id'] ?? null);
        $entry = $treasury->deposit($treasuryAccount, (float) $data['amount'], (string) ($data['notes'] ?? ''), $request->user()->id, $member);
        AuditLogger::record('create', $entry, [], $data);

        return $this->created($this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury), __('Deposit recorded.'));
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
        AuditLogger::record('create', $entry, [], $data);

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
        AuditLogger::record('create', $entry, [], $data);

        return $this->created($this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury), __('Expense recorded.'));
    }

    private function accountPayload(Request $request, KamsMember $member, SavingsLedger $ledger, KamsAccountProvisioner $accounts): array
    {
        $member->loadMissing(['office:id,name,code', 'role:id,name,slug']);
        $payload = $ledger->statement($member);
        $owns = $this->ownsAccount($request, $member);
        $canViewAgents = $this->canViewAgentAccounts($request);
        $payload['can_deposit'] = $owns && $this->hasFinancePermission($request, 'kams.finance.account.deposit');
        $payload['can_withdraw'] = $owns && $this->hasFinancePermission($request, 'kams.finance.account.withdraw');
        $payload['can_post_deposit'] = $this->canPostAgentMovement($request, 'deposit');
        $payload['can_post_withdraw'] = $this->canPostAgentMovement($request, 'withdraw');
        $payload['can_expense'] = false;
        $payload['can_decide'] = false;
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
        abort_unless((int) $treasuryEntry->account_id === (int) $treasuryAccount->id, 404);
        $data = $request->validate([
            'notes' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500'],
        ]);
        $decided = $treasury->decide($treasuryEntry, $decision, $request->user()->id, (string) ($data['notes'] ?? ''));
        AuditLogger::record('update', $decided, [], ['status' => $decision === 'approve' ? 'posted' : 'rejected', 'notes' => $data['notes'] ?? null]);

        return $this->ok(
            $this->treasuryPayload($request, $treasuryAccount->fresh('office'), $treasury),
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
        $balances = $ledger->balancesFor($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

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
        $payload['can_deposit'] = $this->hasFinancePermission($request, 'kams.finance.company.deposit');
        $payload['can_withdraw'] = $this->hasFinancePermission($request, 'kams.finance.company.withdraw');
        $payload['can_expense'] = $this->hasFinancePermission($request, 'kams.finance.company.expense');
        $payload['can_operate'] = $payload['can_deposit'] || $payload['can_withdraw'] || $payload['can_expense'];
        $payload['can_decide'] = $this->hasFinancePermission($request, 'kams.finance.company.approve');
        $payload['members'] = $this->membersForTreasury($account, app(SavingsLedger::class));

        return $payload;
    }
}
