<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * En aktsomhetsvurdering (docs/supplier-assurance-v2-plan.md §11): a person's documented assessment
 * of human rights, working conditions and environment in a supplier's supply chain. Six areas, each
 * Lav · Forhøyet · Høy · Ukjent; what was mapped and investigated; a conclusion the person chooses
 * and a begrunnelse.
 *
 * Not a score. There is no total, no weighting and no computed conclusion: «Høy» in an area never
 * becomes «Tiltak kreves» by itself, and nothing here touches the supplier's assurance decision,
 * criticality or lifecycle.
 *
 * Immutable: a new assessment is a new row. The database refuses changes and deletes too (see the
 * migration's trigger). The assessment in force is the latest assessed_on, then the highest id —
 * newestFirst(). The snapshot (supplier name, criticality, high_risk_categories,
 * production_outside_eea) is how the supplier was when it was assessed.
 *
 * Written only by SupplierDueDiligenceService.
 */
class SupplierDueDiligenceAssessment extends Model
{
    public const LEVEL_LOW = 'low';

    public const LEVEL_ELEVATED = 'elevated';

    public const LEVEL_HIGH = 'high';

    public const LEVEL_UNKNOWN = 'unknown';

    /** Lav · Forhøyet · Høy · Ukjent. Ukjent is not low: it says what has not been found out. */
    public const LEVELS = [self::LEVEL_LOW, self::LEVEL_ELEVATED, self::LEVEL_HIGH, self::LEVEL_UNKNOWN];

    /** Barnearbeid · Tvangsarbeid · Arbeidsforhold · Diskriminering · Organisasjonsfrihet · Miljø. */
    public const AREAS = [
        'child_labour_risk',
        'forced_labour_risk',
        'working_conditions_risk',
        'discrimination_risk',
        'freedom_of_association_risk',
        'environment_risk',
    ];

    public const CONCLUSION_NO_SIGNIFICANT_RISK = 'no_significant_risk';

    public const CONCLUSION_MONITOR = 'monitor';

    public const CONCLUSION_MEASURES_REQUIRED = 'measures_required';

    /** Ingen vesentlig risiko avdekket · Risiko følges opp · Tiltak kreves. */
    public const CONCLUSIONS = [
        self::CONCLUSION_NO_SIGNIFICANT_RISK,
        self::CONCLUSION_MONITOR,
        self::CONCLUSION_MEASURES_REQUIRED,
    ];

    public const REVIEW_INTERVALS = [6, 12, 24];

    /**
     * The interval the form starts with (plan §11.3): 12 months unless the conclusion is «Ingen
     * vesentlig risiko avdekket», then 24. Only a suggestion; the person chooses.
     */
    public static function suggestedInterval(?string $conclusion): int
    {
        return $conclusion === self::CONCLUSION_NO_SIGNIFICANT_RISK ? 24 : 12;
    }

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'child_labour_risk',
        'forced_labour_risk',
        'working_conditions_risk',
        'discrimination_risk',
        'freedom_of_association_risk',
        'environment_risk',
        'supply_chain_description',
        'investigation_summary',
        'conclusion',
        'rationale',
        'review_interval_months',
        'assessed_on',
        'assessed_by_user_id',
        'recorded_at',
        'supplier_name',
        'criticality',
        'high_risk_categories',
        'production_outside_eea',
    ];

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'recorded_at' => 'datetime',
            'review_interval_months' => 'integer',
            'high_risk_categories' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A due diligence assessment is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A due diligence assessment is history and cannot be deleted.');
        });
    }

    /**
     * Order for «the assessment in force»: latest assessed_on, then the highest id.
     *
     * @param  iterable<self>  $assessments
     * @return list<self>
     */
    public static function newestFirst(iterable $assessments): array
    {
        $list = is_array($assessments) ? array_values($assessments) : iterator_to_array($assessments, false);

        usort($list, fn (self $a, self $b): int => [$b->assessed_on?->toDateString(), $b->id] <=> [$a->assessed_on?->toDateString(), $a->id]);

        return $list;
    }

    /** @return array<string, string> area => level */
    public function areas(): array
    {
        return $this->only(self::AREAS);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }
}
