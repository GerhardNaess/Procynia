<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchProfileCpvCode extends Model
{
    protected $fillable = [
        'watch_profile_id',
        'cpv_code',
        'weight',
    ];

    public function watchProfile(): BelongsTo
    {
        return $this->belongsTo(WatchProfile::class);
    }

    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(CpvCode::class, 'cpv_code', 'code');
    }
}
