<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EnterpriseWikiPage extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
        self::STATUS_SUPERSEDED,
    ];

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DOMAIN = 'domain';

    public const SCOPE_PROJECT = 'project';

    public const SCOPES = [
        self::SCOPE_COMPANY,
        self::SCOPE_DOMAIN,
        self::SCOPE_PROJECT,
    ];

    public const GENERATED_BY_AI_JOB = 'ai_job';

    public const GENERATED_BY_MANUAL = 'manual';

    protected $fillable = [
        'customer_id',
        'department_id',
        'slug',
        'title',
        'scope',
        'status',
        'generated_by',
        'owner_user_id',
        'last_source_hash',
        'reviewed_at',
        'reviewed_by_user_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EnterpriseWikiPageVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(EnterpriseWikiPageVersion::class)
            ->where('is_current', true)
            ->latestOfMany('version_number');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaim::class)->orderBy('position_order');
    }

    public function ingestRuns(): HasMany
    {
        return $this->hasMany(EnterpriseWikiIngestRun::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
