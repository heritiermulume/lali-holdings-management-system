<?php

use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Support\RevenueRuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kams_savings_entries') || ! Schema::hasTable('kams_savings_accounts')) {
            return;
        }

        $legacyKinds = ['manager', 'secretary', 'divisionnaire'];
        $entries = KamsSavingsEntry::query()
            ->whereIn('kind', $legacyKinds)
            ->whereIn('type', ['revenue_share', 'salary', 'settlement'])
            ->orderBy('id')
            ->get();

        $touchedAccounts = [];

        foreach ($entries as $entry) {
            $member = KamsMember::query()->with('role:id,slug')->find($entry->member_id);
            if (! $member || ! RevenueRuleCatalog::usesSplitCompensationAccounts($member->role?->slug)) {
                continue;
            }

            $targetKind = 'salary';
            if ($entry->type === 'revenue_share'
                && $entry->source_type === KamsRevenueShare::class
                && $entry->source_id
            ) {
                $share = KamsRevenueShare::query()->find($entry->source_id);
                if ($share) {
                    $targetKind = RevenueRuleCatalog::compensationAccountKind(
                        (string) $share->rule_code,
                        $member->role?->slug,
                        $share->office_id ? (int) $share->office_id : null,
                        $member->office_id ? (int) $member->office_id : null,
                    );
                }
            }

            if ($entry->kind === $targetKind) {
                continue;
            }

            $account = KamsSavingsAccount::query()->firstOrCreate(
                ['member_id' => $member->id, 'kind' => $targetKind],
                ['company_id' => $member->company_id, 'balance' => 0]
            );

            $previousAccountId = (int) $entry->account_id;
            $entry->forceFill([
                'account_id' => $account->id,
                'kind' => $targetKind,
            ])->save();

            $touchedAccounts[$previousAccountId] = true;
            $touchedAccounts[(int) $account->id] = true;
        }

        foreach (array_keys($touchedAccounts) as $accountId) {
            if (! $accountId) {
                continue;
            }
            $balance = round((float) KamsSavingsEntry::query()
                ->where('account_id', $accountId)
                ->where('status', 'posted')
                ->sum('amount'), 2);
            DB::table('kams_savings_accounts')->where('id', $accountId)->update(['balance' => $balance]);
        }
    }

    public function down(): void
    {
        // Irreversible data move: salary/percentage keep their balances.
    }
};
