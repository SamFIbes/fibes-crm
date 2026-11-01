<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'key',
        'module',
        'label_en',
        'label_ar',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')->withPivot('scope');
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->label_ar : $this->label_en;
    }
}
