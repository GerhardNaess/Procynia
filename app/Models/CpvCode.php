<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CpvCode extends Model
{
    protected $table = 'cpv_codes';

    protected $fillable = [
        'code',
        'description_en',
        'description_no',
    ];

    public function noticeCpvCodes(): HasMany
    {
        return $this->hasMany(NoticeCpvCode::class, 'cpv_code', 'code');
    }

    public function watchProfileCpvCodes(): HasMany
    {
        return $this->hasMany(WatchProfileCpvCode::class, 'cpv_code', 'code');
    }
}
