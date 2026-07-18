<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One underlying fact, shared across every Wiki page occurrence (EnterpriseWikiClaim row) that
 * expresses it. A fact is verified, decided on, and made stale exactly once; occurrences
 * (claims) stay page/block-scoped and keep their own provenance — see
 * EnterpriseWikiClaimCanonicalizationService for how a claim is matched to (or split from) a
 * fact, and EnterpriseWikiClaim::canonical_fact_id for the occurrence side of the link.
 */
class EnterpriseWikiCanonicalFact extends Model
{
    public const VERIFICATION_STATUS_PENDING = 'pending';

    public const VERIFICATION_STATUS_SUPPORTED = 'verified_supported';

    public const VERIFICATION_STATUS_UNSUPPORTED = 'verified_unsupported';

    public const VERIFICATION_STATUSES = [
        self::VERIFICATION_STATUS_PENDING,
        self::VERIFICATION_STATUS_SUPPORTED,
        self::VERIFICATION_STATUS_UNSUPPORTED,
    ];

    public const APPROVAL_STATUS_PENDING = 'pending';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_STATUS_PENDING,
        self::APPROVAL_STATUS_APPROVED,
        self::APPROVAL_STATUS_REJECTED,
    ];

    public const STALE_REASON_SOURCE_HASH_CHANGED = 'source_hash_changed';

    public const STALE_REASON_DIVERGENT_OCCURRENCE = 'divergent_occurrence';

    protected $fillable = [
        'customer_id',
        'content_origin',
        'source_type',
        'source_id',
        'source_hash',
        'source_element_keys',
        'source_element_keys_hash',
        'normalized_fingerprint',
        'canonical_text',
        'verification_status',
        'verification_reason',
        'verified_at',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_comment',
        'is_stale',
        'stale_reason',
    ];

    protected function casts(): array
    {
        return [
            'source_element_keys' => 'array',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'is_stale' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaim::class, 'canonical_fact_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isVerifiedSupported(): bool
    {
        return $this->verification_status === self::VERIFICATION_STATUS_SUPPORTED && ! $this->is_stale;
    }

    public function isVerifiedUnsupported(): bool
    {
        return $this->verification_status === self::VERIFICATION_STATUS_UNSUPPORTED && ! $this->is_stale;
    }

    public function isReusable(): bool
    {
        return ! $this->is_stale && $this->verification_status !== self::VERIFICATION_STATUS_PENDING;
    }

    public function isPendingDecision(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING;
    }
}
