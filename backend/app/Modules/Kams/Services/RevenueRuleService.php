<?php

namespace App\Modules\Kams\Services;

use App\Core\Support\AuditLogger;
use App\Modules\Kams\Models\KamsRevenueRule;
use App\Modules\Kams\Models\KamsRevenueRuleHistory;
use App\Modules\Kams\Support\RevenueRuleCatalog;
use Illuminate\Support\Collection;

class RevenueRuleService
{
    /**
     * @return Collection<string, KamsRevenueRule>
     */
    public function activeFor(int $companyId): Collection
    {
        $this->ensureDefaults($companyId);

        return KamsRevenueRule::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now());
            })
            ->orderBy('sort_order')
            ->get()
            ->keyBy('code');
    }

    public function ensureDefaults(int $companyId): void
    {
        foreach (RevenueRuleCatalog::defaults() as $row) {
            KamsRevenueRule::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $row['code']],
                $row
            );
        }
    }

    /**
     * @return list<KamsRevenueRule>
     */
    public function listFor(int $companyId): array
    {
        $this->ensureDefaults($companyId);

        return KamsRevenueRule::query()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(KamsRevenueRule $rule, array $data, ?int $userId): KamsRevenueRule
    {
        $before = $rule->only(['percent', 'divisor', 'is_active', 'label', 'effective_from', 'effective_to']);
        $rule->fill($data);
        $rule->updated_by = $userId;
        $rule->save();
        $after = $rule->only(['percent', 'divisor', 'is_active', 'label', 'effective_from', 'effective_to']);
        if ($before !== $after) {
            KamsRevenueRuleHistory::query()->create([
                'company_id' => $rule->company_id,
                'rule_id' => $rule->id,
                'code' => $rule->code,
                'changed_by' => $userId,
                'before' => $before,
                'after' => $after,
            ]);
            AuditLogger::record('update', $rule, $before, $after);
        }

        return $rule->fresh();
    }
}
