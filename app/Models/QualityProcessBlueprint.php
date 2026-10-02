<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The flow of one process — lanes, nodes and edges — as the structure a diagram is drawn from.
 *
 * The payload is the source of truth. Nothing downstream stores geometry: the swimlane is laid out
 * deterministically from these rows every time it is rendered, so there is exactly one thing to
 * edit and exactly one thing to approve.
 */
class QualityProcessBlueprint extends Model
{
    /** Proposed, not yet vouched for. */
    public const STATUS_DRAFT = 'draft';

    /** Reviewed and accepted as how the process actually runs. */
    public const STATUS_APPROVED = 'approved';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
    ];

    /** Retired. Was built from the process's own steps, inputs and outputs. */
    public const SOURCE_DERIVED = 'derived';

    /** Retired. Was seeded from a worked example when the process had no steps to derive from. */
    public const SOURCE_EXAMPLE = 'example';

    /** Interpreted from a plain-language description by a model, and adopted by a person. */
    public const SOURCE_AI = 'ai';

    /** Edited by a person. Any save through the editor lands here. */
    public const SOURCE_MANUAL = 'manual';

    /**
     * The sources a blueprint may be written with: a flow a person adopted, and a flow a person
     * edited. Both are a person's statement about their own process.
     *
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_AI,
        self::SOURCE_MANUAL,
    ];

    /**
     * Sources that exist in rows written before the generator was removed, and that may never be
     * written again.
     *
     * The generator seeded a flow — from the process's steps, or from a worked ITIL Incident
     * Management example when there were none — and stored it straight over whatever the process
     * already had. A seeded flow is not a statement about the customer's process, so it must never
     * be able to displace one that is. The constants stay so an old row still has a name; the
     * allowlist above is what decides what can be stored.
     *
     * @var list<string>
     */
    public const RETIRED_SOURCES = [
        self::SOURCE_DERIVED,
        self::SOURCE_EXAMPLE,
    ];

    /** Where the flow begins. Exactly one per blueprint. */
    public const NODE_START = 'start';

    /** Work is done. */
    public const NODE_STEP = 'step';

    /** The flow branches. Its outgoing edges carry the outcomes. */
    public const NODE_DECISION = 'decision';

    /** The flow ends. There may be several — "løst" and "eskalert" are both endings. */
    public const NODE_END = 'end';

    /** @var list<string> */
    public const NODE_TYPES = [
        self::NODE_START,
        self::NODE_STEP,
        self::NODE_DECISION,
        self::NODE_END,
    ];

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'payload',
        'description',
        'status',
        'source',
        'generated_at',
        'generated_by_user_id',
        'approved_at',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** @return list<array<string, mixed>> */
    public function lanes(): array
    {
        return array_values($this->payload['lanes'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return array_values($this->payload['nodes'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return array_values($this->payload['edges'] ?? []);
    }
}
