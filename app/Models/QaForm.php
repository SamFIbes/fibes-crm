<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QaForm extends Model
{
    protected $fillable = ['ulid', 'key', 'version', 'name_en', 'name_ar', 'criteria', 'is_current', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QaForm $form): void {
            $form->ulid ??= (string) Str::ulid();
        });
    }
}
