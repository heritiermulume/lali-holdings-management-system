<?php

namespace App\Modules\Kams\Services;

use App\Core\Support\RoleCatalog;
use App\Mail\FinanceRequestDecisionMail;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Office;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TreasuryLedger
{
    public function ensureForCompany(int $companyId): KamsTreasuryAccount
    {
        $company = Company::query()->find($companyId);

        return KamsTreasuryAccount::query()->firstOrCreate(
            ['company_id' => $companyId, 'code' => 'CENTRAL'],
            [
                'office_id' => null,
                'name' => ($company?->name ?: 'KAMS').' — principal',
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
        $this->ensureOfficeBankDepositMirrors($account);
        $this->refreshBalance($account);
        $account->loadMissing('office:id,name,code');
        // Principal (bank) and office ledgers stay separate: never merge company-wide movements.
        $posted = KamsTreasuryEntry::query()
            ->where('status', 'posted')
            ->where('account_id', $account->id);
        $entriesQuery = KamsTreasuryEntry::query()
            ->with([
                'createdBy:id,name',
                'requestedBy:id,name',
                'performedBy:id,name',
                'member:id,full_name,member_code,user_id',
                'account:id,name,code,office_id',
                'account.office:id,name,code',
                'office:id,name,code',
            ])
            ->where('account_id', $account->id);
        $entries = $entriesQuery->orderBy('created_at')->orderBy('id')->get();
        $savings = $this->unmirroredSavingsEntries($account, false);
        $serialized = $this->timelineWithBalances($account, $entries, $savings);
        $serialized = collect($serialized)->sortBy([
            ['created_at', 'desc'],
            ['id', 'desc'],
        ])->take(500)->values();

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'is_central' => $account->isCentral(),
                'office' => $account->office?->only(['id', 'name', 'code']),
            ],
            'balance' => round((float) $account->balance, 2),
            'available' => round($this->spendable($account), 2),
            'pending_deposits' => $this->pendingBankDepositTotal($account),
            'deposits' => round((float) (clone $posted)->whereIn('type', ['deposit', 'transfer', 'transfer_deposit'])->where('amount', '>', 0)->sum('amount'), 2),
            'withdrawals' => round(abs((float) (clone $posted)->where('type', 'withdrawal')->sum('amount')), 2),
            'expenses' => round(abs((float) (clone $posted)->where('type', 'expense')->sum('amount')), 2),
            'entries' => $serialized->all(),
        ];
    }

    /**
     * Ensure each pending principal deposit has a matching pending line on the office ledger
     * (so office cards/transactions show the same "en attente" request).
     */
    private function ensureOfficeBankDepositMirrors(KamsTreasuryAccount $account): void
    {
        if ($account->isCentral() || ! $account->office_id) {
            return;
        }

        $central = $this->ensureForCompany((int) $account->company_id);
        $orphans = KamsTreasuryEntry::query()
            ->where('account_id', $central->id)
            ->where('office_id', $account->office_id)
            ->where('type', 'transfer_deposit')
            ->whereIn('status', ['pending', 'rejected'])
            ->where(function ($query) use ($account) {
                $query->whereNull('related_entry_id')
                    ->orWhereDoesntHave('related', fn ($related) => $related->where('account_id', $account->id));
            })
            ->orderBy('id')
            ->get();

        foreach ($orphans as $centralEntry) {
            $officeEntry = new KamsTreasuryEntry([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'office_id' => $account->office_id,
                'member_id' => null,
                'related_entry_id' => $centralEntry->id,
                'type' => 'transfer_deposit',
                'status' => $centralEntry->status,
                'amount' => -abs((float) $centralEntry->amount),
                'occurred_on' => $centralEntry->occurred_on?->toDateString() ?: now()->toDateString(),
                'notes' => $centralEntry->notes,
                'decision_notes' => $centralEntry->decision_notes,
                'created_by' => $centralEntry->created_by,
                'requested_by' => $centralEntry->requested_by,
                'performed_by' => $centralEntry->performed_by,
            ]);
            $officeEntry->created_at = $centralEntry->created_at ?: now();
            $officeEntry->updated_at = $centralEntry->updated_at ?: now();
            $officeEntry->save();
            $centralEntry->forceFill(['related_entry_id' => $officeEntry->id])->save();
            if ($officeEntry->status === 'rejected') {
                $this->recordBalanceAfter($officeEntry->fresh());
            }
        }
    }

    /**
     * Request a deposit from an office treasury into the company principal (bank) account.
     * Money stays on the office until an admin/president approves the request.
     */
    public function requestOfficeBankDeposit(
        KamsTreasuryAccount $office,
        float $amount,
        string $occurredOn,
        string $notes,
        int $userId,
    ): KamsTreasuryEntry {
        abort_unless(! $office->isCentral() && $office->office_id, 422, __('Deposits to the principal account must come from an office account.'));
        abort_unless($amount > 0.001, 422, __('The amount must be greater than zero.'));
        abort_unless($amount <= $this->spendable($office) + 0.001, 422, __('Insufficient office account balance.'));

        $central = $this->ensureForCompany((int) $office->company_id);
        $office->loadMissing('office:id,name,code');
        $officeName = $office->office?->name ?: $office->name;

        return DB::transaction(function () use ($office, $central, $amount, $occurredOn, $notes, $userId, $officeName) {
            $value = round(abs($amount), 2);
            $note = $notes !== ''
                ? $notes
                : __('Deposit from :office to principal account', ['office' => $officeName]);

            // Mirror pending lines: office (debit pending) + principal (credit pending).
            // Both keep status pending until admin/president decides; then both flip together.
            $officeEntry = KamsTreasuryEntry::query()->create([
                'company_id' => $office->company_id,
                'account_id' => $office->id,
                'office_id' => $office->office_id,
                'member_id' => null,
                'type' => 'transfer_deposit',
                'status' => 'pending',
                'amount' => -abs($value),
                'occurred_on' => $occurredOn,
                'notes' => $note,
                'created_by' => $userId,
                'requested_by' => $userId,
                'performed_by' => null,
            ]);

            $centralEntry = KamsTreasuryEntry::query()->create([
                'company_id' => $central->company_id,
                'account_id' => $central->id,
                'office_id' => $office->office_id,
                'member_id' => null,
                'related_entry_id' => $officeEntry->id,
                'type' => 'transfer_deposit',
                'status' => 'pending',
                'amount' => abs($value),
                'occurred_on' => $occurredOn,
                'notes' => $note,
                'created_by' => $userId,
                'requested_by' => $userId,
                'performed_by' => null,
            ]);

            $officeEntry->forceFill(['related_entry_id' => $centralEntry->id])->save();

            $fresh = $centralEntry->fresh(['createdBy', 'requestedBy', 'office', 'account.office']);
            $this->notifyBankApprovers($fresh);

            return $fresh;
        });
    }

    public function decideBankDeposit(KamsTreasuryEntry $entry, string $decision, int $userId, string $motif): KamsTreasuryEntry
    {
        abort_unless($entry->status === 'pending', 422, __('This request cannot be processed.'));
        abort_unless($entry->type === 'transfer_deposit', 422, __('This request cannot be processed.'));
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, __('Unknown decision.'));
        if ($decision === 'reject') {
            abort_unless(trim($motif) !== '', 422, __('A reason is required to reject this request.'));
        }

        return DB::transaction(function () use ($entry, $decision, $userId, $motif) {
            $entry->loadMissing(['account', 'office', 'related']);
            $central = $entry->account;
            abort_unless($central && $central->isCentral(), 422, __('Unknown treasury account.'));
            abort_unless($entry->office_id, 422, __('This deposit is not linked to an office account.'));

            $office = $this->ensureOffice(
                Office::query()->findOrFail((int) $entry->office_id)
            );
            $office->loadMissing('office:id,name,code');

            $officeEntry = $entry->related_entry_id
                ? KamsTreasuryEntry::query()
                    ->whereKey($entry->related_entry_id)
                    ->where('account_id', $office->id)
                    ->where('type', 'transfer_deposit')
                    ->where('status', 'pending')
                    ->first()
                : null;

            if ($decision === 'approve') {
                $value = round(abs((float) $entry->amount), 2);
                $reservedOther = (float) KamsTreasuryEntry::query()
                    ->where('account_id', $office->id)
                    ->where('type', 'transfer_deposit')
                    ->where('status', 'pending')
                    ->when($officeEntry, fn ($query) => $query->where('id', '!=', $officeEntry->id))
                    ->sum('amount');
                $free = round($this->displayedBalance($office) - abs($reservedOther), 2);
                abort_unless($value <= $free + 0.001, 422, __('Insufficient office account balance.'));

                $officeName = $office->office?->name ?: $office->name;

                if ($officeEntry) {
                    $officeEntry->forceFill([
                        'status' => 'posted',
                        'type' => 'transfer',
                        'performed_by' => $userId,
                        'decision_notes' => $motif !== '' ? $motif : null,
                        'notes' => $officeEntry->notes ?: __('Transfer to principal account'),
                    ])->save();
                } else {
                    $officeEntry = KamsTreasuryEntry::query()->create([
                        'company_id' => $office->company_id,
                        'account_id' => $office->id,
                        'office_id' => $office->office_id,
                        'member_id' => null,
                        'related_entry_id' => $entry->id,
                        'type' => 'transfer',
                        'status' => 'posted',
                        'amount' => -abs($value),
                        'occurred_on' => $entry->occurred_on?->toDateString() ?: now()->toDateString(),
                        'notes' => __('Transfer to principal account'),
                        'created_by' => $userId,
                        'requested_by' => $entry->requested_by ?: $userId,
                        'performed_by' => $userId,
                        'decision_notes' => $motif !== '' ? $motif : null,
                    ]);
                }

                $entry->forceFill([
                    'status' => 'posted',
                    'type' => 'transfer',
                    'related_entry_id' => $officeEntry->id,
                    'performed_by' => $userId,
                    'decision_notes' => $motif !== '' ? $motif : null,
                    'notes' => $entry->notes ?: __('Transfer from :office', ['office' => $officeName]),
                ])->save();

                if ((int) $officeEntry->related_entry_id !== (int) $entry->id) {
                    $officeEntry->forceFill(['related_entry_id' => $entry->id])->save();
                }

                $this->refreshBalance($office);
                $this->refreshBalance($central);
                $this->recordBalanceAfter($officeEntry->fresh());
                $this->recordBalanceAfter($entry->fresh());
            } else {
                $entry->forceFill([
                    'status' => 'rejected',
                    'performed_by' => $userId,
                    'decision_notes' => $motif,
                ])->save();
                if ($officeEntry) {
                    $officeEntry->forceFill([
                        'status' => 'rejected',
                        'performed_by' => $userId,
                        'decision_notes' => $motif,
                    ])->save();
                    $this->recordBalanceAfter($officeEntry->fresh());
                }
                $this->recordBalanceAfter($entry->fresh());
            }

            $fresh = $entry->fresh(['createdBy', 'requestedBy', 'performedBy', 'office', 'account.office']);
            $this->notifyRequester($fresh, $decision, $motif);

            return $fresh;
        });
    }

    /**
     * Day-close cash: company share stays on the selling office.
     * Each agent amount (margin and office percentages) is recorded on the office that agent belongs to.
     */
    public function recordOfficeSaleCollection(KamsMember $member, KamsStockSale $sale, float $collectedDue, int $userId): ?KamsTreasuryEntry
    {
        $shareIds = KamsRevenueShare::query()->where('sale_id', $sale->id)->pluck('id');
        $savings = KamsSavingsEntry::query()
            ->where('status', 'posted')
            ->where(function ($query) use ($sale, $shareIds) {
                $query->where(function ($query) use ($sale) {
                    $query->where('source_type', KamsStockSale::class)->where('source_id', $sale->id);
                });
                if ($shareIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($shareIds) {
                        $query->where('source_type', KamsRevenueShare::class)->whereIn('source_id', $shareIds);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        $officeAmount = round($collectedDue - (float) $savings->sum('amount'), 2);
        $entry = null;
        if ($officeAmount > 0.001) {
            $entry = $this->post(
                $this->accountForMember($member),
                'deposit',
                $officeAmount,
                'Encaissement clôture '.$sale->reference,
                $userId,
            );
        }

        foreach ($savings as $saved) {
            $this->mirrorSavingsToOffice($saved, $userId);
        }

        $this->refreshBalance($this->ensureForCompany((int) $member->company_id));

        return $entry;
    }

    /**
     * Record an agent's posted savings on the treasury account of the office they belong to.
     */
    public function mirrorSavingsToOffice(KamsSavingsEntry $entry, ?int $userId = null): ?KamsTreasuryEntry
    {
        if ($entry->status !== 'posted' || abs((float) $entry->amount) < 0.001) {
            return null;
        }
        if ($entry->source_type === KamsTreasuryEntry::class) {
            return null;
        }
        if (KamsTreasuryEntry::query()->where('savings_entry_id', $entry->id)->exists()) {
            return null;
        }

        $member = $entry->member ?: KamsMember::query()->find($entry->member_id);
        if (! $member) {
            return null;
        }

        $account = $this->accountForMember($member);
        $amount = round((float) $entry->amount, 2);
        $type = $amount < 0 ? 'withdrawal' : 'deposit';
        $walletLabel = match ((string) $entry->kind) {
            'salary' => 'Compte Salaire',
            'percentage' => 'Compte Overed',
            'assistant_manager' => 'Compte assistant',
            'secretary' => 'Compte secrétaire',
            'divisionnaire' => 'Compte divisionnaire',
            'manager' => 'Compte manager',
            default => 'Compte agent',
        };
        $notes = trim(($entry->notes ? $entry->notes.' · ' : '').$walletLabel);
        $treasury = KamsTreasuryEntry::query()->create([
            'company_id' => $account->company_id,
            'account_id' => $account->id,
            'office_id' => $account->office_id,
            'member_id' => $member->id,
            'savings_entry_id' => $entry->id,
            'type' => $type,
            'status' => 'posted',
            'amount' => $amount,
            'occurred_on' => $entry->occurred_on?->toDateString() ?: now()->toDateString(),
            'notes' => $notes !== '' ? $notes : null,
            'created_by' => $userId ?: $entry->created_by,
            'requested_by' => $entry->requested_by ?: $userId,
            'performed_by' => $userId ?: $entry->performed_by,
        ]);
        // Office refresh also recomputes the company central balance.
        $this->refreshBalance($account);
        $this->recordBalanceAfter($treasury);

        return $treasury;
    }

    public function deposit(KamsTreasuryAccount $account, float $amount, string $notes, int $userId, ?KamsMember $member = null, ?string $kind = null): KamsTreasuryEntry
    {
        return $this->post($account, 'deposit', abs($amount), $notes, $userId, $member, $kind);
    }

    public function withdraw(KamsTreasuryAccount $account, float $amount, string $notes, int $userId, ?KamsMember $member = null, ?string $kind = null): KamsTreasuryEntry
    {
        abort_unless($amount <= $this->spendable($account) + 0.001, 422, __('Insufficient account balance.'));

        return $this->post($account, 'withdrawal', -abs($amount), $notes, $userId, $member, $kind);
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
        // Principal account is a bank: only its own posted movements count (no auto-sync from offices).
        // A commission transfer reduces this balance only after the beneficiary accepts it.
        return round($this->available($account) + $this->unmirroredSavingsTotal($account), 2);
    }

    public function spendable(KamsTreasuryAccount $account): float
    {
        $balance = $this->displayedBalance($account);
        if ($account->isCentral() || ! $account->office_id) {
            return $balance;
        }

        // Keep pending transfers reserved so they cannot be spent twice, without lowering the shown balance.
        $reserved = $this->pendingBankDepositTotal($account) + $this->pendingCommissionTransferTotal($account);

        return round(max(0, $balance - $reserved), 2);
    }

    private function pendingCommissionTransferTotal(KamsTreasuryAccount $account): float
    {
        return round(abs((float) KamsTreasuryEntry::query()
            ->where('account_id', $account->id)
            ->where('type', 'commission_transfer')
            ->where('status', 'pending')
            ->sum('amount')), 2);
    }

    private function pendingBankDepositTotal(KamsTreasuryAccount $account): float
    {
        $total = (float) KamsTreasuryEntry::query()
            ->where('account_id', $account->id)
            ->where('type', 'transfer_deposit')
            ->where('status', 'pending')
            ->sum('amount');

        // Older requests may exist only on the principal ledger for this office.
        if (abs($total) < 0.001 && $account->office_id && ! $account->isCentral()) {
            $centralId = KamsTreasuryAccount::query()
                ->where('company_id', $account->company_id)
                ->whereNull('office_id')
                ->value('id');
            if ($centralId) {
                $total = (float) KamsTreasuryEntry::query()
                    ->where('account_id', $centralId)
                    ->where('office_id', $account->office_id)
                    ->where('type', 'transfer_deposit')
                    ->where('status', 'pending')
                    ->sum('amount');
            }
        }

        return round(abs($total), 2);
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

    public function recalculateBalanceSnapshots(): void
    {
        $entries = KamsTreasuryEntry::query()->orderBy('created_at')->orderBy('id')->get();
        foreach ($entries as $entry) {
            $this->recordBalanceAfter($entry);
        }
    }

    private function recordBalanceAfter(?KamsTreasuryEntry $entry): void
    {
        if (! $entry || ! in_array($entry->status ?: 'posted', ['posted', 'rejected'], true)) {
            return;
        }

        $posted = KamsTreasuryEntry::query()
            ->where('account_id', $entry->account_id)
            ->where('status', 'posted');
        $this->limitToPriorEntries($posted, $entry, $entry->status !== 'rejected');
        $entry->forceFill(['balance_after' => round((float) $posted->sum('amount'), 2)])->save();
    }

    private function limitToPriorEntries($query, KamsTreasuryEntry $entry, bool $includeSelf): void
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
     * @param  Collection<int, KamsTreasuryEntry>  $treasury
     * @param  Collection<int, KamsSavingsEntry>  $savings
     * @return list<array<string, mixed>>
     */
    private function timelineWithBalances(KamsTreasuryAccount $account, $treasury, $savings): array
    {
        $events = [];
        foreach ($treasury as $entry) {
            $events[] = ['kind' => 'treasury', 'at' => $entry->created_at?->format('Y-m-d H:i:s') ?? '', 'id' => (int) $entry->id, 'entry' => $entry];
        }
        foreach ($savings as $entry) {
            $events[] = ['kind' => 'savings', 'at' => $entry->created_at?->format('Y-m-d H:i:s') ?? '', 'id' => (int) $entry->id, 'entry' => $entry];
        }
        usort($events, function (array $a, array $b) {
            $time = strcmp($a['at'], $b['at']);
            if ($time !== 0) {
                return $time;
            }
            $kind = strcmp($a['kind'], $b['kind']);
            if ($kind !== 0) {
                return $kind;
            }

            return $a['id'] <=> $b['id'];
        });

        $officeAccounts = KamsTreasuryAccount::query()
            ->where('company_id', $account->company_id)
            ->whereNotNull('office_id')
            ->pluck('id', 'office_id');
        $buckets = [];
        $rows = [];
        foreach ($events as $event) {
            $entry = $event['entry'];
            $bucketId = $event['kind'] === 'treasury'
                ? (int) $entry->account_id
                : (int) ($officeAccounts[$entry->member?->office_id] ?? $account->id);
            if ($event['kind'] === 'savings' && ! $account->isCentral()) {
                $bucketId = (int) $account->id;
            }
            $buckets[$bucketId] ??= 0.0;
            $counts = ($event['kind'] === 'treasury' && ($entry->status ?: 'posted') === 'posted')
                || ($event['kind'] === 'savings' && ($entry->status ?: 'posted') === 'posted');
            if ($counts) {
                $buckets[$bucketId] = round($buckets[$bucketId] + (float) $entry->amount, 2);
            }
            $balance = round($buckets[(int) $account->id] ?? 0, 2);
            if ($event['kind'] === 'treasury') {
                // Member deposit/withdrawal requests act on the agent wallet — show that balance.
                if ($this->isMemberAccountRequest($entry)) {
                    $balance = $this->memberWalletBalanceForRequest($entry);
                }
                $entry->setAttribute('balance_after', $balance);
                $rows[] = $this->serializeEntry($entry);
            } else {
                $rows[] = $this->serializeSavingsEntry($entry, $balance, $account);
            }
        }

        return $rows;
    }

    private function isMemberAccountRequest(KamsTreasuryEntry $entry): bool
    {
        if (! $entry->member_id) {
            return false;
        }

        return in_array($entry->type, ['deposit', 'withdrawal', 'commission_transfer'], true)
            && ($entry->status ?: 'posted') === 'pending';
    }

    private function memberWalletBalanceForRequest(KamsTreasuryEntry $entry): float
    {
        $entry->loadMissing('member');
        $member = $entry->member;
        if (! $member) {
            return 0.0;
        }

        $queued = $this->savingsFor($entry);
        $kind = $queued?->kind;
        $savings = app(SavingsLedger::class);

        return $savings->availableBalance($member, $kind ?: $savings->defaultManualKind($member));
    }

    private function unmirroredSavingsTotal(KamsTreasuryAccount $account): float
    {
        return round((float) $this->unmirroredSavingsEntries($account, false)->sum('amount'), 2);
    }

    /**
     * Savings that are not already posted on a treasury account.
     *
     * @return Collection<int, KamsSavingsEntry>
     */
    private function unmirroredSavingsEntries(KamsTreasuryAccount $account, bool $companyWide)
    {
        $query = KamsSavingsEntry::query()
            ->with(['member:id,full_name,member_code,office_id', 'member.office:id,name,code'])
            ->where('status', 'posted')
            ->where('company_id', $account->company_id)
            ->where(function ($query) {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', KamsTreasuryEntry::class)
                    ->orWhere(function ($query) {
                        $query->where('source_type', KamsTreasuryEntry::class)
                            ->whereIn('source_id', KamsTreasuryEntry::query()
                                ->where('type', 'commission_transfer')
                                ->select('id'));
                    });
            })
            ->whereNotIn('id', KamsTreasuryEntry::query()->whereNotNull('savings_entry_id')->select('savings_entry_id'));
        if (! $companyWide) {
            if ($account->isCentral()) {
                $query->whereHas('member', fn ($query) => $query->whereNull('office_id'));
            } else {
                $query->whereHas('member', fn ($query) => $query->where('office_id', $account->office_id));
            }
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSavingsEntry(KamsSavingsEntry $entry, float $balance, KamsTreasuryAccount $account): array
    {
        $holder = $entry->member?->office?->name ?: ($account->isCentral() ? 'KAMS' : $account->office?->name);

        return [
            'uid' => 'savings-'.$entry->id,
            'id' => $entry->id,
            'kind' => $entry->kind,
            'type' => $entry->type,
            'status' => $entry->status ?: 'posted',
            'amount' => round((float) $entry->amount, 2),
            'balance_after' => round($balance, 2),
            'occurred_on' => $entry->occurred_on?->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes' => $entry->notes,
            'decision_notes' => null,
            'holder' => $holder,
            'member' => $entry->member ? [
                'id' => $entry->member->id,
                'full_name' => $entry->member->full_name,
                'member_code' => $entry->member->member_code,
            ] : null,
            'requested_by' => null,
            'performed_by' => null,
            'created_by' => null,
            'source' => [
                'type' => 'savings',
                'id' => $entry->id,
                'label' => $holder,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(KamsTreasuryAccount $account, ?float $own = null, ?float $officeTotal = null): array
    {
        $this->ensureOfficeBankDepositMirrors($account);
        $posted = KamsTreasuryEntry::query()->where('account_id', $account->id)->where('status', 'posted');
        $own ??= $this->available($account);
        $balance = $this->displayedBalance($account);

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'is_central' => $account->isCentral(),
            'office' => $account->office?->only(['id', 'name', 'code']),
            'balance' => $balance,
            'available' => $this->spendable($account),
            'pending_deposits' => $this->pendingBankDepositTotal($account),
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
            ->sortByDesc(fn (KamsTreasuryAccount $office) => $this->spendable($office))
            ->values();

        foreach ($offices as $office) {
            if ($needed <= 0.001) {
                break;
            }
            $take = round(min($this->spendable($office), $needed), 2);
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
            'notes' => __('Transfer to principal account'),
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
            'notes' => __('Transfer from :office', ['office' => $officeName]),
            'created_by' => $userId,
            'requested_by' => $userId,
            'performed_by' => $userId,
        ]);
        $outgoing->forceFill(['related_entry_id' => $incoming->id])->save();
        $incoming->forceFill(['related_entry_id' => $outgoing->id])->save();
        $this->refreshBalance($office);
        $this->recordBalanceAfter($outgoing->fresh());
        $this->recordBalanceAfter($incoming->fresh());
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

    public function requestDeposit(KamsMember $member, float $amount, string $notes, int $userId, ?string $accountKind = null): KamsTreasuryEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));

        return $this->queueMemberRequest($member, 'deposit', abs($amount), $notes, $userId, $accountKind);
    }

    /**
     * Pending commission transfer: cash stays on the office account until the beneficiary
     * accepts it in Mon compte. Acceptance credits their wallet and deducts the office.
     */
    public function requestCommissionTransfer(
        KamsTreasuryAccount $account,
        KamsMember $member,
        float $amount,
        string $notes,
        int $userId,
        ?string $accountKind = null,
    ): KamsTreasuryEntry {
        abort_unless($account->office_id, 422, __('Commissions are only available on office accounts.'));
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        abort_unless((int) $member->company_id === (int) $account->company_id, 422, __('This member does not belong to this account.'));
        abort_unless($amount <= $this->spendable($account) + 0.001, 422, __('Insufficient office account balance.'));

        return DB::transaction(function () use ($account, $member, $amount, $notes, $userId, $accountKind) {
            $entry = KamsTreasuryEntry::query()->create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'office_id' => $account->office_id,
                'member_id' => $member->id,
                'type' => 'commission_transfer',
                'status' => 'pending',
                'amount' => round($amount, 2),
                'occurred_on' => now()->toDateString(),
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
                'requested_by' => $userId,
                'performed_by' => null,
            ]);

            $savings = app(SavingsLedger::class);
            $savings->queue(
                $member,
                'deposit_request',
                round($amount, 2),
                $notes,
                $userId,
                $entry,
                $accountKind ?: $savings->defaultManualKind($member),
            );

            $fresh = $entry->fresh(['createdBy', 'requestedBy', 'member', 'account.office']);
            $this->refreshBalance($account);
            $this->notifyCommissionBeneficiary($fresh);

            return $fresh;
        });
    }

    /**
     * Beneficiary accepts or rejects a commission transfer on Mon compte.
     * Accept → credit member wallet and deduct the office treasury.
     */
    public function decideCommissionTransfer(KamsTreasuryEntry $entry, string $decision, int $userId, string $motif): KamsTreasuryEntry
    {
        abort_unless($entry->type === 'commission_transfer', 422, __('This request cannot be processed.'));
        abort_unless($entry->status === 'pending', 422, __('This request cannot be processed.'));
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
            $value = abs((float) $entry->amount);

            if ($decision === 'approve') {
                // This pending transfer is still in the displayed balance. Exclude only the other reservations.
                $otherPending = max(0, $this->pendingCommissionTransferTotal($account) - $value);
                $free = $this->displayedBalance($account) - $this->pendingBankDepositTotal($account) - $otherPending;
                abort_unless($value <= $free + 0.001, 422, __('Insufficient office account balance.'));

                if ($queued) {
                    $overed = $savings->account($member, 'percentage');
                    if ((string) $queued->kind !== 'percentage' || (int) $queued->account_id !== (int) $overed->id) {
                        $queued->forceFill([
                            'kind' => 'percentage',
                            'account_id' => $overed->id,
                        ])->save();
                    }
                    $savings->postQueued($queued, $userId, $motif);
                } else {
                    $savings->deposit($member, $value, (string) $entry->notes, $userId, $entry, $entry->requested_by, 'percentage');
                }

                // Deduct office cash (commission leaves the office pool).
                $entry->forceFill([
                    'status' => 'posted',
                    'amount' => - $value,
                    'performed_by' => $userId,
                    'decision_notes' => $motif !== '' ? $motif : null,
                ])->save();
                $this->refreshBalance($account);
                $this->recordBalanceAfter($entry->fresh());

                app(OfficeCommissionService::class)->markTransferPaid($entry);

                $member->refresh();
                if ($member->office_id) {
                    $this->refreshBalance($this->ensureOffice(Office::query()->findOrFail($member->office_id)));
                }
            } else {
                $entry->forceFill([
                    'status' => 'rejected',
                    'performed_by' => $userId,
                    'decision_notes' => $motif,
                ])->save();
                $this->recordBalanceAfter($entry->fresh());
                if ($queued) {
                    $savings->rejectQueued($queued, $userId, $motif);
                }
                app(OfficeCommissionService::class)->markTransferRejected($entry);
            }

            return $entry->fresh(['createdBy', 'requestedBy', 'performedBy', 'member', 'account.office']);
        });
    }

    public function requestWithdrawal(KamsMember $member, float $amount, string $notes, int $userId, ?string $accountKind = null): KamsTreasuryEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        $savings = app(SavingsLedger::class);
        $wallet = $accountKind ?: $savings->defaultManualKind($member);
        abort_unless($amount <= $savings->withdrawableBalance($member, $wallet) + 0.001, 422, __('Insufficient account balance.'));

        return $this->queueMemberRequest($member, 'withdrawal', -abs($amount), $notes, $userId, $wallet);
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
                    // Pending withdrawal_request already reserves this amount in withdrawableBalance;
                    // approval must check posted availability instead.
                    $walletKind = $queued?->kind ?: $savings->defaultManualKind($member);
                    abort_unless($value <= $savings->availableBalance($member, $walletKind) + 0.001, 422, __('Insufficient account balance.'));
                }
                $entry->forceFill([
                    'status' => 'posted',
                    'performed_by' => $userId,
                    'decision_notes' => $motif !== '' ? $motif : null,
                ])->save();
                $this->refreshBalance($account);
                $this->recordBalanceAfter($entry->fresh());
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
                $this->recordBalanceAfter($entry->fresh());
                if ($queued) {
                    $savings->rejectQueued($queued, $userId, $motif);
                }
            }

            $fresh = $entry->fresh(['createdBy', 'requestedBy', 'performedBy', 'member', 'account.office']);
            $this->notifyRequester($fresh, $decision, $motif);

            return $fresh;
        });
    }

    private function notifyCommissionBeneficiary(KamsTreasuryEntry $entry): void
    {
        $entry->loadMissing(['member', 'account.office']);
        $userId = (int) ($entry->member?->user_id ?: 0);
        if ($userId <= 0) {
            return;
        }

        $send = function () use ($entry, $userId) {
            $user = User::query()->find($userId);
            if (! $user) {
                return;
            }
            $locale = $user->locale === 'en' ? 'en' : 'fr';
            $amount = number_format(abs((float) $entry->amount), 2, '.', ' ');
            $office = (string) ($entry->account?->office?->name ?: '');
            $title = $locale === 'en' ? 'Commission transfer to accept' : 'Transfert de commission à accepter';
            $body = $locale === 'en'
                ? ("A commission transfer of {$amount}".($office !== '' ? " from {$office}" : '').' is waiting in My account.')
                : ("Un transfert de commission de {$amount}".($office !== '' ? " depuis {$office}" : '').' vous attend dans Mon compte.');

            AppNotification::query()->create([
                'user_id' => $user->id,
                'type' => 'finance_commission_transfer',
                'title' => $title,
                'body' => $body,
                'data' => [
                    'entry_id' => $entry->id,
                    'amount' => (float) $entry->amount,
                    'office' => $office,
                    'path' => '/modules/kams/finance/account',
                ],
            ]);

            app(KamsAccountNotice::class)->send($user, [
                'purpose' => 'confirm',
                'movement' => 'transfer',
                'amount' => (float) $entry->amount,
                'notes' => $entry->notes,
                'office' => $office !== '' ? $office : null,
                'action_url' => app(KamsAccountNotice::class)->frontend('/modules/kams/finance/account'),
            ]);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
    }

    private function notifyRequester(KamsTreasuryEntry $entry, string $decision, string $motif): void
    {
        $userId = (int) ($entry->requested_by ?: 0);
        if ($userId <= 0) {
            return;
        }

        $payload = [
            'decision' => $decision,
            'type' => (string) $entry->type,
            'amount' => (float) $entry->amount,
            'notes' => $entry->notes,
            'reason' => $decision === 'reject' ? $motif : null,
            'member_name' => $entry->member?->full_name,
            'entry_id' => $entry->id,
        ];

        $send = function () use ($userId, $payload) {
            $user = User::query()->find($userId);
            if (! $user) {
                return;
            }

            $locale = $user->locale === 'en' ? 'en' : 'fr';
            $approved = ($payload['decision'] ?? '') === 'approve';
            $type = match ($payload['type'] ?? '') {
                'deposit', 'transfer_deposit', 'transfer' => $locale === 'en' ? 'deposit' : 'dépôt',
                default => $locale === 'en' ? 'withdrawal' : 'retrait',
            };
            $amount = number_format(abs((float) ($payload['amount'] ?? 0)), 2, '.', ' ');
            $title = $locale === 'en'
                ? ($approved ? 'Finance request approved' : 'Finance request rejected')
                : ($approved ? 'Demande financière acceptée' : 'Demande financière refusée');
            $body = $locale === 'en'
                ? ("Your {$type} request of {$amount} was ".($approved ? 'approved' : 'rejected').'.')
                : ("Votre demande de {$type} de {$amount} a été ".($approved ? 'acceptée' : 'refusée').'.');
            if (! $approved && ! empty($payload['reason'])) {
                $body .= $locale === 'en'
                    ? (' Reason: '.$payload['reason'])
                    : (' Motif : '.$payload['reason']);
            }

            AppNotification::query()->create([
                'user_id' => $user->id,
                'type' => $approved ? 'finance_approved' : 'finance_rejected',
                'title' => $title,
                'body' => $body,
                'channel' => 'in_app',
                'data' => [
                    'module' => 'kams',
                    'entry_id' => $payload['entry_id'] ?? null,
                    'decision' => $payload['decision'] ?? null,
                    'type' => $payload['type'] ?? null,
                ],
            ]);

            if (($payload['decision'] ?? '') !== 'approve' && filled($user->email)) {
                try {
                    Mail::to($user->email)->send(new FinanceRequestDecisionMail($user, $payload));
                } catch (Throwable) {
                    // Decision must succeed even if mail delivery fails.
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
    }

    private function queueMemberRequest(KamsMember $member, string $type, float $amount, string $notes, int $userId, ?string $accountKind = null): KamsTreasuryEntry
    {
        return DB::transaction(function () use ($member, $type, $amount, $notes, $userId, $accountKind) {
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
            $savings = app(SavingsLedger::class);
            $savings->queue(
                $member,
                $type === 'deposit' ? 'deposit_request' : 'withdrawal_request',
                $amount,
                $notes,
                $userId,
                $entry,
                $accountKind ?: $savings->defaultManualKind($member),
            );

            $fresh = $entry->fresh(['createdBy', 'requestedBy', 'member', 'account.office']);
            $this->notifyApprovers($fresh);

            return $fresh;
        });
    }

    private function notifyApprovers(KamsTreasuryEntry $entry): void
    {
        $entry->loadMissing(['member', 'account.office', 'member.office']);
        $companyId = (int) $entry->company_id;
        $officeId = $entry->office_id
            ? (int) $entry->office_id
            : ($entry->member?->office_id ? (int) $entry->member->office_id : null);

        $excludeIds = collect([(int) ($entry->requested_by ?: 0), (int) ($entry->created_by ?: 0)])
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $send = function () use ($entry, $companyId, $officeId, $excludeIds) {
            $approvers = User::query()
                ->where('is_active', true)
                ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
                ->where(function ($query) {
                    $query->whereHas('roles', fn ($roles) => $roles->whereIn('slug', RoleCatalog::fullAccessSlugs()))
                        ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('slug', 'kams.finance.company.approve'));
                })
                ->with(['roles.permissions', 'scopes'])
                ->get();

            $officeName = (string) ($entry->account?->office?->name ?: $entry->member?->office?->name ?: '');
            $amount = number_format(abs((float) $entry->amount), 2, '.', ' ');
            $isDeposit = $entry->type === 'deposit';
            $rawMemberName = trim((string) ($entry->member?->full_name ?: ''));
            $pathQuery = array_filter([
                'member_id' => $entry->member_id ? (int) $entry->member_id : null,
                'office_id' => $officeId,
            ]);
            $path = '/modules/kams/finance/accounts'.($pathQuery !== [] ? '?'.http_build_query($pathQuery) : '');

            foreach ($approvers as $user) {
                if (! $user->isGroupAdmin() && ! $user->hasPermission('kams.finance.company.approve')) {
                    continue;
                }
                if (! $user->canAccessCompany($companyId)) {
                    continue;
                }
                // Office cashiers only see requests for offices they can access.
                if ($officeId && ! $user->isGroupAdmin() && $user->isOfficeScoped() && ! $user->canAccessOffice($officeId)) {
                    continue;
                }

                $locale = $user->locale === 'en' ? 'en' : 'fr';
                $memberName = $rawMemberName !== ''
                    ? $rawMemberName
                    : ($locale === 'en' ? 'A member' : 'Un membre');
                $typeLabel = $isDeposit
                    ? ($locale === 'en' ? 'deposit' : 'dépôt')
                    : ($locale === 'en' ? 'withdrawal' : 'retrait');
                $title = $locale === 'en'
                    ? ('New '.$typeLabel.' request')
                    : ('Nouvelle demande de '.$typeLabel);
                $body = $locale === 'en'
                    ? ("{$memberName} requested a {$typeLabel} of {$amount}.")
                    : ("{$memberName} a demandé un {$typeLabel} de {$amount}.");
                if ($officeName !== '') {
                    $body .= $locale === 'en'
                        ? (" Office: {$officeName}.")
                        : (" Bureau : {$officeName}.");
                }

                AppNotification::query()->create([
                    'user_id' => $user->id,
                    'type' => 'finance_request',
                    'title' => $title,
                    'body' => $body,
                    'channel' => 'in_app',
                    'data' => [
                        'module' => 'kams',
                        'entry_id' => $entry->id,
                        'type' => $entry->type,
                        'office_id' => $officeId,
                        'company_id' => $companyId,
                        'member_id' => $entry->member_id,
                        'path' => $path,
                    ],
                ]);

                app(KamsAccountNotice::class)->send($user, [
                    'purpose' => 'confirm',
                    'movement' => $isDeposit ? 'deposit' : 'withdrawal',
                    'amount' => (float) $entry->amount,
                    'notes' => $entry->notes,
                    'office' => $officeName !== '' ? $officeName : null,
                    'member_name' => $rawMemberName !== '' ? $rawMemberName : null,
                    'action_url' => app(KamsAccountNotice::class)->frontend($path),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
    }

    /**
     * Notify admin/president (company-wide approvers) of an office→bank deposit request.
     */
    private function notifyBankApprovers(KamsTreasuryEntry $entry): void
    {
        $entry->loadMissing(['office', 'account']);
        $companyId = (int) $entry->company_id;
        $officeId = $entry->office_id ? (int) $entry->office_id : null;
        $excludeIds = collect([(int) ($entry->requested_by ?: 0), (int) ($entry->created_by ?: 0)])
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $send = function () use ($entry, $companyId, $officeId, $excludeIds) {
            $candidates = User::query()
                ->where('is_active', true)
                ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
                ->where(function ($query) {
                    $query->whereHas('roles', fn ($roles) => $roles->whereIn('slug', RoleCatalog::fullAccessSlugs()))
                        ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('slug', 'kams.finance.company.approve'));
                })
                ->with(['roles.permissions', 'scopes'])
                ->get();

            $officeName = (string) ($entry->office?->name ?: '');
            $amount = number_format(abs((float) $entry->amount), 2, '.', ' ');

            foreach ($candidates as $user) {
                if (! $user->canAccessCompany($companyId)) {
                    continue;
                }
                // Office-scoped managers validate local member requests, not the principal bank inbox.
                if (! $user->isGroupAdmin() && $user->isOfficeScoped()) {
                    continue;
                }
                if (! $user->isGroupAdmin() && ! $user->hasPermission('kams.finance.company.approve')) {
                    continue;
                }

                $locale = $user->locale === 'en' ? 'en' : 'fr';
                $title = $locale === 'en'
                    ? 'New bank deposit request'
                    : 'Nouvelle demande de dépôt (compte principal)';
                $body = $locale === 'en'
                    ? ("A deposit of {$amount} to the principal account requires validation.")
                    : ("Un dépôt de {$amount} vers le compte principal nécessite une validation.");
                if ($officeName !== '') {
                    $body .= $locale === 'en'
                        ? (" Office: {$officeName}.")
                        : (" Bureau : {$officeName}.");
                }

                AppNotification::query()->create([
                    'user_id' => $user->id,
                    'type' => 'finance_request',
                    'title' => $title,
                    'body' => $body,
                    'channel' => 'in_app',
                    'data' => [
                        'module' => 'kams',
                        'entry_id' => $entry->id,
                        'type' => $entry->type,
                        'office_id' => $officeId,
                        'company_id' => $companyId,
                        'path' => '/modules/kams/finance/company',
                    ],
                ]);

                app(KamsAccountNotice::class)->send($user, [
                    'purpose' => 'confirm',
                    'movement' => 'deposit',
                    'amount' => (float) $entry->amount,
                    'notes' => $entry->notes,
                    'office' => $officeName !== '' ? $officeName : null,
                    'action_url' => app(KamsAccountNotice::class)->frontend('/modules/kams/finance/company'),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
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

    private function post(KamsTreasuryAccount $account, string $type, float $amount, string $notes, int $userId, ?KamsMember $member = null, ?string $kind = null): KamsTreasuryEntry
    {
        abort_unless($amount != 0.0, 422, __('The amount must be greater than zero.'));

        return DB::transaction(function () use ($account, $type, $amount, $notes, $userId, $member, $kind) {
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
            $this->recordBalanceAfter($entry);

            if ($member) {
                $savings = app(SavingsLedger::class);
                $value = abs($amount);
                match ($type) {
                    'deposit' => $savings->deposit($member, $value, $notes, $userId, $entry, $requestedBy, $kind),
                    'withdrawal' => $savings->withdraw($member, $value, $notes, $userId, $entry, $requestedBy, $kind),
                    default => $savings->expense($member, $value, $notes, $userId, $entry, $requestedBy, $kind),
                };
            }

            return $entry->fresh(['createdBy', 'requestedBy', 'performedBy', 'member']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(KamsTreasuryEntry $entry): array
    {
        $entry->loadMissing(['account:id,name,code,office_id', 'account.office:id,name,code', 'office:id,name,code']);
        $holder = $entry->office?->name
            ?: ($entry->account?->office?->name
                ?: ($entry->account?->isCentral() ? 'KAMS' : $entry->account?->name));

        return [
            'uid' => 'treasury-'.$entry->id,
            'id' => $entry->id,
            'type' => $entry->type,
            'status' => $entry->status ?: 'posted',
            'amount' => round((float) $entry->amount, 2),
            'balance_after' => $entry->balance_after !== null ? round((float) $entry->balance_after, 2) : null,
            'occurred_on' => $entry->occurred_on?->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes' => $this->localizedNotes($entry),
            'decision_notes' => $entry->decision_notes ?? null,
            'holder' => $holder,
            'office' => $entry->office?->only(['id', 'name', 'code']),
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
                'label' => $holder,
            ],
        ];
    }

    /**
     * Re-emit known system note templates in the current request locale.
     */
    private function localizedNotes(KamsTreasuryEntry $entry): ?string
    {
        $notes = trim((string) ($entry->notes ?? ''));
        if ($notes === '') {
            return $entry->notes;
        }

        if (preg_match('/^Deposit from (.+) to principal account$/u', $notes, $match)
            || preg_match('/^Dépôt de (.+) vers le compte principal$/u', $notes, $match)
            || preg_match('/^Dépôt depuis (.+) vers le compte principal$/u', $notes, $match)) {
            return __('Deposit from :office to principal account', ['office' => $match[1]]);
        }

        if (in_array($notes, [
            'Transfer to principal account',
            'Transfert vers le compte principal',
            'Transfert vers le compte central',
        ], true)) {
            return __('Transfer to principal account');
        }

        if (preg_match('/^Transfer from (.+)$/u', $notes, $match)
            || preg_match('/^Transfert depuis (.+)$/u', $notes, $match)
            || preg_match('/^Transfert de (.+)$/u', $notes, $match)) {
            return __('Transfer from :office', ['office' => $match[1]]);
        }

        return $entry->notes;
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
