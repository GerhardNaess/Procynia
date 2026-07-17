<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiPageVersionDocumentOwnerApproval extends Model
{
    public const APPROVAL_STATUS_PENDING = 'pending';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_STATUS_PENDING,
        self::APPROVAL_STATUS_APPROVED,
        self::APPROVAL_STATUS_REJECTED,
    ];

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_page_id',
        'enterprise_wiki_page_version_id',
        'enterprise_wiki_ingest_run_id',
        'document_owner_user_id',
        'source_document_ids',
        'source_documents_hash',
        'approval_status',
        'approval_comment',
        'decided_at',
        'decided_by_user_id',
        'is_override',
        'override_reason',
        'overridden_by_user_id',
        'overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'enterprise_wiki_page_id' => 'integer',
            'enterprise_wiki_page_version_id' => 'integer',
            'enterprise_wiki_ingest_run_id' => 'integer',
            'document_owner_user_id' => 'integer',
            'source_document_ids' => 'array',
            'decided_at' => 'datetime',
            'decided_by_user_id' => 'integer',
            'is_override' => 'boolean',
            'overridden_by_user_id' => 'integer',
            'overridden_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function pageVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'enterprise_wiki_page_version_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }

    public function documentOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'document_owner_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_user_id');
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
}
