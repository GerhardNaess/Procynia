<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A risk in the customer's risk register.
 *
 * What the risk is, who owns it, which tilgangsområde it belongs to and where it stands. How
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

    protected $fillable = [
        'customer_id',
        'risk_access_area_id',
        'title',
        'description',
        'owner_user_id',
        'status',
        'created_by',
        'updated_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function accessArea(): BelongsTo
    {
        return $this->belongsTo(RiskAccessArea::class, 'risk_access_area_id');
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
}
