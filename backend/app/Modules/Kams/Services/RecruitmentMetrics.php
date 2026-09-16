<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsInterview;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsMemberImportRow;
use App\Modules\Kams\Models\KamsObservationDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecruitmentMetrics
{
    /**
     * Period-grouped recruitment statistics for the Recap page.
     *
     * @return array<string, mixed>
     */
    public function recap(string $groupBy = 'week', ?int $companyId = null, ?int $officeId = null): array
    {
        $groupBy = in_array($groupBy, ['day', 'week', 'month'], true) ? $groupBy : 'week';
        $end = now()->endOfDay();
        [$start, $series] = match ($groupBy) {
            'day' => $this->buildBuckets($end, 14, 'day'),
            'week' => $this->buildBuckets($end, 8, 'week'),
            'month' => $this->buildBuckets($end, 12, 'month'),
        };

        $candidates = $this->scoped(KamsCandidate::query(), $companyId, $officeId);
        $interviews = $this->scoped(KamsInterview::query(), $companyId, $officeId);
        $observations = $this->scoped(KamsObservationDay::query(), $companyId, $officeId);
        $members = $this->scoped(KamsMember::query(), $companyId, $officeId);

        $applied = (clone $candidates)
            ->whereBetween('applied_at', [$start, $end])
            ->get(['applied_at', 'status', 'source']);

        $interviewRows = (clone $interviews)
            ->whereBetween('interview_date', [$start->toDateString(), $end->toDateString()])
            ->get(['interview_date', 'result', 'attended']);

        $observationRows = (clone $observations)
            ->whereBetween('observation_date', [$start->toDateString(), $end->toDateString()])
            ->get(['observation_date', 'decision', 'attended']);

        $newMembers = (clone $members)
            ->where('origin', 'new_recruit')
            ->whereBetween('joined_on', [$start->toDateString(), $end->toDateString()])
            ->get(['joined_on', 'status']);

        foreach ($applied as $row) {
            $bucket = $this->bucketFor($row->applied_at, $groupBy, $series);
            if ($bucket === null) {
                continue;
            }
            $series[$bucket]['applications']++;
        }

        foreach ($interviewRows as $row) {
            $bucket = $this->bucketFor($row->interview_date, $groupBy, $series);
            if ($bucket === null) {
                continue;
            }
            $series[$bucket]['interviews']++;
            if ($row->result === 'rejected') {
                $series[$bucket]['rejected']++;
            }
            if ($row->result === 'abandoned') {
                $series[$bucket]['abandoned']++;
            }
            if ($row->result === 'on_hold') {
                $series[$bucket]['on_hold']++;
            }
        }

        foreach ($observationRows as $row) {
            $bucket = $this->bucketFor($row->observation_date, $groupBy, $series);
            if ($bucket === null) {
                continue;
            }
            $series[$bucket]['observations']++;
            if ($row->decision === 'approved') {
                $series[$bucket]['approved']++;
            }
            if ($row->decision === 'rejected') {
                $series[$bucket]['rejected']++;
            }
            if ($row->decision === 'on_hold') {
                $series[$bucket]['on_hold']++;
            }
        }

        foreach ($newMembers as $row) {
            $bucket = $this->bucketFor($row->joined_on, $groupBy, $series);
            if ($bucket === null) {
                continue;
            }
            $series[$bucket]['new_members']++;
        }

        $seriesList = array_values($series);
        $applications = (int) array_sum(array_column($seriesList, 'applications'));
        $approved = (int) array_sum(array_column($seriesList, 'approved'));

        return [
            'group_by' => $groupBy,
            'range' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'totals' => [
                'applications' => $applications,
                'interviews' => (int) array_sum(array_column($seriesList, 'interviews')),
                'observations' => (int) array_sum(array_column($seriesList, 'observations')),
                'approved' => $approved,
                'rejected' => (int) array_sum(array_column($seriesList, 'rejected')),
                'on_hold' => (int) array_sum(array_column($seriesList, 'on_hold')),
                'abandoned' => (int) array_sum(array_column($seriesList, 'abandoned')),
                'new_members' => (int) array_sum(array_column($seriesList, 'new_members')),
                'conversion_rate' => $applications > 0 ? round(($approved / $applications) * 100, 1) : 0,
                'interview_attendance' => $this->attendanceRate($interviewRows),
                'observation_attendance' => $this->attendanceRate($observationRows),
            ],
            'series' => $seriesList,
            'pipeline' => $this->countBy((clone $candidates)->whereNotIn('status', ['registered']), 'status'),
            'interview_results' => $this->countBy($interviewRows, 'result'),
            'observation_decisions' => $this->countBy($observationRows, 'decision'),
            'sources' => $this->countBy($applied, 'source'),
            'members' => [
                'total' => (clone $members)->count(),
                'new_recruit' => (clone $members)->where('origin', 'new_recruit')->count(),
                'migrated' => (clone $members)->where('origin', 'migrated')->count(),
                'active' => (clone $members)->where('status', 'active')->count(),
                'verified' => (clone $members)->where('status', 'verified')->count(),
                'pending_verification' => (clone $members)->where('status', 'pending_verification')->count(),
                'inactive' => (clone $members)->where('status', 'inactive')->count(),
            ],
        ];
    }

    /**
     * @return array{recruitment: array<string, float|int>, migration: array<string, int>}
     */
    public function summarize(?int $companyId = null, ?int $officeId = null): array
    {
        $candidates = KamsCandidate::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));
        $members = KamsMember::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));

        $applications = (clone $candidates)->count();
        $approved = (clone $members)->where('origin', 'new_recruit')->whereIn('status', ['active', 'verified'])->count();
        $migrated = (clone $members)->where('origin', 'migrated');

        return [
            'recruitment' => [
                'applications' => $applications,
                'under_review' => (clone $candidates)->where('status', 'under_review')->count(),
                'shortlisted' => (clone $candidates)->where('status', 'shortlisted')->count(),
                'interviews' => KamsInterview::query()->whereIn('candidate_id', (clone $candidates)->select('id'))->count(),
                'observations' => KamsObservationDay::query()->whereIn('candidate_id', (clone $candidates)->select('id'))->count(),
                'approved' => $approved,
                'rejected' => (clone $candidates)->where('status', 'rejected')->count(),
                'abandoned' => (clone $candidates)->where('status', 'abandoned')->count(),
                'on_hold' => (clone $candidates)->where('status', 'on_hold')->count(),
                'conversion_rate' => $applications > 0 ? round(($approved / $applications) * 100, 1) : 0,
            ],
            'migration' => [
                'imported' => (clone $migrated)->count(),
                'pending_verification' => (clone $migrated)->where('status', 'pending_verification')->count(),
                'verified' => (clone $migrated)->where('status', 'verified')->count(),
                'active' => (clone $migrated)->where('status', 'active')->count(),
                'issues' => KamsMemberImportRow::query()
                    ->whereHas('member', function ($q) use ($companyId, $officeId) {
                        $q->where('origin', 'migrated')
                            ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                            ->when($officeId, fn ($inner) => $inner->where('office_id', $officeId));
                    })
                    ->whereNotNull('issues')
                    ->get()
                    ->filter(fn ($row) => filled($row->issues))
                    ->count(),
            ],
        ];
    }

    private function scoped(Builder $query, ?int $companyId, ?int $officeId): Builder
    {
        return $query
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));
    }

    /**
     * @return array{0: Carbon, 1: array<string, array<string, mixed>>}
     */
    private function buildBuckets(Carbon $end, int $count, string $groupBy): array
    {
        $series = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            [$from, $to] = match ($groupBy) {
                'day' => [
                    $end->copy()->subDays($i)->startOfDay(),
                    $end->copy()->subDays($i)->endOfDay(),
                ],
                'week' => [
                    $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($i),
                    $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($i)->endOfWeek(Carbon::SUNDAY),
                ],
                default => [
                    $end->copy()->startOfMonth()->subMonths($i),
                    $end->copy()->startOfMonth()->subMonths($i)->endOfMonth(),
                ],
            };

            if ($to->gt($end)) {
                $to = $end->copy();
            }

            $key = $this->bucketKey($from, $groupBy);
            $series[$key] = [
                'key' => $key,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'applications' => 0,
                'interviews' => 0,
                'observations' => 0,
                'approved' => 0,
                'rejected' => 0,
                'on_hold' => 0,
                'abandoned' => 0,
                'new_members' => 0,
            ];
        }

        $start = Carbon::parse(reset($series)['from'])->startOfDay();

        return [$start, $series];
    }

    /**
     * @param  array<string, array<string, mixed>>  $series
     */
    private function bucketFor(mixed $date, string $groupBy, array $series): ?string
    {
        if (! $date) {
            return null;
        }

        $key = $this->bucketKey(Carbon::parse($date), $groupBy);

        return array_key_exists($key, $series) ? $key : null;
    }

    private function bucketKey(Carbon $date, string $groupBy): string
    {
        return match ($groupBy) {
            'day' => $date->toDateString(),
            'week' => $date->copy()->startOfWeek(Carbon::MONDAY)->format('o-\WW'),
            default => $date->format('Y-m'),
        };
    }

    /**
     * @return list<array{key: string, count: int}>
     */
    private function countBy(Collection|Builder $rows, string $column): array
    {
        $items = $rows instanceof Builder ? $rows->get([$column]) : $rows;

        return $items
            ->groupBy(fn ($row) => (string) ($row->{$column} ?: 'other'))
            ->map(fn (Collection $group, string $key) => ['key' => $key, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function attendanceRate(Collection $rows): float
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        return round(($rows->where('attended', true)->count() / $rows->count()) * 100, 1);
    }
}
