<?php

use App\Modules\Kams\Services\SavingsLedger;
use App\Modules\Kams\Services\TreasuryLedger;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SavingsLedger::class)->recalculateBalanceSnapshots();
        app(TreasuryLedger::class)->recalculateBalanceSnapshots();
    }

    public function down(): void
    {
    }
};
