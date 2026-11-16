<?php

namespace App\Models;

use App\Enums\NumberFormat;
use App\Enums\Theme;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Theme|null $theme
 * @property string $timezone
 * @property NumberFormat $number_format
 * @property string|null $avatar_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Company> $companies
 * @property-read Collection<int, Workspace> $ownedWorkspaces
 * @property-read Collection<int, TrustedDevice> $trustedDevices
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'number_format'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_path'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /** @var list<string> */
    protected $appends = ['avatar_url'];

    /** Database defaults, so a fresh model reads the same as a stored one. */
    protected $attributes = [
        'timezone' => 'Asia/Kolkata',
        'number_format' => 'indian',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'theme' => Theme::class,
            'number_format' => NumberFormat::class,
        ];
    }

    /** @return BelongsToMany<Company, $this, Membership, 'membership'> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'last_active_at'])
            ->withTimestamps()
            ->orderBy('companies.name');
    }

    /** @return HasMany<Workspace, $this> */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /** @return HasMany<TrustedDevice, $this> */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function belongsToCompany(Company $company): bool
    {
        return $this->companies()->whereKey($company->id)->exists();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path === null ? null : Storage::disk('public')->url($this->avatar_path);
    }
}
