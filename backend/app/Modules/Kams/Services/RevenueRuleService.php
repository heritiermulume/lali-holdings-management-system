<?php

namespace App\Modules\Kams\Services;

use App\Models\Company;
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

    /**
     * Ensure catalog rules exist for a company (idempotent; never overwrites percents).
     */
    public function seedDefaults(int $companyId): void
    {
        foreach (RevenueRuleCatalog::defaults() as $row) {
            KamsRevenueRule::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $row['code']],
                array_merge($row, ['is_active' => true])
            );
        }
    }

    /**
     * Ensure defaults for every KAMS company (same permanence as roles/settings).
     */
    public function ensureAll(): void
    {
        Company::query()
            ->whereHas('module', fn ($q) => $q->where('slug', 'kams'))
            ->pluck('id')
            ->each(fn ($id) => $this->seedDefaults((int) $id));
    }

    /**
     * Rules editable in finance settings (excludes product-level manager_sale).
     *
     * @return list<KamsRevenueRule>
     */
    public function listFor(int $companyId): array
    {
        $this->seedDefaults($companyId);

        return KamsRevenueRule::query()
            ->where('company_id', $companyId)
            ->where('code', '!=', RevenueRuleCatalog::MANAGER_SALE)
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
        }

        return $rule->fresh();
    }
}
