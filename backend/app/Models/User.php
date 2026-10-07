<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use App\Models\Concerns\HasLhmsPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'first_name',
    'last_name',
    'gender',
    'name',
    'email',
    'phone',
    'avatar_path',
    'locale',
    'timezone',
    'is_active',
    'primary_company_id',
    'primary_office_id',
    'password',
    'password_changed_at',
    'last_login_at',
])]
#[Hidden(['password', 'login_password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasLhmsPermissions, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'login_password' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->name = trim($user->first_name.' '.$user->last_name);
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
            }
        });

        static::creating(function (User $user): void {
            if (! $user->password_changed_at) {
                $user->password_changed_at = now();
            }
        });
    }

    public function primaryCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'primary_company_id');
    }

    public function primaryOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'primary_office_id');
    }

    public function notifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->send(new PasswordResetMail($this, (string) $token));
    }
}
