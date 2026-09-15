<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsInterview;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsMemberImportRow;
use App\Modules\Kams\Models\KamsObservationDay;

class RecruitmentMetrics
{
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
}
