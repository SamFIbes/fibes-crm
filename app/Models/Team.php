<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    protected $fillable = [
        'key',
        'name_en',
        'name_ar',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }
}
