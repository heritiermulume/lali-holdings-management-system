<?php

namespace App\Modules\Kams\Models;

use App\Core\Support\RoleCatalog;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kams\Services\PeoplePhotoStorage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'office_id', 'candidate_id', 'user_id', 'member_code', 'first_name', 'last_name', 'full_name', 'gender', 'birth_date', 'phone', 'email',
    'login_password', 'photo_path', 'address', 'guardian_name', 'guardian_phone', 'trainer_name', 'trainer_user_id', 'coach_member_id', 'coach_name', 'manager_member_id', 'assistant_manager_member_id', 'manager_name', 'team_name',
    'rank', 'role_id', 'origin', 'status', 'joined_on', 'migrated_at', 'first_week_completed',
    'first_week_completed_at', 'documents', 'notes', 'fixed_salary',
])]
#[Hidden(['login_password'])]
class KamsMember extends Model
{
    use SoftDeletes;

    protected $appends = ['photo_url', 'account', 'is_certified', 'can_be_certified'];

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
        return Attribute::get(function (): ?string {
            $storage = app(PeoplePhotoStorage::class);
            if ($url = $storage->url($this->photo_path)) {
                return $url;
            }

            $fallback = $this->relationLoaded('user')
                ? $this->user?->avatar_path
                : ($this->user_id ? $this->user()->value('avatar_path') : null);

            return $storage->urlFromPaths($fallback);
        });
    }

    protected function isCertified(): Attribute
    {
        return Attribute::get(function (): bool {
            $user = $this->relationLoaded('user') ? $this->user : ($this->user_id ? $this->user()->first() : null);

            return (bool) ($user?->is_certified);
        });
    }

    protected function canBeCertified(): Attribute
    {
        return Attribute::get(function (): bool {
            $this->loadMissing('role:id,slug');

            return $this->role !== null
                && in_array($this->role->slug, User::CERTIFIABLE_ROLE_SLUGS, true);
        });
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

    public function coach(): BelongsTo
    {
        return $this->belongsTo(self::class, 'coach_member_id');
    }

    public function coachedMembers(): HasMany
    {
        return $this->hasMany(self::class, 'coach_member_id');
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

    /**
     * Members who can receive stock from an office depot (excludes platform admins).
     */
    public function scopeAssignableForStock(Builder $query): Builder
    {
        $excluded = RoleCatalog::kamsStockAssignmentExcludedSlugs();

        return $query
            ->whereNotNull('office_id')
            ->where(function (Builder $q) use ($excluded) {
                $q->whereDoesntHave('role', fn (Builder $role) => $role->whereIn('slug', $excluded))
                    ->orWhereNull('role_id');
            });
    }

    public function canReceiveStockAssignment(): bool
    {
        if (! $this->office_id) {
            return false;
        }

        $this->loadMissing('role:id,slug');
        $slug = $this->role?->slug;

        return ! $slug || ! in_array($slug, RoleCatalog::kamsStockAssignmentExcludedSlugs(), true);
    }
}
