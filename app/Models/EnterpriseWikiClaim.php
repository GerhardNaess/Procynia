<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseWikiClaim extends Model
{
    public const CONTENT_ORIGIN_UNCLASSIFIED = 'unclassified';

    public const CONTENT_ORIGIN_SOURCE_BASED = 'source_based';

    public const CONTENT_ORIGIN_BEST_PRACTICE = 'best_practice';

    public const CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT = 'unsupported_generated_content';

    public const CONTENT_ORIGIN_INTERNAL_ERROR = 'internal_error';

    public const CONTENT_ORIGINS = [
        self::CONTENT_ORIGIN_UNCLASSIFIED,
        self::CONTENT_ORIGIN_SOURCE_BASED,
        self::CONTENT_ORIGIN_BEST_PRACTICE,
        self::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
        self::CONTENT_ORIGIN_INTERNAL_ERROR,
    ];

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

    /** No source reference exists, but the claim was explicitly rejected. */
    public const SOURCE_STATUS_REJECTED = 'rejected';

    /** No source reference exists and the claim has not been manually approved. */
    public const SOURCE_STATUS_MISSING = 'missing_source';

    /** A best-practice suggestion is waiting for a human decision; no source should be linked. */
    public const SOURCE_STATUS_BEST_PRACTICE_REVIEW = 'best_practice_review';

    /** The claim is an internal consistency problem and must not be a normal user task. */
    public const SOURCE_STATUS_INTERNAL_ERROR = 'internal_generation_error';

    /** Generated content is unsupported and must be handled as a system deviation. */
    public const SOURCE_STATUS_UNSUPPORTED_GENERATED_CONTENT = 'unsupported_generated_content';

    protected $fillable = [
        'enterprise_wiki_page_id',
        'enterprise_wiki_page_version_id',
        'claim_text',
        'content_origin',
        'page_excerpt',
        'content_block_key',
        'canonical_fact_id',
        'review_reason',
        'review_metadata',
        'generation_issue',
        'blocking_override',
        'blocking_override_by_user_id',
        'blocking_override_at',
        'position_order',
        'confidence',
        'conflict_flag',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_comment',
        'verified_at',
        'verification_dispatched_at',
        'verification_claimed_at',
        'verification_claim_token',
    ];

    protected function casts(): array
    {
        return [
            'position_order' => 'integer',
            'conflict_flag' => 'boolean',
            'blocking_override' => 'boolean',
            'blocking_override_at' => 'datetime',
            'approved_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_dispatched_at' => 'datetime',
            'verification_claimed_at' => 'datetime',
            'review_metadata' => 'array',
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

    public function blockingOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocking_override_by_user_id');
    }

    public function sourceReferences(): HasMany
    {
        return $this->hasMany(EnterpriseWikiSourceReference::class);
    }

    public function canonicalFact(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiCanonicalFact::class, 'canonical_fact_id');
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_REJECTED;
    }

    public function reconciliationAttempts(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaimSourceReconciliationAttempt::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaimDecision::class)->latest('created_at');
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
        if ($this->content_origin === self::CONTENT_ORIGIN_INTERNAL_ERROR) {
            return self::SOURCE_STATUS_INTERNAL_ERROR;
        }

        if ($this->content_origin === self::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT) {
            return self::SOURCE_STATUS_UNSUPPORTED_GENERATED_CONTENT;
        }

        if ($this->content_origin === self::CONTENT_ORIGIN_BEST_PRACTICE && $this->isPending()) {
            return self::SOURCE_STATUS_BEST_PRACTICE_REVIEW;
        }

        $references = $this->relationLoaded('sourceReferences')
            ? $this->sourceReferences
            : $this->sourceReferences()->get();

        if ($references->isEmpty()) {
            if ($this->isApproved()) {
                return self::SOURCE_STATUS_MANUALLY_APPROVED;
            }

            if ($this->approval_status === self::APPROVAL_STATUS_REJECTED) {
                return self::SOURCE_STATUS_REJECTED;
            }

            return self::SOURCE_STATUS_MISSING;
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
        if (in_array($this->content_origin, [
            self::CONTENT_ORIGIN_BEST_PRACTICE,
            self::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            self::CONTENT_ORIGIN_INTERNAL_ERROR,
            // An unclassified claim (e.g. a navigation-only reference that does not semantically
            // fit any of the three real categories — see EnterpriseWikiClaimClassificationService)
            // was never meant to require a source citation in the first place.
            self::CONTENT_ORIGIN_UNCLASSIFIED,
        ], true)) {
            return false;
        }

        return $this->sourceStatus() === self::SOURCE_STATUS_MISSING;
    }

    public function isBestPracticeReview(): bool
    {
        return $this->content_origin === self::CONTENT_ORIGIN_BEST_PRACTICE && $this->isPending();
    }

    public function isInternalGenerationError(): bool
    {
        return $this->content_origin === self::CONTENT_ORIGIN_INTERNAL_ERROR;
    }
}
