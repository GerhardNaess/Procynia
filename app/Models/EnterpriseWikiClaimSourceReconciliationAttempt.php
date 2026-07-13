<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (claim, document) pair ever attempted by the automatic claim-source
 * reconciliation check — the persistent checkpoint that makes "has this claim already been
 * checked against this specific document" answerable without relying on
 * EnterpriseWikiClaim.verified_at, which only ever reflects the single most recent
 * verification and cannot distinguish "checked against document A" from "checked against
 * document B". See EnterpriseWikiClaimSourceReconciliationService for the reservation/lease
 * protocol that writes these rows.
 */
class EnterpriseWikiClaimSourceReconciliationAttempt extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
    ];

    public const RESULT_SUPPORTED = 'supported';

    public const RESULT_UNSUPPORTED = 'unsupported';

    public const RESULT_ERROR = 'error';

    public const RESULTS = [
        self::RESULT_SUPPORTED,
        self::RESULT_UNSUPPORTED,
        self::RESULT_ERROR,
    ];

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_claim_id',
        'enterprise_wiki_document_id',
        'status',
        'result',
        'enterprise_wiki_source_reference_id',
        'claimed_at',
        'claim_token',
        'attempted_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class, 'enterprise_wiki_claim_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }

    public function sourceReference(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiSourceReference::class, 'enterprise_wiki_source_reference_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
