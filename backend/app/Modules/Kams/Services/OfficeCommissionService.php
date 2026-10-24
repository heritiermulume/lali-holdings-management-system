<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use App\Modules\Kams\Support\RevenueRuleCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OfficeCommissionService
{
    public function __construct(
        private readonly TreasuryLedger $treasury,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function beneficiariesForOffice(KamsTreasuryAccount $account): array
    {
        abort_unless($account->office_id, 422, __('Commissions are only available on office accounts.'));

        $rows = $this->payableQuery((int) $account->company_id, (int) $account->office_id)
            ->with([
                'beneficiary:id,full_name,member_code,office_id,role_id,photo_path',
                'beneficiary.role:id,name,slug',
                'beneficiary.office:id,name,code',
            ])
            ->get();

        /** @var Collection<int, Collection<int, KamsRevenueShare>> $grouped */
        $grouped = $rows->groupBy(fn (KamsRevenueShare $share) => (int) $share->beneficiary_member_id);

        return $grouped->map(function (Collection $shares) {
            /** @var KamsMember|null $member */
            $member = $shares->first()?->beneficiary;
            if (! $member) {
                return null;
            }
            $openShares = $shares->filter(fn (KamsRevenueShare $share) => $share->transfer_entry_id === null);
            $pendingTransfer = $shares->contains(fn (KamsRevenueShare $share) => $share->transfer_entry_id !== null);
            $amount = round((float) $openShares->sum('amount'), 2);

            return [
                'member' => [
                    'id' => $member->id,
                    'full_name' => $member->full_name,
                    'member_code' => $member->member_code,
                    'photo_url' => $member->photo_url,
                    'role' => $member->role ? [
                        'id' => $member->role->id,
                        'name' => $member->role->name,
                        'slug' => $member->role->slug,
                    ] : null,
                    'office' => $member->office?->only(['id', 'name', 'code']),
                ],
                'amount' => $amount,
                'share_count' => $openShares->count(),
                'pending_transfer' => $pendingTransfer,
                'can_transfer' => $amount > 0.001,
            ];
        })
            ->filter()
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['member']['full_name']))
            ->values()
            ->all();
    }

    /**
     * @return array{member: array<string, mixed>, amount: float, entries: list<array<string, mixed>>}
     */
    public function beneficiaryDetail(KamsTreasuryAccount $account, KamsMember $member): array
    {
        abort_unless($account->office_id, 422, __('Commissions are only available on office accounts.'));
        abort_unless((int) $member->company_id === (int) $account->company_id, 422, __('This member does not belong to this account.'));

        $shares = $this->payableQuery((int) $account->company_id, (int) $account->office_id)
            ->where('beneficiary_member_id', $member->id)
            ->whereNull('transfer_entry_id')
            ->with([
                'sale:id,reference,sold_at',
                'product:id,name,sku',
                'office:id,name,code',
            ])
            ->orderByDesc('id')
            ->get();

        $member->loadMissing(['role:id,name,slug', 'office:id,name,code']);

        return [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_code' => $member->member_code,
                'photo_url' => $member->photo_url,
                'role' => $member->role ? [
                    'id' => $member->role->id,
                    'name' => $member->role->name,
                    'slug' => $member->role->slug,
                ] : null,
                'office' => $member->office?->only(['id', 'name', 'code']),
            ],
            'amount' => round((float) $shares->sum('amount'), 2),
            'pending_transfer' => $shares->contains(fn (KamsRevenueShare $share) => $share->transfer_entry_id !== null),
            'entries' => $shares->map(fn (KamsRevenueShare $share) => [
                'id' => $share->id,
                'rule_code' => $share->rule_code,
                'role_slug' => $share->role_slug,
                'amount' => round((float) $share->amount, 2),
                'base_amount' => round((float) $share->base_amount, 2),
                'percent' => round((float) $share->percent, 4),
                'formula' => $share->formula,
                'status' => $share->status,
                'transfer_entry_id' => $share->transfer_entry_id,
                'occurred_on' => $share->sale?->sold_at?->toDateString()
                    ?: $share->created_at?->toDateString(),
                'sale' => $share->sale ? [
                    'id' => $share->sale->id,
                    'reference' => $share->sale->reference,
                ] : null,
                'product' => $share->product ? [
                    'id' => $share->product->id,
                    'name' => $share->product->name,
                    'sku' => $share->product->sku,
                ] : null,
                'office' => $share->office?->only(['id', 'name', 'code']),
            ])->values()->all(),
        ];
    }

    /**
     * Create a transfer for all or part of the beneficiary's payable commissions.
     * The office balance drops when the beneficiary accepts the credit in My account.
     */
    public function transfer(KamsTreasuryAccount $account, KamsMember $member, int $userId, float $amount, ?string $notes = null): KamsTreasuryEntry
    {
        abort_unless($account->office_id, 422, __('Commissions are only available on office accounts.'));
        abort_unless((int) $member->company_id === (int) $account->company_id, 422, __('This member does not belong to this account.'));
        abort_unless($amount > 0, 422, __('The amount must be greater than zero.'));

        return DB::transaction(function () use ($account, $member, $userId, $amount, $notes) {
            $shares = $this->payableQuery((int) $account->company_id, (int) $account->office_id)
                ->where('beneficiary_member_id', $member->id)
                ->whereNull('transfer_entry_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = round((float) $shares->sum('amount'), 2);
            abort_if($shares->isEmpty() || $available <= 0.001, 422, __('No payable commissions for this beneficiary.'));
            abort_if($amount > $available + 0.001, 422, __('The amount exceeds the available commission.'));

            $amount = round(min($amount, $available), 2);

            $label = __('Office commission transfer');
            $motif = trim(($notes ?: '').($notes ? ' · ' : '').$label);

            $entry = $this->treasury->requestCommissionTransfer(
                $account,
                $member,
                $amount,
                $motif,
                $userId,
                'percentage',
            );

            $remaining = $amount;
            foreach ($shares as $share) {
                if ($remaining <= 0.001) {
                    break;
                }
                $shareAmount = round((float) $share->amount, 2);
                if ($shareAmount <= $remaining + 0.001) {
                    $share->forceFill(['transfer_entry_id' => $entry->id])->save();
                    $remaining = round($remaining - $shareAmount, 2);
                    continue;
                }

                $snapshot = is_array($share->snapshot) ? $share->snapshot : [];
                $part = $share->replicate();
                $part->amount = $remaining;
                $part->transfer_entry_id = $entry->id;
                $part->snapshot = array_merge($snapshot, ['partial_of' => $share->id]);
                $part->save();

                $share->forceFill([
                    'amount' => round($shareAmount - $remaining, 2),
                ])->save();
                $remaining = 0;
            }

            return $entry;
        });
    }

    public function markTransferPaid(KamsTreasuryEntry $entry): void
    {
        KamsRevenueShare::query()
            ->where('transfer_entry_id', $entry->id)
            ->where('status', 'payable')
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
    }

    public function markTransferRejected(KamsTreasuryEntry $entry): void
    {
        $shares = KamsRevenueShare::query()
            ->where('transfer_entry_id', $entry->id)
            ->where('status', 'payable')
            ->get();

        foreach ($shares as $share) {
            $snapshot = is_array($share->snapshot) ? $share->snapshot : [];
            $originId = isset($snapshot['partial_of']) ? (int) $snapshot['partial_of'] : 0;
            $origin = $originId > 0 ? KamsRevenueShare::query()->find($originId) : null;
            if (
                $origin
                && $origin->status === 'payable'
                && $origin->transfer_entry_id === null
                && (int) $origin->beneficiary_member_id === (int) $share->beneficiary_member_id
            ) {
                $origin->forceFill([
                    'amount' => round((float) $origin->amount + (float) $share->amount, 2),
                ])->save();
                $share->delete();
                continue;
            }

            $share->forceFill(['transfer_entry_id' => null])->save();
        }
    }

    /**
     * @param  list<int>  $officeIds
     */
    public function payableTotal(?int $companyId, array $officeIds = []): float
    {
        $query = KamsRevenueShare::query()
            ->where('status', 'payable')
            ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
            ->whereNull('transfer_entry_id')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        if ($officeIds !== []) {
            $query->whereIn('office_id', $officeIds);
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * Payable total including amounts already in a pending transfer (still owed until approved).
     *
     * @param  list<int>  $officeIds
     */
    public function outstandingTotal(?int $companyId, array $officeIds = []): float
    {
        $query = KamsRevenueShare::query()
            ->where('status', 'payable')
            ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        if ($officeIds !== []) {
            $query->whereIn('office_id', $officeIds);
        }

        return round((float) $query->sum('amount'), 2);
    }

    private function payableQuery(int $companyId, int $officeId)
    {
        return KamsRevenueShare::query()
            ->where('company_id', $companyId)
            ->where('office_id', $officeId)
            ->where('status', 'payable')
            ->whereIn('rule_code', RevenueRuleCatalog::heldOfficeCommissionCodes())
            ->where('amount', '>', 0);
    }
}
