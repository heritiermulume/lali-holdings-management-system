<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsDailySettlement;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockReturnItem;
use App\Modules\Kams\Models\KamsStockSale;
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
            'amount' => round($amount, 2),
            'source_type' => $source::class,
            'source_id' => $source->id,
            'occurred_on' => $date,
            'notes' => $notes,
        ]);
        $account->balance = round((float) KamsSavingsEntry::query()->where('account_id', $account->id)->sum('amount'), 2);
        $account->save();
    }
}
