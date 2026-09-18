<?php

namespace App\Modules\Kams\Services;

use App\Core\Support\RoleCatalog;
use App\Models\Company;
use App\Models\Office;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use Illuminate\Support\Facades\DB;

class TreasuryLedger
{
    public function ensureForCompany(int $companyId): KamsTreasuryAccount
    {
        $company = Company::query()->find($companyId);

        return KamsTreasuryAccount::query()->firstOrCreate(
            ['company_id' => $companyId, 'code' => 'CENTRAL'],
            [
                'office_id' => null,
                'name' => ($company?->name ?: 'KAMS').' — central',
                'balance' => 0,
            ]
        );
    }

    public function ensureOffice(Office $office): KamsTreasuryAccount
    {
        return KamsTreasuryAccount::query()->firstOrCreate(
            ['company_id' => $office->company_id, 'code' => 'OFFICE-'.$office->id],
            [
                'office_id' => $office->id,
                'name' => $office->name,
                'balance' => 0,
            ]
        );
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function overview(int $companyId, array $officeIds = [], bool $includeCentral = true): array
    {
        $this->ensureForCompany($companyId);
        $offices = Office::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($officeIds !== [], fn ($q) => $q->whereIn('id', $officeIds))
            ->orderBy('name')
            ->get();
        foreach ($offices as $office) {
            $this->ensureOffice($office);
        }

        $accounts = KamsTreasuryAccount::query()
            ->with('office:id,name,code')
            ->where('company_id', $companyId)
            ->when(! $includeCentral, fn ($q) => $q->whereNotNull('office_id'))
            ->when($officeIds !== [], function ($q) use ($officeIds, $includeCentral) {
                $q->where(function ($inner) use ($officeIds, $includeCentral) {
                    $inner->whereIn('office_id', $officeIds);
                    if ($includeCentral) {
                        $inner->orWhereNull('office_id');
                    }
                });
            })
            ->orderByRaw('office_id is null desc')
            ->orderBy('name')
            ->get();

        $ownById = $this->postedTotalsByAccount($accounts->pluck('id')->map(fn ($id) => (int) $id)->all());
        $officeTotal = $this->officesPostedTotal($companyId);

        return $accounts->map(fn (KamsTreasuryAccount $account) => $this->summarize(
            $account,
            $ownById[(int) $account->id] ?? 0.0,
            $officeTotal,
        ))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(KamsTreasuryAccount $account): array
    {
        $this->ensurePendingSavingsLinked($account);
        $this->refreshBalance($account);
        $account->loadMissing('office:id,name,code');
        $posted = KamsTreasuryEntry::query()->where('account_id', $account->id)->where('status', 'posted');
        $entries = KamsTreasuryEntry::query()
            ->with([
                'createdBy:id,name',
                'requestedBy:id,name',
                'performedBy:id,name',
                'member:id,full_name,member_code,user_id',
                'account:id,name,code,office_id',
                'account.office:id,name,code',
            ])
            ->where('account_id', $account->id)
            ->latest()
            ->limit(80)
            ->get();

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'is_central' => $account->isCentral(),
                'office' => $account->office?->only(['id', 'name', 'code']),
            ],
            'balance' => round((float) $account->balance, 2),
            'available' => round((float) $account->balance, 2),
            'deposits' => round((float) (clone $posted)->where('type', 'deposit')->sum('amount'), 2),
            'withdrawals' => round(abs((float) (clone $posted)->where('type', 'withdrawal')->sum('amount')), 2),
            'expenses' => round(abs((float) (clone $posted)->where('type', 'expense')->sum('amount')), 2),
            'entries' => $entries->map(fn (KamsTreasuryEntry $entry) => $this->serializeEntry($entry, $account))->values()->all(),
        ];
    }

    public function deposit(KamsTreasuryAccount $account, float $amount, string $notes, int $userId, ?KamsMember $member = null): KamsTreasuryEntry
    {
        return $this->post($account, 'deposit', abs($amount), $notes, $userId, $member);
    }

    public function withdraw(KamsTreasuryAccount $account, float $amount, string $notes, int $userId, ?KamsMember $member = null): KamsTreasuryEntry
    {
        abort_unless($amount <= $this->spendable($account) + 0.001, 422, __('Insufficient account balance.'));

        return $this->post($account, 'withdrawal', -abs($amount), $notes, $userId, $member);
    }

    public function expense(KamsTreasuryAccount $account, float $amount, string $notes, int $userId, ?KamsMember $member = null): KamsTreasuryEntry
    {
        abort_unless($amount <= $this->spendable($account) + 0.001, 422, __('Insufficient account balance.'));

        return $this->post($account, 'expense', -abs($amount), $notes, $userId, $member);
    }

