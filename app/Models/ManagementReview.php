<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ledelsens gjennomgåelse (docs/management-review-v1-plan.md).
 *
 * Two stored statuses: draft and finalized. «Klar for ferdigstilling» is computed
 * (ManagementReviewReadiness) and never stored. A finalized review is frozen by the database
 * (management_reviews_finalized_locked): only the owner and next_review_due_on stay open. It is never
 * reopened; corrections are amendments.
 *
 * Every read on a user's behalf starts from ManagementReviewAccessService::visibleReviews().
 */
class ManagementReview extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_FINALIZED];

    public const JUDGEMENT_SATISFACTORY = 'satisfactory';

    public const JUDGEMENT_NEEDS_IMPROVEMENT = 'needs_improvement';

    public const JUDGEMENT_NOT_SATISFACTORY = 'not_satisfactory';

    public const JUDGEMENTS = [
        self::JUDGEMENT_SATISFACTORY,
        self::JUDGEMENT_NEEDS_IMPROVEMENT,
        self::JUDGEMENT_NOT_SATISFACTORY,
    ];

    protected $fillable = [
        'customer_id',
        'title',
        'purpose',
        'period_start',
        'period_end',
        'meeting_date',
        'all_business_areas',
        'frameworks',
        'framework_versions',
        'owner_user_id',
        'conclusion',
        'next_review_due_on',
        'status',
        'finalized_at',
        'finalized_by_user_id',
        'finalized_by_name',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'meeting_date' => 'date:Y-m-d',
            'next_review_due_on' => 'date:Y-m-d',
            'all_business_areas' => 'boolean',
            'frameworks' => 'array',
            'framework_versions' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'all_business_areas' => true,
        'frameworks' => '[]',
    ];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    /**
     * A draft may be deleted while no tiltak from it has been handed to Avvik og forbedringer: the
     * case would otherwise lose the decision it came from. The database refuses it too.
     */
    public function isDeletable(): bool
    {
        return $this->isDraft()
            && ! $this->decisions()->whereNotNull('improvement_case_id')->exists();
    }

    /**
     * The fagområder the review is limited to, or null for «Hele virksomheten».
     *
     * @return list<int>|null
     */
    public function scopeAreaIds(): ?array
    {
        if ($this->all_business_areas) {
            return null;
        }

        return $this->businessAreas()->pluck('business_areas.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function businessAreas(): BelongsToMany
    {
        return $this->belongsToMany(BusinessArea::class, 'management_review_business_areas')
            ->withPivot('customer_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ManagementReviewParticipant::class)->orderBy('position')->orderBy('id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ManagementReviewSection::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ManagementReviewDecision::class)->orderBy('position')->orderBy('id');
    }

    public function snapshotSections(): HasMany
    {
        return $this->hasMany(ManagementReviewSnapshotSection::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ManagementReviewAmendment::class)->orderBy('created_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ManagementReviewEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
