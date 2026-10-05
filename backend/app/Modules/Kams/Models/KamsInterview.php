<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'office_id', 'candidate_id', 'interview_date', 'interview_starts_at', 'interview_ends_at',
    'observation_starts_at', 'observation_ends_at', 'interviewer_name', 'interviewer_user_id',
    'attended', 'notes', 'abandonment_reason', 'rejection_reason', 'result', 'recorded_by', 'recorded_at',
])]
class KamsInterview extends Model
{
    protected function casts(): array
    {
        return [
            'interview_date' => 'date:Y-m-d',
            'interview_starts_at' => 'datetime',
            'interview_ends_at' => 'datetime',
            'observation_starts_at' => 'datetime',
            'observation_ends_at' => 'datetime',
            'attended' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(KamsCandidate::class, 'candidate_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
