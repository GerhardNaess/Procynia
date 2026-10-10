<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed edge between two quality items.
 *
 * Stored once, in the direction it is true in: a policy governs a process, a process does not
 * govern a policy. Reverse traversal is a `to_item_id` lookup, which the index covers; a mirrored
 * row would assert something false.
 */
class QualityItemRelation extends Model
{
    /**
     * Styrende dokument -> Process. The document sets the frame the process works inside: a policy
     * its principles, a procedure or work instruction how parts of it are carried out, a checklist
     * what must not be forgotten. One relation for all four, because the process asks one question
     * of them — "which documents apply to me?" — and the Styrende dokumenter section answers it.
     */
    public const TYPE_GOVERNS = 'governs';

    /** Process -> Procedure. The procedure is how a part of the process is actually carried out. */
    public const TYPE_HAS_PROCEDURE = 'has_procedure';

    /** The document is applied while the work is done — a checklist in hand, an instruction followed. */
    public const TYPE_USES = 'uses';

    /** Control -> Process. The control is what confirms the process is followed. */
    public const TYPE_VERIFIES = 'verifies';

    /** Process -> Process. One cannot run until the other has delivered. */
    public const TYPE_DEPENDS_ON = 'depends_on';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_GOVERNS,
        self::TYPE_HAS_PROCEDURE,
        self::TYPE_USES,
        self::TYPE_VERIFIES,
        self::TYPE_DEPENDS_ON,
    ];

    /**
     * Which ordered type pairs each relation allows.
     *
     * Pairs rather than independent from/to lists, because the two ends are not independent:
     * `uses` is legal from a procedure to a work instruction and from either a process or a
     * procedure to a checklist, but never from a process to a work instruction — a process reaches
     * an instruction through its procedure, which is the point of the hierarchy. From/to lists
     * would quietly permit that combination.
     *
     * Declared as data so widening the model is a line here rather than a new conditional.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const TYPE_MATRIX = [
        self::TYPE_GOVERNS => [
            [QualityItem::TYPE_POLICY, QualityItem::TYPE_PROCESS],
            [QualityItem::TYPE_PROCEDURE, QualityItem::TYPE_PROCESS],
            [QualityItem::TYPE_WORK_INSTRUCTION, QualityItem::TYPE_PROCESS],
            [QualityItem::TYPE_CHECKLIST, QualityItem::TYPE_PROCESS],
        ],
        self::TYPE_HAS_PROCEDURE => [
            [QualityItem::TYPE_PROCESS, QualityItem::TYPE_PROCEDURE],
        ],
        self::TYPE_USES => [
            [QualityItem::TYPE_PROCEDURE, QualityItem::TYPE_WORK_INSTRUCTION],
            [QualityItem::TYPE_PROCESS, QualityItem::TYPE_CHECKLIST],
            [QualityItem::TYPE_PROCEDURE, QualityItem::TYPE_CHECKLIST],
        ],
        self::TYPE_VERIFIES => [
            [QualityItem::TYPE_CONTROL, QualityItem::TYPE_PROCESS],
        ],
        self::TYPE_DEPENDS_ON => [
            [QualityItem::TYPE_PROCESS, QualityItem::TYPE_PROCESS],
        ],
    ];

    /** A person drew this edge. */
    public const SOURCE_MANUAL = 'manual';

    /** Carried over from the retired page-classification model. */
    public const SOURCE_MIGRATED = 'migrated';

    protected $fillable = [
        'customer_id',
        'from_item_id',
        'to_item_id',
        'relation_type',
        'source',
        'created_by_user_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fromItem(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'from_item_id');
    }

    public function toItem(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'to_item_id');
    }

    public static function allows(string $relationType, string $fromType, string $toType): bool
    {
        foreach (self::TYPE_MATRIX[$relationType] ?? [] as [$allowedFrom, $allowedTo]) {
            if ($allowedFrom === $fromType && $allowedTo === $toType) {
                return true;
            }
        }

        return false;
    }

    /**
     * The types that may sit at the start of this relation. Presentation only — the form narrows
     * its selects with it, and {@see allows()} is what actually decides.
     *
     * @return list<string>
     */
    public static function allowedFromTypes(string $relationType): array
    {
        return array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[0],
            self::TYPE_MATRIX[$relationType] ?? [],
        )));
    }

    /**
     * The types that may sit at the end of this relation, optionally narrowed to one start type.
     *
     * @return list<string>
     */
    public static function allowedToTypes(string $relationType, ?string $fromType = null): array
    {
        $pairs = self::TYPE_MATRIX[$relationType] ?? [];

        if ($fromType !== null) {
            $pairs = array_filter($pairs, static fn (array $pair): bool => $pair[0] === $fromType);
        }

        return array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[1],
            $pairs,
        )));
    }
}
