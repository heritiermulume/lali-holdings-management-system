<?php

use App\Models\Company;
use App\Models\Office;
use App\Modules\Kams\Services\TreasuryLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kams_treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('kams_treasury_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('kams_treasury_accounts')->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 20)->default('posted');
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });

        $ledger = app(TreasuryLedger::class);
        Company::query()->whereHas('module', fn ($q) => $q->where('slug', 'kams'))->each(function (Company $company) use ($ledger) {
            $ledger->ensureForCompany((int) $company->id);
        });
        Office::query()
            ->whereHas('company.module', fn ($q) => $q->where('slug', 'kams'))
            ->where('is_active', true)
            ->each(fn (Office $office) => app(TreasuryLedger::class)->ensureOffice($office));
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_treasury_entries');
        Schema::dropIfExists('kams_treasury_accounts');
    }
};
