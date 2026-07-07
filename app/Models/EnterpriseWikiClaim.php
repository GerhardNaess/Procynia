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
    ];

    protected function casts(): array
    {
        return [
            'position_order' => 'integer',
            'conflict_flag' => 'boolean',
            'approved_at' => 'datetime',
        ];
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
}
