<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * En leverandørvurdering: how the supplier performed, as judged on one day — four fixed criteria,
 * the overall result the assessor chose, and why. Never computed: the criteria are the basis for
 * the result, not its source. Something else than the supplier's criticality, which it never
 * changes; the criticality and review interval at the time are kept here as a snapshot, with the
 * supplier's name, so an old assessment reads the same after either changes.
 *
 * Immutable: a mistaken assessment is corrected by registering a new one. The database refuses
 * changes and deletes too (see the migration's trigger).
 *
 * Written only by SupplierAssessmentService. Has no access rules of its own — reach it only through
 * a supplier from SupplierAccessService::visibleSuppliers().
 */
class SupplierAssessment extends Model
{
    public const RATING_GOOD = 'good';

    public const RATING_ACCEPTABLE = 'acceptable';

    public const RATING_POOR = 'poor';

    public const RATING_NOT_RELEVANT = 'not_relevant';

    /** Bra · Akseptabelt · Svakt · Ikke relevant. */
    public const RATINGS = [
        self::RATING_GOOD,
        self::RATING_ACCEPTABLE,
        self::RATING_POOR,
        self::RATING_NOT_RELEVANT,
    ];

    /**
     * The four fixed criteria (plan §4.3), each its own column: Kvalitet på leveransen,
     * Leveringspresisjon og respons, Informasjonssikkerhet og personvern, Etterlevelse av avtale og
     * krav. Not configurable in v1.
     */
    public const CRITERIA = [
        'quality_rating',
        'delivery_rating',
        'security_rating',
        'compliance_rating',
    ];

    public const RESULT_SATISFACTORY = 'satisfactory';

    public const RESULT_PARTIALLY_SATISFACTORY = 'partially_satisfactory';

    public const RESULT_UNSATISFACTORY = 'unsatisfactory';

    /** Tilfredsstillende · Delvis tilfredsstillende · Ikke tilfredsstillende — chosen, never computed. */
    public const RESULTS = [
        self::RESULT_SATISFACTORY,
        self::RESULT_PARTIALLY_SATISFACTORY,
        self::RESULT_UNSATISFACTORY,
    ];

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'assessed_on',
        'assessed_by_user_id',
        'quality_rating',
        'delivery_rating',
        'security_rating',
        'compliance_rating',
        'overall_result',
        'rationale',
        'supplier_name',
        'criticality',
        'review_interval_months',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'review_interval_months' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A supplier assessment is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A supplier assessment is history and cannot be deleted.');
        });
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
