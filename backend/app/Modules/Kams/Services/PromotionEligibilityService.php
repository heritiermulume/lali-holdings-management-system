<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsStockSaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PromotionEligibilityService
{
    public const CHEF_DAILY_UNITS = 15;

    public const ASSISTANT_MANAGER_DAILY_UNITS = 150;

    public const CONSECUTIVE_DAYS = 3;

    public const ASSISTANT_MANAGER_TEAM_SIZE = 15;

    public const ASSISTANT_MANAGER_CHEF_COACHEES = 5;

    /** @var list<string> */
    private const CHEF_OR_ABOVE = [
        'chef',
        'assistant_manager',
        'manager',
        'secretary',
        'divisionnaire',
        'vice_president',
        'partner',
        'president',
    ];

    /**
     * Counts of members currently eligible for Chef / Assistant Manager promotion.
     *
     * @param  list<int>|int|null  $officeId
     * @return array{
     *   chef: int,
     *   assistant_manager: int,
     *   criteria: array<string, mixed>
     * }
     */
    public function counts(?int $companyId = null, int|array|null $officeId = null): array
    {
        $evaluated = $this->evaluate($companyId);
        $scopedMembers = $this->activeMembers($companyId, $officeId);
        if ($scopedMembers->isEmpty()) {
            return $this->emptyPayload();
        }

        $chefEligible = 0;
        $assistantEligible = 0;
        foreach ($scopedMembers as $member) {
            $id = (int) $member->id;
            if (isset($evaluated['chef'][$id])) {
                $chefEligible++;
            }
            if (isset($evaluated['assistant_manager'][$id])) {
                $assistantEligible++;
            }
        }

        return [
            'chef' => $chefEligible,
            'assistant_manager' => $assistantEligible,
            'criteria' => $evaluated['criteria'],
        ];
    }

    /**
     * Evaluate eligibility for every active member in the company.
     *
     * Eligibility is personal: a member who meets the criteria is marked even
     * if their coach is not eligible / not yet promoted.
     *
     * @return array{
     *   chef: array<int, true>,
     *   assistant_manager: array<int, true>,
     *   any: array<int, true>,
     *   for: array<int, 'chef'|'assistant_manager'>,
     *   criteria: array<string, mixed>
     * }
     */
    public function evaluate(?int $companyId = null): array
    {
        $criteria = [
            'chef' => [
                'daily_units' => self::CHEF_DAILY_UNITS,
                'consecutive_days' => self::CONSECUTIVE_DAYS,
            ],
            'assistant_manager' => [
                'team_size' => self::ASSISTANT_MANAGER_TEAM_SIZE,
                'chef_coachees' => self::ASSISTANT_MANAGER_CHEF_COACHEES,
                'daily_units' => self::ASSISTANT_MANAGER_DAILY_UNITS,
                'consecutive_days' => self::CONSECUTIVE_DAYS,
            ],
        ];

        $companyMembers = $this->activeMembers($companyId, null);
        if ($companyMembers->isEmpty()) {
            return [
                'chef' => [],
                'assistant_manager' => [],
                'any' => [],
                'for' => [],
                'criteria' => $criteria,
            ];
        }

        $companyIds = $companyMembers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $dailySold = $this->dailySoldByMember($companyIds);
        $childrenByCoach = $this->childrenByCoach($companyId);
        $roleById = [];
        foreach ($companyMembers as $member) {
            $roleById[(int) $member->id] = $this->roleSlug($member);
        }

        $chefEligibleIds = [];
        foreach ($companyMembers as $member) {
            $id = (int) $member->id;
            $slug = $roleById[$id] ?? null;
            if (! $this->isBelowChef($slug)) {
                continue;
            }
            if ($this->hasConsecutiveSalesDays($dailySold[$id] ?? [], self::CHEF_DAILY_UNITS)) {
                $chefEligibleIds[$id] = true;
            }
        }

        $assistantEligibleIds = [];
        foreach ($companyMembers as $member) {
            $id = (int) $member->id;
            $slug = $roleById[$id] ?? null;
            if ($slug !== 'chef') {
                continue;
            }

            $teamIds = $this->descendantIds($id, $childrenByCoach);
            $chefProfileCoachees = 0;
            foreach ($childrenByCoach[$id] ?? [] as $directId) {
                if (($roleById[$directId] ?? null) === 'chef') {
                    $chefProfileCoachees++;
                }
            }

            if (
                count($teamIds) >= self::ASSISTANT_MANAGER_TEAM_SIZE
                && $chefProfileCoachees >= self::ASSISTANT_MANAGER_CHEF_COACHEES
                && $this->hasConsecutiveSalesDays($dailySold[$id] ?? [], self::ASSISTANT_MANAGER_DAILY_UNITS)
            ) {
                $assistantEligibleIds[$id] = true;
            }
        }

        $for = [];
        $any = [];
        foreach ($assistantEligibleIds as $id => $_) {
            $for[$id] = 'assistant_manager';
            $any[$id] = true;
        }
        foreach ($chefEligibleIds as $id => $_) {
            if (! isset($for[$id])) {
                $for[$id] = 'chef';
            }
            $any[$id] = true;
        }

        return [
            'chef' => $chefEligibleIds,
            'assistant_manager' => $assistantEligibleIds,
            'any' => $any,
            'for' => $for,
            'criteria' => $criteria,
        ];
    }

    /**
     * @param  array{chef: array<int, true>, assistant_manager: array<int, true>, any: array<int, true>, for: array<int, 'chef'|'assistant_manager'>}  $evaluated
     * @return array{eligible: bool, promotion_for: 'chef'|'assistant_manager'|null}
     */
    public function flagsFor(int $memberId, array $evaluated): array
    {
        $for = $evaluated['for'][$memberId] ?? null;

        return [
            'eligible' => isset($evaluated['any'][$memberId]),
            'promotion_for' => $for,
        ];
    }

    /**
     * @return array{chef: int, assistant_manager: int, criteria: array<string, mixed>}
     */
    private function emptyPayload(): array
    {
        return [
            'chef' => 0,
            'assistant_manager' => 0,
            'criteria' => [
                'chef' => [
                    'daily_units' => self::CHEF_DAILY_UNITS,
                    'consecutive_days' => self::CONSECUTIVE_DAYS,
                ],
                'assistant_manager' => [
                    'team_size' => self::ASSISTANT_MANAGER_TEAM_SIZE,
                    'chef_coachees' => self::ASSISTANT_MANAGER_CHEF_COACHEES,
                    'daily_units' => self::ASSISTANT_MANAGER_DAILY_UNITS,
                    'consecutive_days' => self::CONSECUTIVE_DAYS,
                ],
            ],
        ];
    }

    /**
     * @param  list<int>|int|null  $officeId
     * @return Collection<int, KamsMember>
     */
    private function activeMembers(?int $companyId, int|array|null $officeId): Collection
    {
        $query = KamsMember::query()
            ->with('role:id,name,slug')
            ->whereIn('status', ['active', 'verified', 'pending_verification']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }
        if (is_array($officeId)) {
            $query->whereIn('office_id', $officeId ?: [0]);
        } elseif ($officeId) {
            $query->where('office_id', $officeId);
        }

        return $query->get(['id', 'company_id', 'office_id', 'coach_member_id', 'role_id', 'rank', 'status']);
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, array<string, int>> member_id => [Y-m-d => units]
     */
    private function dailySoldByMember(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $rows = KamsStockSaleItem::query()
            ->join('kams_stock_sales as s', 's.id', '=', 'kams_stock_sale_items.sale_id')
            ->whereIn('s.member_id', $memberIds)
            ->whereNotNull('s.sold_at')
            ->selectRaw('s.member_id as member_id, DATE(s.sold_at) as sold_day, SUM(kams_stock_sale_items.quantity) as units')
            ->groupBy('s.member_id', DB::raw('DATE(s.sold_at)'))
            ->orderBy('sold_day')
            ->get();

        $byMember = [];
        foreach ($rows as $row) {
            $memberId = (int) $row->member_id;
            $day = (string) $row->sold_day;
            $byMember[$memberId][$day] = (int) $row->units;
        }

        return $byMember;
    }

    /**
     * @return array<int, list<int>> coach_member_id => [child ids]
     */
    private function childrenByCoach(?int $companyId): array
    {
        $query = KamsMember::query()
            ->whereNotNull('coach_member_id')
            ->whereIn('status', ['active', 'verified', 'pending_verification']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $map = [];
        foreach ($query->get(['id', 'coach_member_id']) as $row) {
            $coachId = (int) $row->coach_member_id;
            $map[$coachId][] = (int) $row->id;
        }

        return $map;
    }

    /**
     * @param  array<int, list<int>>  $childrenByCoach
     * @return list<int>
     */
    private function descendantIds(int $rootId, array $childrenByCoach): array
    {
        $found = [];
        $stack = $childrenByCoach[$rootId] ?? [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($found[$id])) {
                continue;
            }
            $found[$id] = true;
            foreach ($childrenByCoach[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_keys($found);
    }

    /**
     * @param  array<string, int>  $dailySold  Y-m-d => units
     */
    private function hasConsecutiveSalesDays(array $dailySold, int $minUnits): bool
    {
        if ($dailySold === []) {
            return false;
        }

        $qualifying = [];
        foreach ($dailySold as $day => $units) {
            if ($units >= $minUnits) {
                $qualifying[] = $day;
            }
        }
        sort($qualifying);

        if (count($qualifying) < self::CONSECUTIVE_DAYS) {
            return false;
        }

        $streak = 1;
        for ($i = 1, $len = count($qualifying); $i < $len; $i++) {
            $prev = strtotime($qualifying[$i - 1]);
            $curr = strtotime($qualifying[$i]);
            if ($prev === false || $curr === false) {
                $streak = 1;
                continue;
            }
            $diffDays = (int) (($curr - $prev) / 86400);
            if ($diffDays === 1) {
                $streak++;
                if ($streak >= self::CONSECUTIVE_DAYS) {
                    return true;
                }
            } elseif ($diffDays !== 0) {
                $streak = 1;
            }
        }

        return false;
    }

    private function roleSlug(KamsMember $member): ?string
    {
        $slug = $member->role?->slug;
        if ($slug) {
            return $slug;
        }

        $rank = mb_strtolower(trim((string) $member->rank));
        if ($rank === '') {
            return null;
        }

        return match (true) {
            str_contains($rank, 'divisionnaire') => 'divisionnaire',
            str_contains($rank, 'assistant') => 'assistant_manager',
            str_contains($rank, 'secrétaire') || str_contains($rank, 'secretary') => 'secretary',
            str_contains($rank, 'manager') => 'manager',
            str_contains($rank, 'chef') || str_contains($rank, 'leader') || str_contains($rank, 'cheffe') => 'chef',
            str_contains($rank, 'distribut') || str_contains($rank, 'guy') => 'distributor',
            default => null,
        };
    }

    private function isBelowChef(?string $slug): bool
    {
        return $slug === null || ! in_array($slug, self::CHEF_OR_ABOVE, true);
    }
}
