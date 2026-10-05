<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'office_id', 'candidate_id', 'observation_date', 'attended',
    'supervisor_name', 'supervisor_member_id', 'sponsor_member_id', 'evaluation', 'notes', 'decision', 'rejection_reason', 'recorded_by', 'recorded_at',
])]
class KamsObservationDay extends Model
{
    protected function casts(): array
    {
        return [
            'observation_date' => 'date:Y-m-d',
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

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'supervisor_member_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'sponsor_member_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
