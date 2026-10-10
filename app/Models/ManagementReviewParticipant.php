<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who took part. A person in Procynia (user_id, name kept as it was) or someone outside it (name
 * only). Frozen with the review.
 */
class ManagementReviewParticipant extends Model
{
    protected $fillable = [
        'customer_id',
        'management_review_id',
        'user_id',
        'name',
        'role_label',
        'position',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
