<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiLintFinding extends Model
{
    public const CODE_CLAIM_MISSING_SOURCE = 'claim_missing_source';

    public const CODE_SOURCE_REFERENCE_MISSING_EXCERPT = 'source_reference_missing_excerpt';

    public const CODE_DOCUMENT_INGEST_FAILED = 'document_ingest_failed';

    public const CODES = [
        self::CODE_CLAIM_MISSING_SOURCE,
        self::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
        self::CODE_DOCUMENT_INGEST_FAILED,
    ];

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_page_id',
        'enterprise_wiki_claim_id',
        'enterprise_wiki_document_id',
        'code',
        'severity',
        'message',
        'status',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class, 'enterprise_wiki_claim_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }
}
