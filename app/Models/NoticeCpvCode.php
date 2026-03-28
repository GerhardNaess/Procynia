<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticeCpvCode extends Model
{
    protected $fillable = [
        'notice_id',
        'cpv_code',
        'cpv_description_en',
        'cpv_description_no',
    ];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(CpvCode::class, 'cpv_code', 'code');
    }
}
