<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['code', 'name_en', 'name_ar', 'decimals', 'symbol'];
}
