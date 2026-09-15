<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'office_id', 'member_id', 'sponsor_member_id', 'guardian_member_id', 'candidate_id',
    'first_name', 'last_name', 'full_name',
    'gender', 'birth_date', 'phone', 'email', 'photo_path', 'address', 'guardian_name', 'guardian_phone', 'source', 'sponsor_name',
    'status', 'applied_at', 'documents', 'notes',
])]
class KamsCandidate extends Model
{
    use SoftDeletes;

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'birth_date' => 'date:Y-m-d',
            'documents' => 'array',
        ];
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->photo_path ? '/storage/'.$this->photo_path : null);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'sponsor_member_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'guardian_member_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(KamsInterview::class, 'candidate_id')->latest('interview_date');
    }

    public function latestInterview(): HasOne
    {
        return $this->hasOne(KamsInterview::class, 'candidate_id')->latestOfMany();
    }

    public function observationDays(): HasMany
    {
        return $this->hasMany(KamsObservationDay::class, 'candidate_id')->latest('observation_date');
    }

    public function latestObservationDay(): HasOne
    {
        return $this->hasOne(KamsObservationDay::class, 'candidate_id')->latestOfMany();
    }

    public function communications(): MorphMany
    {
        return $this->morphMany(KamsCommunication::class, 'communicable')->latest();
    }
}
