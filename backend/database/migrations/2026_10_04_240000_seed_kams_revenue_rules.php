<?php

use App\Models\Company;
use App\Modules\Kams\Services\RevenueRuleService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $service = app(RevenueRuleService::class);
        Company::query()
            ->whereHas('module', fn ($q) => $q->where('slug', 'kams'))
            ->pluck('id')
            ->each(fn ($id) => $service->seedDefaults((int) $id));
    }

    public function down(): void
    {
        // Keep seeded rules; they may have been edited.
    }
};
