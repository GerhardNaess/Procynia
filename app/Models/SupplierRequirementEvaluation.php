<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * En kontroll: a person's conclusion about one control requirement for one supplier, on the basis of
 * the documentation they name (docs/supplier-assurance-v2-plan.md §8.1). Documentation is evidence;
 * the status is the person's judgement of it — nothing here or anywhere else sets it from a document
 * type, a certificate or a validity date.
 *
 * Dokumentert needs at least one document; Midlertidig akseptert needs a date it is accepted until.
 * Every status needs a begrunnelse. There is no «Ikke relevant»: whether a requirement applies is
 * an override (§5.5), not a control.
 *
 * Immutable: a mistake is corrected by a new control. The database refuses changes and deletes too
 * (see the migration's trigger). The control in force is the latest evaluated_on, then the highest
 * id — current().
 *
 * The requirement's title, level and theme, the «Gjelder fordi …» text and the supplier's name and
 * criticality are kept as they were.
 *
 * Written only by SupplierRequirementEvaluationService.
 */
class SupplierRequirementEvaluation extends Model
{
    public const STATUS_DOCUMENTED = 'documented';

    public const STATUS_PARTIALLY_DOCUMENTED = 'partially_documented';

    public const STATUS_MISSING = 'missing';

    public const STATUS_TEMPORARILY_ACCEPTED = 'temporarily_accepted';

    /** Dokumentert · Delvis dokumentert · Mangler · Midlertidig akseptert. */
    public const STATUSES = [
        self::STATUS_DOCUMENTED,
        self::STATUS_PARTIALLY_DOCUMENTED,
        self::STATUS_MISSING,
        self::STATUS_TEMPORARILY_ACCEPTED,
    ];

    /** How far ahead a temporary acceptance may run (§8.1). */
    public const MAX_ACCEPTANCE_MONTHS = 12;

    /**
     * Results that can be followed up in Avvik og forbedringer: everything short of Dokumentert
     * (plan §7.1 — following up never removes a blocker; it is follow-up, not acceptance).
     */
    public const FOLLOW_UP_STATUSES = [self::STATUS_PARTIALLY_DOCUMENTED, self::STATUS_MISSING, self::STATUS_TEMPORARILY_ACCEPTED];

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'requirement_id',
        'status',
        'rationale',
        'accepted_until',
        'evaluated_on',
        'evaluated_by_user_id',
        'recorded_at',
        'requirement_title',
        'requirement_level',
        'requirement_theme',
        'applicability_reason',
        'supplier_name',
        'criticality',
    ];

    protected function casts(): array
    {
        return [
            'accepted_until' => 'date',
            'evaluated_on' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A requirement control is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A requirement control is history and cannot be deleted.');
        });
    }

    /** Order for «the control in force»: latest evaluated_on, then the highest id. */
    public static function newestFirst(iterable $evaluations): array
    {
        $list = is_array($evaluations) ? $evaluations : iterator_to_array($evaluations, false);

        usort($list, fn (self $a, self $b): int => [$b->evaluated_on?->toDateString(), $b->id] <=> [$a->evaluated_on?->toDateString(), $a->id]);

        return $list;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SupplierControlRequirement::class, 'requirement_id');
    }

    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by_user_id');
    }

    /** The documentation given as the basis, each with its snapshot. */
    public function documents(): HasMany
    {
        return $this->hasMany(SupplierRequirementEvaluationDocument::class, 'evaluation_id')->orderBy('id');
    }
}
