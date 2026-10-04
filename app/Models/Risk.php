<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A risk in the customer's risk register.
 *
 * What the risk is, who owns it, which fagområde it belongs to and where it stands. What the risk
 * is, is said by cause → event → consequence (årsak → hendelse → konsekvens); `description` is only
 * optional supplementary text («Utfyllende informasjon») and never stands in for them. How
 * serious it is lives in its assessments — a history of RiskAssessment rows, never fields here —
 * and status says where the risk is in its lifecycle, not how high it is.
 *
 * Never query this model for a user without going through RiskAccessService::visibleRisks() —
 * the area scope is what keeps a risk outside someone's areas from being discoverable at all.
 */
class Risk extends Model
{
    public const STATUS_IDENTIFIED = 'identified';

    public const STATUS_IN_TREATMENT = 'in_treatment';

    public const STATUS_MONITORED = 'monitored';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_IDENTIFIED,
        self::STATUS_IN_TREATMENT,
        self::STATUS_MONITORED,
        self::STATUS_CLOSED,
    ];

    /**
     * Allowed review intervals, in calendar months: månedlig, kvartalsvis, halvårlig, årlig. No
     * interval (null) means no fixed review cycle. See RiskReviewSchedule.
     */
    public const REVIEW_INTERVALS = [1, 3, 6, 12];

    protected $fillable = [
        'customer_id',
        'business_area_id',
        'title',
        'cause',
        'event',
        'consequence',
        'description',
        'owner_user_id',
        'status',
        'review_interval_months',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'review_interval_months' => 'integer',
        ];
    }

    /**
     * Whether the risk has its årsak, hendelse and konsekvens. Risks registered before the
     * structured description existed do not, until someone next edits them.
     */
    public function hasStructuredDescription(): bool
    {
        return filled($this->cause) && filled($this->event) && filled($this->consequence);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The risk's primary fagområde — the scope that decides who can reach it. */
    public function businessArea(): BelongsTo
    {
        return $this->belongsTo(BusinessArea::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Newest first: the first one is the current assessment. */
    public function assessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('assessed_at')->orderByDesc('id');
    }

    /** Links to the Kvalitet controls that handle this risk. See RiskControlService. */
    public function controlLinks(): HasMany
    {
        return $this->hasMany(RiskControl::class);
    }

    /** Acceptances of residual risk, current and historical. See RiskAcceptanceService. */
    public function acceptances(): HasMany
    {
        return $this->hasMany(RiskAcceptance::class);
    }

    /** Tiltak on this risk. Reached only through the risk; see RiskTreatmentService. */
    public function treatmentActions(): HasMany
    {
        return $this->hasMany(RiskTreatmentAction::class);
    }
}
