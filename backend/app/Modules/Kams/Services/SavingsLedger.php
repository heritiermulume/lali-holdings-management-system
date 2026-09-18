<?php

namespace App\Modules\Kams\Services;

use App\Models\User;
use App\Modules\Kams\Models\KamsDailySettlement;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockReturnItem;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use Illuminate\Support\Facades\DB;

class SavingsLedger
{
    public function account(KamsMember $member, string $kind): KamsSavingsAccount
    {
        abort_unless(in_array($kind, ['guy', 'manager'], true), 422, __('Unknown savings account.'));

        return KamsSavingsAccount::query()->firstOrCreate(
            ['member_id' => $member->id, 'kind' => $kind],
            ['company_id' => $member->company_id, 'balance' => 0]
        );
    }

    public function resolveManager(KamsMember $seller): ?KamsMember
    {
        if ($seller->manager_member_id) {
            return KamsMember::query()->find($seller->manager_member_id);
        }
        if ($seller->trainer_user_id) {
            $byUser = KamsMember::query()
                ->where('company_id', $seller->company_id)
                ->where('user_id', $seller->trainer_user_id)
                ->first();
            if ($byUser) {
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
                $q->where('rank', 'Manager')
                    ->orWhereHas('role', fn ($role) => $role->where('slug', 'manager'));
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

        $this->postUnique($seller, 'guy', (float) $sale->seller_margin, 'settlement', $sale, $sale->sold_at?->toDateString(), 'Guy margin on '.$sale->reference);
        if ($manager && (float) $sale->manager_margin > 0) {
            $this->postUnique($manager, 'manager', (float) $sale->manager_margin, 'settlement', $sale, $sale->sold_at?->toDateString(), 'Manager margin on '.$sale->reference);
        }
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

    private function postUnique(KamsMember $member, string $kind, float $amount, string $type, object $source, ?string $date, string $notes): void
    {
        if (abs($amount) < 0.001) {
            return;
        }
        $exists = KamsSavingsEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->id)
            ->where('member_id', $member->id)
            ->where('kind', $kind)
            ->where('type', $type)
            ->exists();
        if ($exists) {
            return;
        }
        $account = $this->account($member, $kind);
        KamsSavingsEntry::query()->create([
            'company_id' => $member->company_id,
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
            ->limit(80)
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
            ->limit(80)
            ->get()
            ->map(fn (KamsTreasuryEntry $entry) => $this->serializeTreasuryMirror($entry));
        $mapped = $mapped
            ->concat($orphans)
            ->sortByDesc(fn (array $row) => $row['created_at'] ?? $row['occurred_on'] ?? '')
            ->values()
            ->take(80)
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

        $balance = round((float) $accounts->sum('balance'), 2);
        if ($balance == 0.0) {
            $balance = round((float) (clone $posted)->sum('amount'), 2);
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
            'deposits' => round((float) (clone $posted)->where('type', 'deposit')->sum('amount'), 2),
            'withdrawals' => round(abs((float) (clone $posted)->whereIn('type', ['withdrawal', 'withdrawal_request'])->sum('amount')), 2),
            'expenses' => round(abs((float) (clone $posted)->where('type', 'expense')->sum('amount')), 2),
            'entries' => $mapped->all(),
        ];
    }

    public function deposit(KamsMember $member, float $amount, string $notes, int $userId, ?object $source = null, ?int $requestedBy = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));

        return $this->postManual($member, 'deposit', abs($amount), $notes, $userId, 'posted', $requestedBy, $userId, $source);
    }

    public function expense(KamsMember $member, float $amount, string $notes, int $userId, ?object $source = null, ?int $requestedBy = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        $available = $this->availableBalance($member);
        abort_unless($amount <= $available + 0.001, 422, __('Insufficient account balance.'));

        return $this->postManual($member, 'expense', -abs($amount), $notes, $userId, 'posted', $requestedBy, $userId, $source);
    }

    public function withdraw(KamsMember $member, float $amount, string $notes, int $userId, ?object $source = null, ?int $requestedBy = null): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        abort_unless($amount <= $this->withdrawableBalance($member) + 0.001, 422, __('Insufficient account balance.'));

        return $this->postManual($member, 'withdrawal', -abs($amount), $notes, $userId, 'posted', $requestedBy, $userId, $source);
    }

    public function queue(KamsMember $member, string $type, float $amount, string $notes, int $userId, object $source): KamsSavingsEntry
    {
        abort_unless(in_array($type, ['deposit_request', 'withdrawal_request'], true), 422, __('Unknown request type.'));

        return $this->postManual($member, $type, $amount, $notes, $userId, 'pending', $userId, null, $source);
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

        return $entry->fresh(['member', 'createdBy', 'requestedBy', 'performedBy', 'source']);
    }

    public function requestWithdrawal(KamsMember $member, float $amount, string $notes, int $userId): KamsSavingsEntry
    {
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));
        abort_unless($amount <= $this->withdrawableBalance($member) + 0.001, 422, __('Insufficient account balance.'));

        return $this->postManual($member, 'withdrawal_request', -abs($amount), $notes, $userId, 'pending', $userId, null);
    }

    public function approveWithdrawal(KamsSavingsEntry $entry, int $userId): KamsSavingsEntry
    {
        return $this->postQueued($entry, $userId, '');
    }

    public function rejectWithdrawal(KamsSavingsEntry $entry): KamsSavingsEntry
    {
        return $this->rejectQueued($entry, (int) ($entry->performed_by ?: $entry->created_by ?: 0), '');
    }

    public function availableBalance(KamsMember $member): float
    {
        return round((float) KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
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

    public function withdrawableBalance(KamsMember $member): float
    {
        $pending = (float) KamsSavingsEntry::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->where('type', 'withdrawal_request')
            ->sum('amount');

        return round($this->availableBalance($member) + $pending, 2);
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
    ): KamsSavingsEntry {
        $account = $this->account($member, 'guy');
        $requester = $requestedBy ?: ($member->user_id ?: $userId);
        $entry = KamsSavingsEntry::query()->create([
            'company_id' => $member->company_id,
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
        }

        return $entry->fresh(['member', 'createdBy', 'requestedBy', 'performedBy', 'source']);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEntry(KamsSavingsEntry $entry): array
    {
        $entry->loadMissing(['createdBy:id,name', 'requestedBy:id,name', 'performedBy:id,name', 'member:id,full_name,member_code,user_id', 'source']);

        return [
            'uid' => 'savings-'.$entry->id,
            'id' => $entry->id,
            'type' => $entry->type,
            'status' => $entry->status ?: 'posted',
            'amount' => round((float) $entry->amount, 2),
            'occurred_on' => $entry->occurred_on?->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes' => $entry->notes,
            'decision_notes' => $entry->decision_notes ?? null,
            'member' => $this->memberActor($entry->member),
            'requested_by' => $this->actor($entry->requestedBy, $entry->member?->full_name),
            'performed_by' => $this->actor($entry->performedBy),
            'created_by' => $this->actor($entry->createdBy),
            'source' => $this->sourcePayload($entry->source),
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
            ];
        }
        if ($source instanceof KamsStockSale) {
            return ['type' => 'sale', 'id' => $source->id, 'label' => $source->reference ?? null];
        }
        if ($source instanceof KamsStockReturn) {
            return ['type' => 'return', 'id' => $source->id, 'label' => $source->reference ?? null];
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
