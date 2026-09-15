<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsMember;

class KamsIdentityService
{
    public function nextCandidateId(int $companyId): string
    {
        $year = now()->year;
        $count = KamsCandidate::withTrashed()->where('company_id', $companyId)->whereYear('created_at', $year)->count() + 1;

        return sprintf('KA-C-%d-%06d', $year, $count);
    }

    public function nextMemberCode(int $companyId): string
    {
        $year = now()->year;
        $count = KamsMember::withTrashed()->where('company_id', $companyId)->whereYear('created_at', $year)->count() + 1;

        return sprintf('KA-M-%d-%06d', $year, $count);
    }
}
