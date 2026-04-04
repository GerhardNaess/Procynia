<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeBusinessReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'saved_notice_id',
        'business_review_at',
    ];

    protected $casts = [
        'business_review_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }
}
