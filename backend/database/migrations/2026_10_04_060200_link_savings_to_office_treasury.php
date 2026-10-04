<?php

use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Services\TreasuryLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_treasury_entries', 'savings_entry_id')) {
                $table->foreignId('savings_entry_id')
                    ->nullable()
                    ->after('member_id')
                    ->constrained('kams_savings_entries')
                    ->nullOnDelete();
            }
        });

        $ledger = app(TreasuryLedger::class);
        KamsSavingsEntry::query()
            ->where('status', 'posted')
            ->where(function ($query) {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', \App\Modules\Kams\Models\KamsTreasuryEntry::class);
            })
            ->orderBy('id')
            ->each(function (KamsSavingsEntry $entry) use ($ledger) {
                $ledger->mirrorSavingsToOffice($entry);
            });
    }

    public function down(): void
    {
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (Schema::hasColumn('kams_treasury_entries', 'savings_entry_id')) {
                $table->dropConstrainedForeignId('savings_entry_id');
            }
        });
    }
};
