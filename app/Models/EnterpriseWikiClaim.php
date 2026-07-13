<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseWikiClaim extends Model
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_UNCERTAIN = 'uncertain';

    public const CONFIDENCES = [
        self::CONFIDENCE_HIGH,
        self::CONFIDENCE_MEDIUM,
        self::CONFIDENCE_LOW,
        self::CONFIDENCE_UNCERTAIN,
    ];

    public const APPROVAL_STATUS_PENDING = 'pending';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_STATUS_PENDING,
        self::APPROVAL_STATUS_APPROVED,
        self::APPROVAL_STATUS_REJECTED,
    ];

    /** A real source reference exists — the strongest possible state. */
    public const SOURCE_STATUS_FOUND = 'source_found';

    /** A real source reference exists but none of its excerpts are filled in. */
    public const SOURCE_STATUS_MISSING_EXCERPT = 'missing_excerpt';

    /** No source reference exists, but a System Owner manually approved the claim. */
    public const SOURCE_STATUS_MANUALLY_APPROVED = 'manually_approved';

    /** No source reference exists and the claim has not been manually approved. */
    public const SOURCE_STATUS_MISSING = 'missing_source';

    protected $fillable = [
        'enterprise_wiki_page_id',
        'enterprise_wiki_page_version_id',
        'claim_text',
        'position_order',
        'confidence',
        'conflict_flag',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_comment',
        'verified_at',
        'verification_claimed_at',
        'verification_claim_token',
    ];

    protected function casts(): array
    {
        return [
            'position_order' => 'integer',
            'conflict_flag' => 'boolean',
            'approved_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_claimed_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'enterprise_wiki_page_version_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function sourceReferences(): HasMany
    {
        return $this->hasMany(EnterpriseWikiSourceReference::class);
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_APPROVED;
    }

    public function reconciliationAttempts(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaimSourceReconciliationAttempt::class);
    }

    public function hasSourceReference(): bool
    {
        return $this->relationLoaded('sourceReferences')
            ? $this->sourceReferences->isNotEmpty()
            : $this->sourceReferences()->exists();
    }

    /**
     * Single source of truth for how a claim's sourcing should be presented and whether the
     * "claim has no source reference" lint warning applies to it. Priority: a real source
     * reference always wins (even one manually approved earlier — the approval trail is kept,
     * but the badge reflects the stronger, now-available evidence); manual approval only
     * matters while no real source exists yet.
     */
    public function sourceStatus(): string
    {
        $references = $this->relationLoaded('sourceReferences')
            ? $this->sourceReferences
            : $this->sourceReferences()->get();

        if ($references->isEmpty()) {
            return $this->isApproved() ? self::SOURCE_STATUS_MANUALLY_APPROVED : self::SOURCE_STATUS_MISSING;
        }

        return $references->every(fn (EnterpriseWikiSourceReference $ref) => empty($ref->excerpt))
            ? self::SOURCE_STATUS_MISSING_EXCERPT
            : self::SOURCE_STATUS_FOUND;
    }

    /**
     * Whether this claim should trigger the "claim has no source reference" lint warning —
     * true only when it has neither a real source reference nor a manual approval.
     */
    public function needsSourceWarning(): bool
    {
        return $this->sourceStatus() === self::SOURCE_STATUS_MISSING;
    }
}
