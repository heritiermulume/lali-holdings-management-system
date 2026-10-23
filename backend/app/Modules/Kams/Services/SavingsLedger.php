<?php

namespace App\Modules\Kams\Services;

use App\Models\User;
use App\Modules\Kams\Models\KamsDailySettlement;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockReturnItem;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use App\Modules\Kams\Support\RevenueRuleCatalog;
use Illuminate\Support\Facades\DB;

class SavingsLedger
{
    public const KINDS = ['guy', 'manager', 'assistant_manager', 'secretary', 'divisionnaire', 'salary', 'percentage'];

    public function account(KamsMember $member, string $kind): KamsSavingsAccount
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, __('Unknown savings account.'));

        return KamsSavingsAccount::query()->firstOrCreate(
            ['member_id' => $member->id, 'kind' => $kind],
            ['company_id' => $this->companyId($member), 'balance' => 0]
        );
    }

    public function defaultManualKind(KamsMember $member): string
    {
        $slug = $member->role?->slug ?: \App\Models\Role::query()->whereKey($member->role_id)->value('slug');

        return RevenueRuleCatalog::usesSplitCompensationAccounts($slug) ? 'salary' : 'guy';
    }

    public function resolveManager(KamsMember $seller): ?KamsMember
    {
        $hierarchy = app(OfficeHierarchy::class);
        $seller->loadMissing('role:id,name,slug');
        if ($seller->manager_member_id) {
            $linked = KamsMember::query()->find($seller->manager_member_id);
            if ($linked && ! $hierarchy->excludedFromRemoteEncadreurTeam($seller, $linked)) {
                return $linked;
            }
        }
        // Encadreur is the parrain / upline used by finance.
        if ($seller->coach_member_id) {
            $coach = KamsMember::query()->find($seller->coach_member_id);
            if ($coach && ! $hierarchy->excludedFromRemoteEncadreurTeam($seller, $coach)) {
                return $coach;
            }
        }
        if ($seller->trainer_user_id) {
            $byUser = KamsMember::query()
                ->where('company_id', $seller->company_id)
                ->where('user_id', $seller->trainer_user_id)
                ->first();
            if ($byUser && ! $hierarchy->excludedFromRemoteEncadreurTeam($seller, $byUser)) {
                return $byUser;
            }
        }
        if ($seller->manager_name) {
            return KamsMember::query()
                ->where('company_id', $seller->company_id)
                ->where('full_name', $seller->manager_name)
                ->first();
        }

        return KamsMember::query()
            ->where('company_id', $seller->company_id)
            ->where('office_id', $seller->office_id)
            ->where('id', '!=', $seller->id)
            ->where(function ($q) {
                $q->whereHas('role', fn ($role) => $role->where('slug', 'manager'))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('role_id')->where('rank', 'Manager'));
            })
            ->first();
    }

    public function postSale(KamsStockSale $sale): void
    {
        if ($sale->payment_status !== 'paid') {
            return;
        }
        $sale->loadMissing(['member', 'items']);
        $seller = $sale->member;
        if (! $seller) {
            return;
        }
        $manager = $sale->manager_member_id
            ? KamsMember::query()->find($sale->manager_member_id)
            : $this->resolveManager($seller);

        if ((float) $sale->seller_margin != 0.0) {
            $seller->loadMissing('role:id,slug');
            $sellerKind = RevenueRuleCatalog::usesSplitCompensationAccounts($seller->role?->slug) ? 'salary' : 'guy';
            $this->postUnique($seller, $sellerKind, (float) $sale->seller_margin, 'settlement', $sale, $sale->sold_at?->toDateString(), 'Guy margin on '.$sale->reference);
        }
        app(RevenueShareService::class)->post($sale);
        $hasShares = KamsRevenueShare::query()->where('sale_id', $sale->id)->exists();
        if (! $hasShares && $manager && (float) $sale->manager_margin != 0.0) {
            $manager->loadMissing('role:id,slug');
            $managerKind = RevenueRuleCatalog::usesSplitCompensationAccounts($manager->role?->slug) ? 'salary' : 'manager';
            $this->postUnique($manager, $managerKind, (float) $sale->manager_margin, 'settlement', $sale, $sale->sold_at?->toDateString(), 'Manager margin on '.$sale->reference);
        }
    }

    public function postCompensation(KamsMember $member, string $kind, float $amount, string $type, object $source, ?string $date, string $notes): void
    {
        $this->postUnique($member, $kind, $amount, $type, $source, $date, $notes);
    }

    public function reverseReturn(KamsStockReturn $return, KamsStockReturnItem $line): void
    {
        if ($return->type !== 'customer' || ! $return->sale_id) {
            return;
        }
        $sale = $return->sale ?: KamsStockSale::query()->find($return->sale_id);
        if (! $sale?->member) {
            return;
        }
        $qty = (int) $line->quantity;
        $match = $sale->items()->where('product_id', $line->product_id)->first();
        $sellerUnit = $match ? (float) $match->seller_margin : 0;
        $managerUnit = $match ? (float) $match->manager_margin : 0;
        $this->postUnique($sale->member, 'guy', -1 * $qty * $sellerUnit, 'return', $return, now()->toDateString(), 'Return '.$return->reference);
        $manager = $sale->manager_member_id ? KamsMember::query()->find($sale->manager_member_id) : $this->resolveManager($sale->member);
        if ($manager && $managerUnit > 0) {
            $this->postUnique($manager, 'manager', -1 * $qty * $managerUnit, 'return', $return, now()->toDateString(), 'Return '.$return->reference);
        }
    }

    public function recapDay(int $companyId, string $date, ?int $officeId = null): array
    {
        $sales = KamsStockSale::query()
            ->with(['member:id,full_name,office_id,manager_member_id', 'items'])
            ->where('company_id', $companyId)
            ->whereDate('sold_at', $date)
            ->when($officeId, fn ($q) => $q->whereHas('member', fn ($m) => $m->where('office_id', $officeId)))
            ->get();

        $field = 0.0;
        $seller = 0.0;
        $manager = 0.0;
        $company = 0.0;
        foreach ($sales as $sale) {
            $field += (float) $sale->field_total ?: (float) $sale->subtotal;
            $seller += (float) $sale->seller_margin;
            $manager += (float) $sale->manager_margin;
            $company += (float) $sale->company_total;
        }
        $returns = KamsStockReturn::query()
            ->with('items')
            ->where('company_id', $companyId)
            ->where('type', 'customer')
            ->whereIn('status', ['restocked', 'quarantine'])
            ->whereDate('validated_at', $date)
            ->get();
        foreach ($returns as $return) {
            $sale = $sales->firstWhere('id', $return->sale_id) ?: KamsStockSale::query()->with('items')->find($return->sale_id);
            foreach ($return->items as $line) {
                $match = $sale?->items->firstWhere('product_id', $line->product_id);
                $qty = (int) $line->quantity;
                $seller -= $qty * (float) ($match?->seller_margin ?? 0);
                $manager -= $qty * (float) ($match?->manager_margin ?? 0);
                $field -= $qty * (float) ($match?->field_price ?? $line->unit_price);
                $company -= $qty * (float) ($match?->company_price ?? 0);
            }
        }

        return [
            'date' => $date,
            'office_id' => $officeId,
            'sales_count' => $sales->count(),
            'field_total' => round($field, 2),
            'seller_savings' => round($seller, 2),
            'manager_savings' => round($manager, 2),
            'company_share' => round($company, 2),
            'cost_total' => round($company, 2),
            'profit' => round($field - $company, 2),
            'sales' => $sales,
        ];
    }

    public function closeDay(int $companyId, string $date, ?int $officeId, int $userId): KamsDailySettlement
    {
        return DB::transaction(function () use ($companyId, $date, $officeId, $userId) {
            $recap = $this->recapDay($companyId, $date, $officeId);
            foreach ($recap['sales'] as $sale) {
                if ($sale->payment_status === 'paid') {
                    $this->postSale($sale);
                }
            }
            $row = KamsDailySettlement::query()->updateOrCreate(
                ['company_id' => $companyId, 'office_id' => $officeId, 'settled_on' => $date],
                [
                    'status' => 'posted',
                    'field_total' => $recap['field_total'],
                    'seller_savings' => $recap['seller_savings'],
                    'manager_savings' => $recap['manager_savings'],
                    'company_share' => $recap['company_share'],
                    'posted_by' => $userId,
                    'posted_at' => now(),
                ]
            );

            return $row->fresh('office');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ledgers(?int $companyId, string $kind): array
    {
        return KamsSavingsAccount::query()
            ->with(['member:id,full_name,member_code,office_id,manager_name', 'member.office:id,name'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('kind', $kind)
            ->orderByDesc('balance')
            ->get()
            ->map(fn (KamsSavingsAccount $account) => [
                'id' => $account->id,
                'kind' => $account->kind,
                'balance' => round((float) $account->balance, 2),
                'member' => $account->member,
                'entries' => $account->entries()->limit(12)->get(['id', 'type', 'amount', 'occurred_on', 'notes']),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $officeIds
     * @return array{members: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function memberAccounts(?int $companyId, array $officeIds = [], ?int $excludeMemberId = null): array
    {
        $kinds = self::KINDS;
        $emptyBalances = array_fill_keys($kinds, 0.0);

        $members = KamsMember::query()
            ->with([
                'office:id,name,code',
                'role:id,name,slug',
                'savingsAccounts:id,member_id,kind,balance',
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->where(function ($query) {
                $query->whereDoesntHave('role', fn ($role) => $role->where('slug', 'super_administrator'))
                    ->whereDoesntHave('user.roles', fn ($role) => $role->where('roles.slug', 'super_administrator'));
            })
            ->whereIn('status', ['active', 'verified'])
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'member_code', 'status', 'office_id', 'role_id']);

        $totals = array_merge($emptyBalances, ['total' => 0.0]);
        $rows = [];

        foreach ($members as $member) {
            $balances = $emptyBalances;
            foreach ($member->savingsAccounts as $account) {
                $kind = (string) $account->kind;
                if (! array_key_exists($kind, $balances)) {
                    continue;
                }
                $balances[$kind] = round((float) $account->balance, 2);
            }
            $total = round(array_sum($balances), 2);
            foreach ($balances as $kind => $amount) {
                $totals[$kind] = round($totals[$kind] + $amount, 2);
            }
            $totals['total'] = round($totals['total'] + $total, 2);

            $rows[] = [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_code' => $member->member_code,
                'status' => $member->status,
                'office' => $member->office?->only(['id', 'name', 'code']),
                'role' => $member->role?->only(['id', 'name', 'slug']),
                'accounts' => $balances,
                'total_balance' => $total,
            ];
        }

        return [
            'members' => $rows,
            'totals' => $totals,
            'profiles' => collect(['distributor', 'assistant_manager', 'manager', 'divisionnaire'])
                ->map(function (string $slug) use ($rows) {
                    $group = collect($rows)->filter(fn (array $row) => ($row['role']['slug'] ?? null) === $slug);

                    return [
                        'slug' => $slug,
                        'name' => (string) ($group->first()['role']['name'] ?? $slug),
                        'total' => round((float) $group->sum('total_balance'), 2),
                    ];
                })
                ->all(),
        ];
    }

    private function postUnique(KamsMember $member, string $kind, float $amount, string $type, object $source, ?string $date, string $notes): void
    {
        if (abs($amount) < 0.001) {
            return;
        }
        $existing = KamsSavingsEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->id)
            ->where('member_id', $member->id)
            ->where('type', $type)
            ->first();
        if ($existing) {
            // Keep a single ledger line per source even after salary/percentage split.
            if ($existing->kind !== $kind) {
                $account = $this->account($member, $kind);
                $previousAccountId = (int) $existing->account_id;
                $existing->forceFill([
                    'account_id' => $account->id,
                    'kind' => $kind,
                ])->save();
                $this->refreshBalance($account);
                if ($previousAccountId && $previousAccountId !== (int) $account->id) {
                    $previous = KamsSavingsAccount::query()->find($previousAccountId);
                    if ($previous) {
                        $this->refreshBalance($previous);
                    }
                }
                app(TreasuryLedger::class)->mirrorSavingsToOffice($existing->fresh());
            }

            return;
        }
        $account = $this->account($member, $kind);
        $entry = KamsSavingsEntry::query()->create([
            'company_id' => $this->companyId($member, $source),
            'account_id' => $account->id,
            'member_id' => $member->id,
            'kind' => $kind,
            'type' => $type,
            'status' => 'posted',
            'amount' => round($amount, 2),
            'source_type' => $source::class,
            'source_id' => $source->id,
            'occurred_on' => $date,
            'notes' => $notes,
        ]);
        $account->balance = round((float) KamsSavingsEntry::query()
            ->where('account_id', $account->id)
            ->where('status', 'posted')
            ->sum('amount'), 2);
        $account->save();
        $this->recordBalanceAfter($entry);
        app(TreasuryLedger::class)->mirrorSavingsToOffice($entry->fresh());
    }

    public function refreshBalance(KamsSavingsAccount $account): KamsSavingsAccount
    {
        $account->balance = round((float) KamsSavingsEntry::query()
            ->where('account_id', $account->id)
            ->where('status', 'posted')
            ->sum('amount'), 2);
        $account->save();

        return $account->fresh('member');
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(KamsMember $member): array
    {
        $member->loadMissing(['office:id,name,code', 'role:id,name,slug']);
        app(RevenueShareService::class)->syncMemberLedger($member);
        if (RevenueRuleCatalog::usesSplitCompensationAccounts($member->role?->slug)) {
            $this->account($member, 'salary');
            $this->account($member, 'percentage');
        }

        $accounts = KamsSavingsAccount::query()
            ->where('member_id', $member->id)
            ->get();
        if ($accounts->isEmpty()) {
            $accounts = collect([$this->account($member, 'guy')]);
        }

        $entries = KamsSavingsEntry::query()
            ->with(['createdBy:id,name', 'requestedBy:id,name', 'performedBy:id,name', 'member:id,full_name,member_code,user_id', 'source'])
            ->where('member_id', $member->id)
            ->latest()
            ->orderByDesc('id')
            ->limit(500)
            ->get();
        $mapped = $entries->map(fn (KamsSavingsEntry $entry) => $this->serializeEntry($entry))->values();
        $linkedTreasuryIds = $entries
            ->filter(fn (KamsSavingsEntry $entry) => $entry->source_type === KamsTreasuryEntry::class)
            ->pluck('source_id')
            ->filter()
            ->all();
        $orphans = KamsTreasuryEntry::query()
            ->with([
                'createdBy:id,name',
                'requestedBy:id,name',
                'performedBy:id,name',
                'member:id,full_name,member_code,user_id',
                'account:id,name,code,office_id',
                'account.office:id,name,code',
            ])
            ->where('member_id', $member->id)
            ->when($linkedTreasuryIds !== [], fn ($q) => $q->whereNotIn('id', $linkedTreasuryIds))
            ->latest()
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (KamsTreasuryEntry $entry) => $this->serializeTreasuryMirror($entry));
        $mapped = $mapped
            ->concat($orphans)
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->values()
            ->take(500)
            ->values();

        $posted = KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted');
        $pendingWithdrawals = KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->where('type', 'withdrawal_request');
        $pendingDeposits = KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->where('type', 'deposit_request');

        $balance = round((float) (clone $posted)->sum('amount'), 2);
        $roleSlug = $member->role?->slug;
        $splitAccounts = RevenueRuleCatalog::usesSplitCompensationAccounts($roleSlug);
        $walletKinds = $splitAccounts ? ['salary', 'percentage'] : ['guy'];
        $wallets = [];
        foreach ($walletKinds as $walletKind) {
            $wallets[] = [
                'kind' => $walletKind,
                'balance' => round((float) KamsSavingsEntry::query()
                    ->where('member_id', $member->id)
                    ->where('status', 'posted')
                    ->where('kind', $walletKind)
                    ->sum('amount'), 2),
                'pending_withdrawals' => round(abs((float) KamsSavingsEntry::query()
                    ->where('member_id', $member->id)
                    ->where('status', 'pending')
                    ->where('type', 'withdrawal_request')
                    ->where('kind', $walletKind)
                    ->sum('amount')), 2),
                'pending_deposits' => round((float) KamsSavingsEntry::query()
                    ->where('member_id', $member->id)
                    ->where('status', 'pending')
                    ->where('type', 'deposit_request')
                    ->where('kind', $walletKind)
                    ->sum('amount'), 2),
            ];
        }

        return [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_code' => $member->member_code,
                'rank' => $member->rank,
                'office' => $member->office?->only(['id', 'name', 'code']),
                'role' => $member->role?->only(['id', 'name', 'slug']),
            ],
            'balance' => $balance,
            'pending_withdrawals' => round(abs((float) $pendingWithdrawals->sum('amount')), 2),
            'pending_deposits' => round((float) $pendingDeposits->sum('amount'), 2),
            'deposits' => round((float) (clone $posted)
                ->where('type', 'deposit')
                ->where(function ($query) {
                    $query->where('source_type', '!=', KamsTreasuryEntry::class)
                        ->orWhereNull('source_type')
                        ->orWhereIn('source_id', function ($sub) {
                            $sub->select('id')
                                ->from('kams_treasury_entries')
                                ->where('type', '!=', 'commission_transfer');
                        });
                })
                ->sum('amount'), 2),
            'withdrawals' => round(abs((float) (clone $posted)->whereIn('type', ['withdrawal', 'withdrawal_request'])->sum('amount')), 2),
            'expenses' => round(abs((float) (clone $posted)->where('type', 'expense')->sum('amount')), 2),
            'split_accounts' => $splitAccounts,
            'accounts' => $wallets,
            'entries' => $mapped->all(),
        ];
    }

    public function deposit(KamsMember $member, float $amount, string $notes, int $userId, ?object $source = null, ?int $requestedBy = null, ?string $kind = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));

        return $this->postManual($member, 'deposit', abs($amount), $notes, $userId, 'posted', $requestedBy, $userId, $source, $kind);
    }

    /**
     * Credit day-closing cash surplus to the agent's savings account (posted deposit).
     */
    public function depositDayClosingSurplus(
        KamsMember $member,
        float $amount,
        KamsStockAssignment $assignment,
        int $userId,
    ): KamsSavingsEntry {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));

        $performer = User::query()->find($userId);
        $performerName = trim((string) ($performer?->name ?: $performer?->email ?: ('#'.$userId)));

        $notes = trim(sprintf(
            'Surplus clôture journée %s · Effectué par %s',
            $assignment->reference,
            $performerName,
        ));

        $entry = $this->postManual(
            $member,
            'deposit',
            abs($amount),
            $notes,
            $userId,
            'posted',
            $userId,
            $userId,
            $assignment,
        );

        // Ensure the cashier/manager who closed the day is stored as the deposit performer.
        $entry->forceFill([
            'created_by' => $userId,
            'requested_by' => $userId,
            'performed_by' => $userId,
        ])->save();

        $account = $this->account($member, $this->defaultManualKind($member));
        $this->refreshBalance($account);

        $accountId = (int) $account->id;
        DB::afterCommit(function () use ($accountId) {
            $account = KamsSavingsAccount::query()->find($accountId);
            if ($account) {
                app(self::class)->refreshBalance($account);
            }
        });

        return $entry->fresh(['member', 'createdBy', 'requestedBy', 'performedBy', 'source', 'account']);
    }

    public function expense(KamsMember $member, float $amount, string $notes, int $userId, ?object $source = null, ?int $requestedBy = null, ?string $kind = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        $wallet = $kind ?: $this->defaultManualKind($member);
        $available = $this->availableBalance($member, $wallet);
        abort_unless($amount <= $available + 0.001, 422, __('Insufficient account balance.'));

        return $this->postManual($member, 'expense', -abs($amount), $notes, $userId, 'posted', $requestedBy, $userId, $source, $wallet);
    }

    public function withdraw(KamsMember $member, float $amount, string $notes, int $userId, ?object $source = null, ?int $requestedBy = null, ?string $kind = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        $wallet = $kind ?: $this->defaultManualKind($member);
        abort_unless($amount <= $this->withdrawableBalance($member, $wallet) + 0.001, 422, __('Insufficient account balance.'));

        return $this->postManual($member, 'withdrawal', -abs($amount), $notes, $userId, 'posted', $requestedBy, $userId, $source, $wallet);
    }

    public function queue(KamsMember $member, string $type, float $amount, string $notes, int $userId, object $source, ?string $kind = null): KamsSavingsEntry
    {
        abort_unless(in_array($type, ['deposit_request', 'withdrawal_request'], true), 422, __('Unknown request type.'));

        return $this->postManual($member, $type, $amount, $notes, $userId, 'pending', $userId, null, $source, $kind);
    }

    public function postQueued(KamsSavingsEntry $entry, int $userId, string $decisionNotes): KamsSavingsEntry
    {
        abort_unless($entry->status === 'pending', 422, __('This request cannot be approved.'));
        $type = match ($entry->type) {
            'deposit_request' => 'deposit',
            'withdrawal_request' => 'withdrawal',
            default => null,
        };
        abort_unless($type, 422, __('This request cannot be approved.'));
        $entry->forceFill([
            'status' => 'posted',
            'type' => $type,
            'performed_by' => $userId,
            'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
        ])->save();
        $this->refreshBalance($entry->account);
        $fresh = $entry->fresh();
        $this->recordBalanceAfter($fresh);
        if ($fresh->source_type !== KamsTreasuryEntry::class) {
            app(TreasuryLedger::class)->mirrorSavingsToOffice($fresh, $userId);
        }
        $this->notifyAccountHolder($fresh);

        return $entry->fresh(['member', 'createdBy', 'requestedBy', 'performedBy', 'source']);
    }

    public function rejectQueued(KamsSavingsEntry $entry, int $userId, string $decisionNotes): KamsSavingsEntry
    {
        abort_unless($entry->status === 'pending', 422, __('This request cannot be rejected.'));
        abort_unless(in_array($entry->type, ['deposit_request', 'withdrawal_request'], true), 422, __('This request cannot be rejected.'));
        $entry->forceFill([
            'status' => 'rejected',
            'performed_by' => $userId,
            'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
        ])->save();
        $this->recordBalanceAfter($entry);

        return $entry->fresh(['member', 'createdBy', 'requestedBy', 'performedBy', 'source']);
    }

    public function requestWithdrawal(KamsMember $member, float $amount, string $notes, int $userId, ?string $kind = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        $wallet = $kind ?: $this->defaultManualKind($member);
        abort_unless($amount <= $this->withdrawableBalance($member, $wallet) + 0.001, 422, __('Insufficient account balance.'));

        return $this->postManual($member, 'withdrawal_request', -abs($amount), $notes, $userId, 'pending', $userId, null, null, $wallet);
    }

    public function approveWithdrawal(KamsSavingsEntry $entry, int $userId): KamsSavingsEntry
    {
        return $this->postQueued($entry, $userId, '');
    }

    public function rejectWithdrawal(KamsSavingsEntry $entry): KamsSavingsEntry
    {
        return $this->rejectQueued($entry, (int) ($entry->performed_by ?: $entry->created_by ?: 0), '');
    }

    public function availableBalance(KamsMember $member, ?string $kind = null): float
    {
        return round((float) KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->sum('amount'), 2);
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, float>
     */
    public function balancesFor(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return KamsSavingsEntry::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', 'posted')
            ->groupBy('member_id')
            ->selectRaw('member_id, coalesce(sum(amount), 0) as balance')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->member_id => round((float) $row->balance, 2)])
            ->all();
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, float>
     */
    public function withdrawableBalancesFor(array $memberIds): array
    {
        $available = $this->balancesFor($memberIds);
        if ($memberIds === []) {
            return [];
        }

        $pending = KamsSavingsEntry::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', 'pending')
            ->where('type', 'withdrawal_request')
            ->groupBy('member_id')
            ->selectRaw('member_id, coalesce(sum(amount), 0) as pending')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->member_id => (float) $row->pending])
            ->all();

        $out = [];
        foreach ($memberIds as $id) {
            $out[$id] = round(($available[$id] ?? 0.0) + ($pending[$id] ?? 0.0), 2);
        }

        return $out;
    }

    public function withdrawableBalance(KamsMember $member, ?string $kind = null): float
    {
        $pending = (float) KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->where('type', 'withdrawal_request')
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->sum('amount');

        return round($this->availableBalance($member, $kind) + $pending, 2);
    }

    private function postManual(
        KamsMember $member,
        string $type,
        float $amount,
        string $notes,
        int $userId,
        string $status,
        ?int $requestedBy = null,
        ?int $performedBy = null,
        ?object $source = null,
        ?string $kind = null,
    ): KamsSavingsEntry {
        $wallet = $kind && in_array($kind, self::KINDS, true) ? $kind : $this->defaultManualKind($member);
        $account = $this->account($member, $wallet);
        $requester = $requestedBy ?: ($member->user_id ?: $userId);
        $entry = KamsSavingsEntry::query()->create([
            'company_id' => $this->companyId($member, $source),
            'account_id' => $account->id,
            'member_id' => $member->id,
            'kind' => $account->kind,
            'type' => $type,
            'status' => $status,
            'amount' => round($amount, 2),
            'source_type' => $source ? $source::class : null,
            'source_id' => $source->id ?? null,
            'occurred_on' => now()->toDateString(),
            'notes' => $notes ?: null,
            'created_by' => $userId,
            'requested_by' => $requester,
            'performed_by' => $status === 'pending' ? $performedBy : ($performedBy ?: $userId),
        ]);
        if ($status === 'posted') {
            $this->refreshBalance($account);
            $this->recordBalanceAfter($entry);
            if (! $source instanceof KamsTreasuryEntry) {
                app(TreasuryLedger::class)->mirrorSavingsToOffice($entry->fresh(), $userId);
            }
            $this->notifyAccountHolder($entry);
        }

        return $entry->fresh(['member', 'createdBy', 'requestedBy', 'performedBy', 'source']);
    }

    public function recalculateBalanceSnapshots(): void
    {
        $entries = KamsSavingsEntry::query()->orderBy('created_at')->orderBy('id')->get();
        foreach ($entries as $entry) {
            $this->recordBalanceAfter($entry);
        }
    }

    /**
     * Partial member selects omit company_id, which must still be stored on ledger rows.
     */
    private function companyId(KamsMember $member, ?object $source = null): int
    {
        $companyId = $member->getAttributes()['company_id'] ?? null;
        if (! $companyId && $member->id) {
            $companyId = KamsMember::query()->whereKey($member->id)->value('company_id');
        }
        if (! $companyId && $source && ! empty($source->company_id)) {
            $companyId = $source->company_id;
        }

        abort_if(! $companyId, 422, 'The member is not attached to a company.');

        return (int) $companyId;
    }

    private function notifyAccountHolder(KamsSavingsEntry $entry): void
    {
        if (! in_array($entry->type, ['deposit', 'withdrawal'], true)) {
            return;
        }

        $entryId = (int) $entry->id;
        $send = function () use ($entryId) {
            $entry = KamsSavingsEntry::query()->with('member.user')->find($entryId);
            $user = $entry?->member?->user;
            if (! $entry || ! $user || ! filled($user->email)) {
                return;
            }

            $english = $user->locale === 'en';
            $wallet = match ((string) $entry->kind) {
                'salary' => $english ? 'Salary account' : 'Compte Salaire',
                'percentage' => $english ? 'Overed account' : 'Compte Overed',
                'assistant_manager' => $english ? 'Assistant account' : 'Compte assistant',
                'secretary' => $english ? 'Secretary account' : 'Compte secrétaire',
                'divisionnaire' => $english ? 'Division account' : 'Compte divisionnaire',
                'manager' => $english ? 'Manager account' : 'Compte manager',
                default => $english ? 'Agent account' : 'Compte agent',
            };

            app(KamsAccountNotice::class)->send($user, [
                'purpose' => 'posted',
                'movement' => $entry->type === 'withdrawal' ? 'withdrawal' : 'deposit',
                'amount' => (float) $entry->amount,
                'notes' => $entry->notes,
                'balance_after' => $entry->balance_after !== null ? (float) $entry->balance_after : null,
                'wallet' => $wallet,
            ]);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
    }

    private function recordBalanceAfter(?KamsSavingsEntry $entry): void
    {
        if (! $entry || ! in_array($entry->status ?: 'posted', ['posted', 'rejected'], true)) {
            return;
        }

        $posted = KamsSavingsEntry::query()
            ->where('member_id', $entry->member_id)
            ->where('status', 'posted');
        $this->limitToPriorEntries($posted, $entry, $entry->status !== 'rejected');
        $entry->forceFill(['balance_after' => round((float) $posted->sum('amount'), 2)])->save();
    }

    private function limitToPriorEntries($query, KamsSavingsEntry $entry, bool $includeSelf): void
    {
        $second = $entry->created_at?->format('Y-m-d H:i:s');
        $nextSecond = $entry->created_at?->copy()->addSecond()->format('Y-m-d H:i:s');
        $query->where(function ($query) use ($entry, $second, $nextSecond, $includeSelf) {
            $query->where('created_at', '<', $second)
                ->orWhere(function ($query) use ($entry, $second, $nextSecond, $includeSelf) {
                    $query->where('created_at', '>=', $second)
                        ->where('created_at', '<', $nextSecond)
                        ->where('id', $includeSelf ? '<=' : '<', $entry->id);
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEntry(KamsSavingsEntry $entry): array
    {
        $entry->loadMissing(['createdBy:id,name', 'requestedBy:id,name', 'performedBy:id,name', 'member:id,full_name,member_code,user_id', 'source']);

        $source = $this->sourcePayload($entry->source);

        return [
            'uid' => 'savings-'.$entry->id,
            'id' => $entry->id,
            'kind' => $entry->kind,
            'type' => $entry->type,
            'status' => $entry->status ?: 'posted',
            'amount' => round((float) $entry->amount, 2),
            'balance_after' => $entry->balance_after !== null ? round((float) $entry->balance_after, 2) : null,
            'occurred_on' => $entry->occurred_on?->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes' => $entry->notes,
            'decision_notes' => $entry->decision_notes ?? null,
            'is_commission_transfer' => (bool) ($source['is_commission_transfer'] ?? false),
            'member' => $this->memberActor($entry->member),
            'requested_by' => $this->actor($entry->requestedBy, $entry->member?->full_name),
            'performed_by' => $this->actor(
                $entry->performedBy ?: ($entry->status === 'pending' ? null : $entry->createdBy),
            ),
            'created_by' => $this->actor($entry->createdBy),
            'source' => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTreasuryMirror(KamsTreasuryEntry $entry): array
    {
        $entry->loadMissing([
            'createdBy:id,name',
            'requestedBy:id,name',
            'performedBy:id,name',
            'member:id,full_name,member_code,user_id',
            'account:id,name,code,office_id',
            'account.office:id,name,code',
        ]);

        $kind = null;
        if ($entry->savings_entry_id) {
            $kind = KamsSavingsEntry::query()->whereKey($entry->savings_entry_id)->value('kind');
        }
        if (! $kind && is_string($entry->notes)) {
            if (str_contains($entry->notes, 'Compte Overed') || str_contains($entry->notes, 'Compte Pourcentage')) {
                $kind = 'percentage';
            } elseif (str_contains($entry->notes, 'Compte Salaire')) {
                $kind = 'salary';
            }
        }

        return [
            'uid' => 'treasury-'.$entry->id,
            'id' => $entry->id,
            'kind' => $kind,
            'type' => $entry->type,
            'status' => $entry->status ?: 'posted',
            'amount' => round((float) $entry->amount, 2),
            'balance_after' => $entry->balance_after !== null ? round((float) $entry->balance_after, 2) : null,
            'occurred_on' => $entry->occurred_on?->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes' => $entry->notes,
            'decision_notes' => $entry->decision_notes ?? null,
            'member' => $this->memberActor($entry->member),
            'requested_by' => $this->actor($entry->requestedBy, $entry->member?->full_name),
            'performed_by' => $this->actor($entry->performedBy ?: ($entry->status === 'pending' ? null : $entry->createdBy)),
            'created_by' => $this->actor($entry->createdBy),
            'source' => $this->sourcePayload($entry),
        ];
    }

    /**
     * @return array{id: int|null, name: string}|null
     */
    private function actor(?User $user, ?string $fallback = null): ?array
    {
        if ($user) {
            return ['id' => $user->id, 'name' => $user->name];
        }
        if ($fallback) {
            return ['id' => null, 'name' => $fallback];
        }

        return null;
    }

    /**
     * @return array{id: int, full_name: string, member_code: string|null}|null
     */
    private function memberActor(?KamsMember $member): ?array
    {
        if (! $member) {
            return null;
        }

        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'member_code' => $member->member_code,
        ];
    }

    /**
     * @return array{type: string, id: int|null, label: string|null}|null
     */
    private function sourcePayload(mixed $source): ?array
    {
        if ($source instanceof KamsTreasuryEntry) {
            $source->loadMissing(['account:id,name,code,office_id', 'account.office:id,name,code']);
            $account = $source->account;

            return [
                'type' => 'treasury',
                'id' => $source->id,
                'label' => $account?->office?->name ?: $account?->name,
                'entry_type' => $source->type,
                'is_commission_transfer' => $source->type === 'commission_transfer',
            ];
        }
        if ($source instanceof KamsStockSale) {
            return ['type' => 'sale', 'id' => $source->id, 'label' => $source->reference ?? null];
        }
        if ($source instanceof KamsStockAssignment) {
            return ['type' => 'assignment', 'id' => $source->id, 'label' => $source->reference ?? null];
        }
        if ($source instanceof KamsStockReturn) {
            return ['type' => 'return', 'id' => $source->id, 'label' => $source->reference ?? null];
        }
        if ($source instanceof KamsRevenueShare) {
            $source->loadMissing([
                'sale:id,reference',
                'beneficiary:id,full_name,member_code',
                'parentShare.beneficiary:id,full_name,member_code',
            ]);

            return [
                'type' => 'revenue_share',
                'id' => $source->id,
                'label' => $source->sale?->reference ?: $source->formula,
                'rule_code' => $source->rule_code,
                'formula' => $source->formula,
                'beneficiary' => $this->memberActor($source->beneficiary),
                'paid_from' => $this->memberActor($source->parentShare?->beneficiary),
            ];
        }
        if (is_object($source) && isset($source->id)) {
            $label = $source->reference ?? $source->name ?? null;

            return [
                'type' => class_basename($source),
                'id' => $source->id,
                'label' => is_string($label) ? $label : null,
            ];
        }

        return null;
    }
}
