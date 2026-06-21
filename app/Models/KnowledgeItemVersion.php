<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItemVersion extends Model
{
    public const APPROVAL_STATUS_PENDING_REVIEW = 'pending_review';
    public const APPROVAL_STATUS_APPROVED = 'approved';
    public const APPROVAL_STATUS_REJECTED = 'rejected';
    public const APPROVAL_STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'knowledge_item_id',
        'customer_id',
        'version_no',
        'is_current',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'extracted_text',
        'extraction_status',
        'extraction_error',
        'uploaded_by_user_id',
        'uploaded_at',
        'file_hash_sha256',
        'approval_status',
        'submitted_for_review_at',
        'submitted_for_review_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'file_size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Returns the user who submitted this version for review. */
    public function submittedForReviewBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_for_review_by_user_id');
    }

    /** Returns the user who approved this version. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** Returns the user who rejected this version. */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeItemChunk::class, 'knowledge_item_version_id');
    }

    /** Returns true when this version is awaiting a reviewer decision. */
    public function isPendingReview(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING_REVIEW;
    }

    /** Returns true when this version has been approved. */
    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_APPROVED;
    }

    /** Returns true when this version has been rejected. */
    public function isRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_REJECTED;
    }

    /** Returns true when this version has been superseded by a later approved version. */
    public function isSuperseded(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_SUPERSEDED;
    }
}
