<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'phone',
        'extension',
        'last_latitude',
        'last_longitude',
        'last_located_at',
        'is_active',
        'role',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'is_active' => 'boolean',
            'force_two_factor' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function hasRole(string $key): bool
    {
        if ($this->role === $key) {
            return true;
        }

        $roles = $this->relationLoaded('roles') ? $this->roles : $this->roles()->get();

        return $roles->contains(fn (Role $role) => $role->key === $key);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /**
     * @return BelongsToMany<EscalationTier, $this>
     */
    /**
     * @return HasMany<UserSkill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(UserSkill::class);
    }

    public function escalationTiers(): BelongsToMany
    {
        return $this->belongsToMany(EscalationTier::class, 'escalation_tier_user')
            ->withPivot('on_duty')
            ->withTimestamps();
    }

    public function requiresTwoFactor(): bool
    {
        if ($this->force_two_factor) {
            return true;
        }

        $required = config('fibes.two_factor.required_roles', []);

        if (! is_array($required)) {
            return false;
        }

        foreach ($required as $key) {
            if (is_string($key) && $this->hasRole($key)) {
                return true;
            }
        }

        return false;
    }

    public function mustEnrollTwoFactor(): bool
    {
        if (! $this->requiresTwoFactor() || $this->hasTwoFactorEnabled() || $this->created_at === null) {
            return false;
        }

        return $this->created_at->lte(now()->subDays($this->twoFactorGraceDays()));
    }

    public function twoFactorGraceDaysLeft(): ?int
    {
        if (! $this->requiresTwoFactor() || $this->hasTwoFactorEnabled() || $this->created_at === null) {
            return null;
        }

        $ends = $this->created_at->copy()->addDays($this->twoFactorGraceDays());
        $seconds = $ends->getTimestamp() - now()->getTimestamp();

        return max(0, (int) ceil($seconds / 86400));
    }

    public function replacePassword(string $password): void
    {
        $this->forceFill([
            'password' => $password,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
    }

    private function twoFactorGraceDays(): int
    {
        return (int) config('fibes.two_factor.grace_days', 7);
    }

    public function markSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->save();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
