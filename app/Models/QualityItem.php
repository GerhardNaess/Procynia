<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One styrende dokument in the virksomhet's kvalitetssystem.
 *
 * A quality item is a governance object in its own right, not a label on a Wiki page. It exists
 * from the moment somebody registers that the organisation has — or needs — a policy, a process, a
 * control; whether it has been written up in the Wiki yet is a separate question, answered by
 * QualityItemWikiLink and by nothing on this row.
 */
class QualityItem extends Model
{
    /** Why we do it. Governs processes. */
    public const TYPE_POLICY = 'policy';

    /** What is done, end to end. The backbone the rest hangs off. */
    public const TYPE_PROCESS = 'process';

    /** How a process step is carried out. */
    public const TYPE_PROCEDURE = 'procedure';

    /** The hands-on detail, for one task at one place. */
    public const TYPE_WORK_INSTRUCTION = 'work_instruction';

    /** What must be ticked off while doing the work. */
    public const TYPE_CHECKLIST = 'checklist';

    /** What verifies that the work was done as described. */
    public const TYPE_CONTROL = 'control';

    /**
     * Ordered from governing to operational — the order a kvalitetshåndbok is read in. Declared
     * once here so the controller and React never disagree about it.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_POLICY,
        self::TYPE_PROCESS,
        self::TYPE_PROCEDURE,
        self::TYPE_WORK_INSTRUCTION,
        self::TYPE_CHECKLIST,
        self::TYPE_CONTROL,
    ];

    /** Registered, not yet in force. */
    public const STATUS_DRAFT = 'draft';

    /** In force. What the organisation is actually held to. */
    public const STATUS_ACTIVE = 'active';

    /** In force, but being revised. */
    public const STATUS_UNDER_REVIEW = 'under_review';

    /** No longer in force. Kept, because what used to apply is part of the audit trail. */
    public const STATUS_RETIRED = 'retired';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RETIRED,
    ];

    /**
     * Which types own a structure table. Everything else is described by its purpose alone — a
     * policy has no steps, and giving it an empty step list would invite somebody to fill it in.
     *
     * @var list<string>
     */
    public const STRUCTURED_TYPES = [
        self::TYPE_PROCESS,
        self::TYPE_CHECKLIST,
        self::TYPE_CONTROL,
    ];

    protected $fillable = [
        'customer_id',
        'quality_type',
        'title',
        'code',
        'purpose',
        'owner_user_id',
        'status',
        'review_interval_months',
        'last_reviewed_at',
        'next_review_at',
        'last_reviewed_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'review_interval_months' => 'integer',
            'last_reviewed_at' => 'date',
            'next_review_at' => 'date',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by_user_id');
    }

    /** @return HasMany<QualityProcessStep, $this> */
    public function processSteps(): HasMany
    {
        return $this->hasMany(QualityProcessStep::class)->orderBy('position');
    }

    /** @return HasMany<QualityProcessIo, $this> */
    public function processIo(): HasMany
    {
        return $this->hasMany(QualityProcessIo::class)->orderBy('direction')->orderBy('position');
    }

    /** @return HasMany<QualityChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(QualityChecklistItem::class)->orderBy('position');
    }

    /** @return HasOne<QualityControlDetail, $this> */
    public function controlDetail(): HasOne
    {
        return $this->hasOne(QualityControlDetail::class);
    }

    /** @return HasMany<QualityItemRelation, $this> */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(QualityItemRelation::class, 'from_item_id');
    }

    /** @return HasMany<QualityItemRelation, $this> */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(QualityItemRelation::class, 'to_item_id');
    }

    /** @return HasMany<QualityItemWikiLink, $this> */
    public function wikiLinks(): HasMany
    {
        return $this->hasMany(QualityItemWikiLink::class);
    }

    public function isStructured(): bool
    {
        return in_array($this->quality_type, self::STRUCTURED_TYPES, true);
    }
}
