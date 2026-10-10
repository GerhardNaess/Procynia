<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The management's own assessment of one section: a judgement (three levels), an optional comment,
 * and for the manual sections the text that is the basis itself (notes). Frozen with the review.
 *
 * The judgement and comment of a section built from another module are shown only to someone who
 * may read that section's basis (ManagementReviewAccessService::sectionGate()).
 */
class ManagementReviewSection extends Model
{
    protected $fillable = [
        'customer_id',
        'management_review_id',
        'section_key',
        'judgement',
        'comment',
        'notes',
        'updated_by',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
