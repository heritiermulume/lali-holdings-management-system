<?php

use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Services\SavingsLedger;
use App\Modules\Kams\Services\TreasuryLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_treasury_entries', 'related_entry_id')) {
                $table->foreignId('related_entry_id')
                    ->nullable()
                    ->after('member_id')
                    ->constrained('kams_treasury_entries')
                    ->nullOnDelete();
            }
        });

        $outs = DB::table('kams_treasury_entries')
            ->where('type', 'transfer')
            ->where('amount', '<', 0)
            ->whereNull('related_entry_id')
            ->orderBy('id')
            ->get();

        foreach ($outs as $out) {
            $in = DB::table('kams_treasury_entries')
                ->where('type', 'transfer')
                ->where('company_id', $out->company_id)
                ->where('amount', abs((float) $out->amount))
                ->whereNull('related_entry_id')
                ->where('id', '!=', $out->id)
                ->where('created_at', $out->created_at)
                ->orderBy('id')
                ->first();
            if (! $in) {
                continue;
            }
            DB::table('kams_treasury_entries')->where('id', $out->id)->update(['related_entry_id' => $in->id]);
            DB::table('kams_treasury_entries')->where('id', $in->id)->update(['related_entry_id' => $out->id]);
        }

        $treasury = app(TreasuryLedger::class);
        KamsTreasuryAccount::query()
            ->orderByRaw('office_id is null asc')
            ->get()
            ->each(fn (KamsTreasuryAccount $account) => $treasury->refreshBalance($account));

        $savings = app(SavingsLedger::class);
        KamsSavingsAccount::query()
            ->get()
            ->each(fn (KamsSavingsAccount $account) => $savings->refreshBalance($account));
    }

    public function down(): void
    {
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (Schema::hasColumn('kams_treasury_entries', 'related_entry_id')) {
                $table->dropConstrainedForeignId('related_entry_id');
            }
        });
    }
};
