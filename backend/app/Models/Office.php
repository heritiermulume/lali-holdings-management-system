<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'parent_office_id', 'creator_user_id', 'manager_user_id', 'recorded_by_user_id',
    'country_id', 'province_id', 'city_id', 'commune_id', 'neighborhood_id',
    'name', 'code', 'type', 'address', 'phone', 'is_active',
])]
class Office extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Office $office): void {
            if ($office->neighborhood_id && ! $office->commune_id) {
                $office->commune_id = Neighborhood::query()->whereKey($office->neighborhood_id)->value('commune_id');
            }

            if ($office->commune_id && ! $office->city_id) {
                $office->city_id = Commune::query()->whereKey($office->commune_id)->value('city_id');
            }

            if ($office->city_id) {
                $city = City::query()->find($office->city_id);
                if ($city) {
                    $office->country_id ??= $city->country_id;
                    $office->province_id ??= $city->province_id;
                }
            }
        });
    }

    /**
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    public static function descendantIdsFor(array $rootIds): array
    {
        $rootIds = array_values(array_unique(array_filter(array_map('intval', $rootIds))));
        if ($rootIds === []) {
            return [];
        }

        $children = [];
        foreach (static::query()->get(['id', 'parent_office_id']) as $row) {
            if ($row->parent_office_id) {
                $children[(int) $row->parent_office_id][] = (int) $row->id;
            }
        }

        $found = [];
        $queue = $rootIds;
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($children[$id] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_keys($found);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_office_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_office_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }
}
