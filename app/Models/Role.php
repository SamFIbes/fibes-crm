<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'key',
        'name_en',
        'name_ar',
        'is_builtin',
        'is_locked',
        'home_route',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withPivot('scope');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role');
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }
}