    public function available(KamsTreasuryAccount $account): float
    {
        return $this->postedTotal([(int) $account->id]);
    }

    public function displayedBalance(KamsTreasuryAccount $account): float
    {
        $own = $this->available($account);
        if (! $account->isCentral()) {
            return $own;
        }

        return round($own + $this->officesPostedTotal((int) $account->company_id), 2);
    }

    public function spendable(KamsTreasuryAccount $account): float
    {
        return $this->displayedBalance($account);
    }

    public function refreshBalance(KamsTreasuryAccount $account): KamsTreasuryAccount
    {
        $account->balance = $this->displayedBalance($account);
        $account->save();
        if (! $account->isCentral()) {
            $this->refreshCentralBalance((int) $account->company_id);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(KamsTreasuryAccount $account, ?float $own = null, ?float $officeTotal = null): array
    {
        $posted = KamsTreasuryEntry::query()->where('account_id', $account->id)->where('status', 'posted');
        $own ??= $this->available($account);
        $balance = $account->isCentral()
            ? round($own + ($officeTotal ?? $this->officesPostedTotal((int) $account->company_id)), 2)
            : $own;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'is_central' => $account->isCentral(),
            'office' => $account->office?->only(['id', 'name', 'code']),
            'balance' => $balance,
            'available' => $balance,
            'deposits' => round((float) (clone $posted)->where('type', 'deposit')->sum('amount'), 2),
            'withdrawals' => round(abs((float) (clone $posted)->where('type', 'withdrawal')->sum('amount')), 2),
            'expenses' => round(abs((float) (clone $posted)->where('type', 'expense')->sum('amount')), 2),
        ];
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function postedTotal(array $accountIds): float
    {
        if ($accountIds === []) {
            return 0.0;
        }

        return round((float) KamsTreasuryEntry::query()
            ->whereIn('account_id', $accountIds)
            ->where('status', 'posted')
            ->sum('amount'), 2);
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, float>
     */
    private function postedTotalsByAccount(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = KamsTreasuryEntry::query()
            ->selectRaw('account_id, COALESCE(SUM(amount), 0) as total')
            ->whereIn('account_id', $accountIds)
            ->where('status', 'posted')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $out = [];
        foreach ($accountIds as $id) {
            $out[$id] = round((float) ($rows[$id] ?? 0), 2);
        }

        return $out;
    }

    private function officesPostedTotal(int $companyId): float
    {
        $ids = KamsTreasuryAccount::query()
            ->where('company_id', $companyId)
            ->whereNotNull('office_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->postedTotal($ids);
    }

    private function refreshCentralBalance(int $companyId): void
    {
        $central = KamsTreasuryAccount::query()
            ->where('company_id', $companyId)
            ->where('code', 'CENTRAL')
            ->first();
        if (! $central) {
            return;
        }
        $central->balance = $this->displayedBalance($central);
        $central->save();
    }

    private function coverFromOffices(KamsTreasuryAccount $account, float $amount, int $userId): void
    {
        if (! $account->isCentral()) {
            return;
        }

        $needed = round($amount - $this->available($account), 2);
        if ($needed <= 0.001) {
            return;
        }

        $offices = KamsTreasuryAccount::query()
            ->with('office:id,name,code')
            ->where('company_id', $account->company_id)
            ->whereNotNull('office_id')
            ->get()
            ->sortByDesc(fn (KamsTreasuryAccount $office) => $this->available($office))
            ->values();

        foreach ($offices as $office) {
            if ($needed <= 0.001) {
                break;
            }
            $take = round(min($this->available($office), $needed), 2);
            if ($take <= 0.001) {
                continue;
            }
            $this->moveToCentral($office, $account, $take, $userId);
            $needed = round($needed - $take, 2);
        }

        abort_unless($needed <= 0.001, 422, __('Insufficient account balance.'));
    }

    private function moveToCentral(KamsTreasuryAccount $office, KamsTreasuryAccount $central, float $amount, int $userId): void
    {
        $officeName = $office->office?->name ?: $office->name;
        $outgoing = KamsTreasuryEntry::query()->create([
            'company_id' => $office->company_id,
            'account_id' => $office->id,
            'office_id' => $office->office_id,
            'member_id' => null,
            'type' => 'transfer',
            'status' => 'posted',
            'amount' => -abs($amount),
            'occurred_on' => now()->toDateString(),
            'notes' => 'Transfert vers le compte central',
            'created_by' => $userId,
            'requested_by' => $userId,
            'performed_by' => $userId,
        ]);
        $incoming = KamsTreasuryEntry::query()->create([
            'company_id' => $central->company_id,
            'account_id' => $central->id,
            'office_id' => null,
            'member_id' => null,
            'type' => 'transfer',
            'status' => 'posted',
            'amount' => abs($amount),
            'occurred_on' => now()->toDateString(),
            'notes' => 'Transfert depuis '.$officeName,
            'created_by' => $userId,
            'requested_by' => $userId,
            'performed_by' => $userId,
        ]);
        $outgoing->forceFill(['related_entry_id' => $incoming->id])->save();
        $incoming->forceFill(['related_entry_id' => $outgoing->id])->save();
        $this->refreshBalance($office);
    }

    public function accountForMember(KamsMember $member): KamsTreasuryAccount
    {
        abort_unless($member->company_id, 422, __('No KAMS company is available to open an account.'));
        $member->loadMissing('role');
        $slug = (string) $member->role?->slug;
        if (in_array($slug, RoleCatalog::kamsCentralSlugs(), true) || ! $member->office_id) {
            return $this->ensureForCompany((int) $member->company_id);
        }
        $office = Office::query()->find($member->office_id);
        abort_unless($office, 422, __('No office account is available.'));

        return $this->ensureOffice($office);
    }

    public function requestDeposit(KamsMember $member, float $amount, string $notes, int $userId): KamsTreasuryEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));

        return $this->queueMemberRequest($member, 'deposit', abs($amount), $notes, $userId);
    }

    public function requestWithdrawal(KamsMember $member, float $amount, string $notes, int $userId): KamsTreasuryEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        $savings = app(SavingsLedger::class);
        abort_unless($amount <= $savings->withdrawableBalance($member) + 0.001, 422, __('Insufficient account balance.'));

        return $this->queueMemberRequest($member, 'withdrawal', -abs($amount), $notes, $userId);
    }

