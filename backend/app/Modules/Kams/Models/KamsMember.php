<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'office_id', 'candidate_id', 'user_id', 'member_code', 'first_name', 'last_name', 'full_name', 'gender', 'birth_date', 'phone', 'email',
    'login_password', 'photo_path', 'address', 'guardian_name', 'guardian_phone', 'trainer_name', 'trainer_user_id', 'manager_member_id', 'assistant_manager_member_id', 'manager_name', 'team_name',
    'rank', 'role_id', 'origin', 'status', 'joined_on', 'migrated_at', 'first_week_completed',
    'first_week_completed_at', 'documents', 'notes', 'fixed_salary',
])]
#[Hidden(['login_password'])]
class KamsMember extends Model
{
    use SoftDeletes;

    protected $appends = ['photo_url', 'account'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date:Y-m-d',
            'birth_date' => 'date:Y-m-d',
            'migrated_at' => 'datetime',
            'first_week_completed' => 'boolean',
            'first_week_completed_at' => 'datetime',
            'documents' => 'array',
            'login_password' => 'encrypted',
            'fixed_salary' => 'float',
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

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_user_id');
    }

    public function managerMember(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_member_id');
    }

    public function assistantManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'assistant_manager_member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function account(): Attribute
    {
        return Attribute::get(function (): array {
            $user = $this->relationLoaded('user') ? $this->user : ($this->user_id ? $this->user()->first() : null);

            return [
                'email' => $user?->email,
                'phone' => $user?->phone ?? $this->phone,
                'password' => $this->login_password,
                'is_active' => (bool) ($user?->is_active),
            ];
        });
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(KamsCandidate::class, 'candidate_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(KamsMemberHistory::class, 'member_id')->latest('changed_at');
    }

    public function openingPositions(): HasMany
    {
        return $this->hasMany(KamsMemberOpeningPosition::class, 'member_id');
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(KamsSavingsAccount::class, 'member_id');
    }

    public function communications(): MorphMany
    {
        return $this->morphMany(KamsCommunication::class, 'communicable')->latest();
    }

    public function isMigrated(): bool
    {
        return $this->origin === 'migrated';
    }
}