    public function decide(KamsTreasuryEntry $entry, string $decision, int $userId, string $motif): KamsTreasuryEntry
    {
        abort_unless($entry->status === 'pending', 422, __('This request cannot be processed.'));
        abort_unless(in_array($entry->type, ['deposit', 'withdrawal'], true), 422, __('This request cannot be processed.'));
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, __('Unknown decision.'));

        if ($decision === 'reject') {
            abort_unless(trim($motif) !== '', 422, __('A reason is required to reject this request.'));
        }

        return DB::transaction(function () use ($entry, $decision, $userId, $motif) {
            $entry->loadMissing(['account', 'member']);
            $account = $entry->account;
            abort_unless($account, 422, __('Unknown treasury account.'));
            $member = $entry->member;
            abort_unless($member, 422, __('This request is not linked to a member account.'));
            $savings = app(SavingsLedger::class);
            $queued = $this->savingsFor($entry);

            if ($decision === 'approve') {
                $value = abs((float) $entry->amount);
                if ($entry->type === 'withdrawal') {
                    abort_unless($value <= $this->spendable($account) + 0.001, 422, __('Insufficient office account balance.'));
                    abort_unless($value <= $savings->withdrawableBalance($member) + 0.001, 422, __('Insufficient account balance.'));
                    $this->coverFromOffices($account, $value, $userId);
                }
                $entry->forceFill([
                    'status' => 'posted',
                    'performed_by' => $userId,
                    'decision_notes' => $motif !== '' ? $motif : null,
                ])->save();
                $this->refreshBalance($account);
                if ($queued) {
                    $savings->postQueued($queued, $userId, $motif);
                } elseif ($entry->type === 'deposit') {
                    $savings->deposit($member, $value, (string) $entry->notes, $userId, $entry, $entry->requested_by);
                } else {
                    $savings->withdraw($member, $value, (string) $entry->notes, $userId, $entry, $entry->requested_by);
                }
            } else {
                $entry->forceFill([
                    'status' => 'rejected',
                    'performed_by' => $userId,
                    'decision_notes' => $motif,
                ])->save();
                if ($queued) {
                    $savings->rejectQueued($queued, $userId, $motif);
                }
            }

            return $entry->fresh(['createdBy', 'requestedBy', 'performedBy', 'member', 'account.office']);
        });
    }

    private function queueMemberRequest(KamsMember $member, string $type, float $amount, string $notes, int $userId): KamsTreasuryEntry
    {
        return DB::transaction(function () use ($member, $type, $amount, $notes, $userId) {
            $account = $this->accountForMember($member);
            $requestedBy = $member->user_id ?: $userId;
            $entry = KamsTreasuryEntry::query()->create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'office_id' => $account->office_id,
                'member_id' => $member->id,
                'type' => $type,
                'status' => 'pending',
                'amount' => round($amount, 2),
                'occurred_on' => now()->toDateString(),
                'notes' => $notes ?: null,
                'created_by' => $userId,
                'requested_by' => $requestedBy,
                'performed_by' => null,
            ]);
            app(SavingsLedger::class)->queue(
                $member,
                $type === 'deposit' ? 'deposit_request' : 'withdrawal_request',
                $amount,
                $notes,
                $userId,
                $entry,
            );

            return $entry->fresh(['createdBy', 'requestedBy', 'member', 'account.office']);
        });
    }

    private function savingsFor(KamsTreasuryEntry $entry): ?KamsSavingsEntry
    {
        return KamsSavingsEntry::query()
            ->where('source_type', $entry::class)
            ->where('source_id', $entry->id)
            ->where('status', 'pending')
            ->first();
    }

    private function ensurePendingSavingsLinked(KamsTreasuryAccount $account): void
    {
        $memberQuery = KamsMember::query()
            ->where('company_id', $account->company_id)
            ->whereIn('status', ['active', 'verified']);
        if ($account->office_id) {
            $memberQuery->where('office_id', $account->office_id);
        } else {
            $memberQuery->whereHas('role', fn ($q) => $q->whereIn('slug', RoleCatalog::kamsCentralSlugs()));
        }
        $memberIds = $memberQuery->pluck('id');
        if ($memberIds->isEmpty()) {
            return;
        }

        $orphans = KamsSavingsEntry::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', 'pending')
            ->whereIn('type', ['deposit_request', 'withdrawal_request'])
            ->where(function ($q) {
                $q->whereNull('source_id')->orWhere('source_type', '!=', KamsTreasuryEntry::class);
            })
            ->get();

        foreach ($orphans as $row) {
            $type = $row->type === 'deposit_request' ? 'deposit' : 'withdrawal';
            $treasury = KamsTreasuryEntry::query()->create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'office_id' => $account->office_id,
                'member_id' => $row->member_id,
                'type' => $type,
                'status' => 'pending',
                'amount' => round((float) $row->amount, 2),
                'occurred_on' => $row->occurred_on?->toDateString() ?: now()->toDateString(),
                'notes' => $row->notes,
                'created_by' => $row->created_by,
                'requested_by' => $row->requested_by ?: $row->created_by,
                'performed_by' => null,
            ]);
            $row->forceFill([
                'source_type' => KamsTreasuryEntry::class,
                'source_id' => $treasury->id,
            ])->save();
        }
    }

    private function post(KamsTreasuryAccount $account, string $type, float $amount, string $notes, int $userId, ?KamsMember $member = null): KamsTreasuryEntry
    {
        abort_unless($amount != 0.0, 422, __('The amount must be greater than zero.'));

        return DB::transaction(function () use ($account, $type, $amount, $notes, $userId, $member) {
            if (in_array($type, ['withdrawal', 'expense'], true)) {
                $this->coverFromOffices($account, abs($amount), $userId);
            }
            $requestedBy = $member?->user_id ?: $userId;
            $entry = KamsTreasuryEntry::query()->create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'office_id' => $account->office_id,
                'member_id' => $member?->id,
                'type' => $type,
                'status' => 'posted',
                'amount' => round($amount, 2),
                'occurred_on' => now()->toDateString(),
                'notes' => $notes ?: null,
                'created_by' => $userId,
                'requested_by' => $requestedBy,
                'performed_by' => $userId,
            ]);
            $this->refreshBalance($account);

            if ($member) {
                $savings = app(SavingsLedger::class);
                $value = abs($amount);
                match ($type) {
                    'deposit' => $savings->deposit($member, $value, $notes, $userId, $entry, $requestedBy),
                    'withdrawal' => $savings->withdraw($member, $value, $notes, $userId, $entry, $requestedBy),
                    default => $savings->expense($member, $value, $notes, $userId, $entry, $requestedBy),
                };
            }

            return $entry->fresh(['createdBy', 'requestedBy', 'performedBy', 'member']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(KamsTreasuryEntry $entry, KamsTreasuryAccount $account): array
    {
        $sourceLabel = $account->office?->name ?: $account->name;

        return [
            'uid' => 'treasury-'.$entry->id,
            'id' => $entry->id,
            'type' => $entry->type,
            'status' => $entry->status ?: 'posted',
            'amount' => round((float) $entry->amount, 2),
            'occurred_on' => $entry->occurred_on?->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes' => $entry->notes,
            'decision_notes' => $entry->decision_notes ?? null,
            'member' => $entry->member ? [
                'id' => $entry->member->id,
                'full_name' => $entry->member->full_name,
                'member_code' => $entry->member->member_code,
            ] : null,
            'requested_by' => $this->actor($entry->requestedBy, $entry->member?->full_name),
            'performed_by' => $this->actor($entry->status === 'pending' ? $entry->performedBy : ($entry->performedBy ?: $entry->createdBy)),
            'created_by' => $this->actor($entry->createdBy),
            'source' => [
                'type' => 'treasury',
                'id' => $entry->id,
                'label' => $sourceLabel,
            ],
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
}
