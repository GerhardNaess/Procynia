<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SavedNotice extends Model
{
    use HasFactory;

    public const SOURCE_TYPE_PUBLIC_NOTICE = 'public_notice';

    public const SOURCE_TYPE_PRIVATE_REQUEST = 'private_request';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_PUBLIC_NOTICE,
        self::SOURCE_TYPE_PRIVATE_REQUEST,
    ];

    public const SOURCE_TYPE_LABELS = [
        self::SOURCE_TYPE_PUBLIC_NOTICE => 'Offentlig kunngjøring',
        self::SOURCE_TYPE_PRIVATE_REQUEST => 'Privat forespørsel',
    ];

    public const BID_STATUS_DISCOVERED = 'discovered';

    public const BID_STATUS_QUALIFYING = 'qualifying';

    public const BID_STATUS_GO_NO_GO = 'go_no_go';

    public const BID_STATUS_IN_PROGRESS = 'in_progress';

    public const BID_STATUS_SUBMITTED = 'submitted';

    public const BID_STATUS_NEGOTIATION = 'negotiation';

    public const BID_STATUS_WON = 'won';

    public const BID_STATUS_LOST = 'lost';

    public const BID_STATUS_NO_GO = 'no_go';

    public const BID_STATUS_WITHDRAWN = 'withdrawn';

    public const BID_STATUS_ARCHIVED = 'archived';

    public const BID_STATUSES = [
        self::BID_STATUS_DISCOVERED,
        self::BID_STATUS_QUALIFYING,
        self::BID_STATUS_GO_NO_GO,
        self::BID_STATUS_IN_PROGRESS,
        self::BID_STATUS_SUBMITTED,
        self::BID_STATUS_NEGOTIATION,
        self::BID_STATUS_WON,
        self::BID_STATUS_LOST,
        self::BID_STATUS_NO_GO,
        self::BID_STATUS_WITHDRAWN,
        self::BID_STATUS_ARCHIVED,
    ];

    public const BID_STATUS_LABELS = [
        self::BID_STATUS_DISCOVERED => 'Registrert',
        self::BID_STATUS_QUALIFYING => 'Kvalifiseres',
        self::BID_STATUS_GO_NO_GO => 'Go / No-Go',
        self::BID_STATUS_IN_PROGRESS => 'Under arbeid',
        self::BID_STATUS_SUBMITTED => 'Sendt',
        self::BID_STATUS_NEGOTIATION => 'Forhandling',
        self::BID_STATUS_WON => 'Vunnet',
        self::BID_STATUS_LOST => 'Tapt',
        self::BID_STATUS_NO_GO => 'No-Go',
        self::BID_STATUS_WITHDRAWN => 'Trukket',
        self::BID_STATUS_ARCHIVED => 'Arkiv',
    ];

    public const BID_CLOSURE_REASON_CAPACITY = 'capacity';

    public const BID_CLOSURE_REASON_STRATEGIC_MISMATCH = 'strategic_mismatch';

    public const BID_CLOSURE_REASON_LOW_WIN_PROBABILITY = 'low_win_probability';

    public const BID_CLOSURE_REASON_LOW_PROFITABILITY = 'low_profitability';

    public const BID_CLOSURE_REASON_CUSTOMER_CANCELLED = 'customer_cancelled';

    public const BID_CLOSURE_REASON_INTERNAL_PRIORITY_CHANGE = 'internal_priority_change';

    public const BID_CLOSURE_REASON_COMPLIANCE_RISK = 'compliance_risk';

    public const BID_CLOSURE_REASON_OTHER = 'other';

    public const BID_CLOSURE_REASONS = [
        self::BID_CLOSURE_REASON_CAPACITY,
        self::BID_CLOSURE_REASON_STRATEGIC_MISMATCH,
        self::BID_CLOSURE_REASON_LOW_WIN_PROBABILITY,
        self::BID_CLOSURE_REASON_LOW_PROFITABILITY,
        self::BID_CLOSURE_REASON_CUSTOMER_CANCELLED,
        self::BID_CLOSURE_REASON_INTERNAL_PRIORITY_CHANGE,
        self::BID_CLOSURE_REASON_COMPLIANCE_RISK,
        self::BID_CLOSURE_REASON_OTHER,
    ];

    public const BID_CLOSURE_REASON_LABELS = [
        self::BID_CLOSURE_REASON_CAPACITY => 'Manglende kapasitet',
        self::BID_CLOSURE_REASON_STRATEGIC_MISMATCH => 'Darlig strategisk match',
        self::BID_CLOSURE_REASON_LOW_WIN_PROBABILITY => 'Lav vinnersannsynlighet',
        self::BID_CLOSURE_REASON_LOW_PROFITABILITY => 'Lav lonnsomhet',
        self::BID_CLOSURE_REASON_CUSTOMER_CANCELLED => 'Kunde avlyste',
        self::BID_CLOSURE_REASON_INTERNAL_PRIORITY_CHANGE => 'Endret intern prioritering',
        self::BID_CLOSURE_REASON_COMPLIANCE_RISK => 'Compliance-risiko',
        self::BID_CLOSURE_REASON_OTHER => 'Annet',
    ];

    public const PROCUREMENT_TYPE_ONE_TIME = 'one_time';

    public const PROCUREMENT_TYPE_RECURRING = 'recurring';

    public const FOLLOW_UP_MODE_NONE = 'none';

    public const FOLLOW_UP_MODE_MANUAL_OFFSET = 'manual_offset';

    public const FOLLOW_UP_MODE_CONTRACT_END = 'contract_end';

    public const PROCUREMENT_TYPES = [
        self::PROCUREMENT_TYPE_ONE_TIME,
        self::PROCUREMENT_TYPE_RECURRING,
    ];

    public const FOLLOW_UP_MODES = [
        self::FOLLOW_UP_MODE_NONE,
        self::FOLLOW_UP_MODE_MANUAL_OFFSET,
        self::FOLLOW_UP_MODE_CONTRACT_END,
    ];

    public const EDITABLE_FOLLOW_UP_MODES = [
        self::FOLLOW_UP_MODE_NONE,
        self::FOLLOW_UP_MODE_MANUAL_OFFSET,
    ];

    private const BID_ALLOWED_TRANSITIONS = [
        self::BID_STATUS_DISCOVERED => [
            self::BID_STATUS_QUALIFYING,
            self::BID_STATUS_NO_GO,
        ],
        self::BID_STATUS_QUALIFYING => [
            self::BID_STATUS_GO_NO_GO,
            self::BID_STATUS_NO_GO,
        ],
        self::BID_STATUS_GO_NO_GO => [
            self::BID_STATUS_IN_PROGRESS,
            self::BID_STATUS_NO_GO,
        ],
        self::BID_STATUS_IN_PROGRESS => [
            self::BID_STATUS_SUBMITTED,
            self::BID_STATUS_WITHDRAWN,
        ],
        self::BID_STATUS_SUBMITTED => [
            self::BID_STATUS_NEGOTIATION,
            self::BID_STATUS_WON,
            self::BID_STATUS_LOST,
            self::BID_STATUS_WITHDRAWN,
        ],
        self::BID_STATUS_NEGOTIATION => [
            self::BID_STATUS_WON,
            self::BID_STATUS_LOST,
            self::BID_STATUS_WITHDRAWN,
        ],
        self::BID_STATUS_WON => [
            self::BID_STATUS_ARCHIVED,
        ],
        self::BID_STATUS_LOST => [
            self::BID_STATUS_ARCHIVED,
        ],
        self::BID_STATUS_NO_GO => [
            self::BID_STATUS_ARCHIVED,
        ],
        self::BID_STATUS_WITHDRAWN => [
            self::BID_STATUS_ARCHIVED,
        ],
        self::BID_STATUS_ARCHIVED => [],
    ];

    private const BID_ACTION_DEFINITIONS = [
        self::BID_STATUS_QUALIFYING => [
            'label' => 'Move to Qualifying',
            'tone' => 'primary',
        ],
        self::BID_STATUS_GO_NO_GO => [
            'label' => 'Move to Go / No-Go',
            'tone' => 'primary',
        ],
        self::BID_STATUS_IN_PROGRESS => [
            'label' => 'Move to In Progress',
            'tone' => 'primary',
        ],
        self::BID_STATUS_SUBMITTED => [
            'label' => 'Move to Submitted',
            'tone' => 'primary',
        ],
        self::BID_STATUS_NEGOTIATION => [
            'label' => 'Move to Negotiation',
            'tone' => 'primary',
        ],
        self::BID_STATUS_NO_GO => [
            'label' => 'Set as No-Go',
            'tone' => 'danger',
        ],
        self::BID_STATUS_WITHDRAWN => [
            'label' => 'Withdraw Case',
            'tone' => 'danger',
        ],
        self::BID_STATUS_WON => [
            'label' => 'Mark as Won',
            'tone' => 'success',
        ],
        self::BID_STATUS_LOST => [
            'label' => 'Mark as Lost',
            'tone' => 'danger',
        ],
        self::BID_STATUS_ARCHIVED => [
            'label' => 'Archive Case',
            'tone' => 'secondary',
        ],
    ];

    private const BID_CLOSING_STATUSES = [
        self::BID_STATUS_NO_GO,
        self::BID_STATUS_WITHDRAWN,
        self::BID_STATUS_WON,
        self::BID_STATUS_LOST,
    ];

    private const BID_STATUSES_REQUIRING_CLOSURE_REASON = [
        self::BID_STATUS_NO_GO,
        self::BID_STATUS_WITHDRAWN,
    ];

    protected $fillable = [
        'customer_id',
        'source_type',
        'organizational_department_id',
        'saved_by_user_id',
        'bid_status',
        'opportunity_owner_user_id',
        'bid_manager_user_id',
        'bid_qualified_at',
        'bid_submitted_at',
        'bid_closed_at',
        'bid_closure_reason',
        'bid_closure_note',
        'external_id',
        'title',
        'buyer_name',
        'external_url',
        'reference_number',
        'contact_person_name',
        'contact_person_email',
        'summary',
        'notes',
        'publication_date',
        'deadline',
        'status',
        'cpv_code',
        'archived_at',
        'questions_deadline_at',
        'questions_rfi_deadline_at',
        'rfi_submission_deadline_at',
        'questions_rfp_deadline_at',
        'rfp_submission_deadline_at',
        'award_date_at',
        'selected_supplier_name',
        'contract_value_mnok',
        'contract_period_text',
        'contract_period_months',
        'procurement_type',
        'follow_up_mode',
        'follow_up_offset_months',
        'next_process_date_at',
    ];

    protected $attributes = [
        'source_type' => self::SOURCE_TYPE_PUBLIC_NOTICE,
    ];

    protected $casts = [
        'publication_date' => 'datetime',
        'deadline' => 'datetime',
        'archived_at' => 'datetime',
        'bid_qualified_at' => 'datetime',
        'bid_submitted_at' => 'datetime',
        'bid_closed_at' => 'datetime',
        'questions_deadline_at' => 'datetime',
        'questions_rfi_deadline_at' => 'datetime',
        'rfi_submission_deadline_at' => 'datetime',
        'questions_rfp_deadline_at' => 'datetime',
        'rfp_submission_deadline_at' => 'datetime',
        'award_date_at' => 'datetime',
        'contract_value_mnok' => 'decimal:2',
        'contract_period_months' => 'integer',
        'follow_up_offset_months' => 'integer',
        'next_process_date_at' => 'datetime',
    ];

    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by_user_id');
    }

    public function isPublicNotice(): bool
    {
        return (string) ($this->source_type ?? self::SOURCE_TYPE_PUBLIC_NOTICE) === self::SOURCE_TYPE_PUBLIC_NOTICE;
    }

    public function isPrivateRequest(): bool
    {
        return (string) ($this->source_type ?? self::SOURCE_TYPE_PUBLIC_NOTICE) === self::SOURCE_TYPE_PRIVATE_REQUEST;
    }

    public function getSourceTypeLabelAttribute(): string
    {
        $sourceType = (string) ($this->source_type ?? self::SOURCE_TYPE_PUBLIC_NOTICE);

        return self::SOURCE_TYPE_LABELS[$sourceType] ?? $sourceType;
    }

    public function organizationalDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'organizational_department_id');
    }

    public function opportunityOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opportunity_owner_user_id');
    }

    public function bidManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bid_manager_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(BidSubmission::class)->orderBy('sequence_number');
    }

    public function businessReviews(): HasMany
    {
        return $this->hasMany(SavedNoticeBusinessReview::class)
            ->orderBy('business_review_at')
            ->orderBy('id');
    }

    public function infoItems(): HasMany
    {
        return $this->hasMany(SavedNoticeInfoItem::class)
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [SavedNoticeInfoItem::STATUS_CLOSED])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function userAccesses(): HasMany
    {
        return $this->hasMany(SavedNoticeUserAccess::class)->orderByDesc('created_at');
    }

    public function phaseComments(): HasMany
    {
        return $this->hasMany(SavedNoticePhaseComment::class)->orderBy('created_at');
    }

    public function getBidStatusLabelAttribute(): string
    {
        $status = (string) ($this->bid_status ?? '');

        return self::BID_STATUS_LABELS[$status] ?? $status;
    }

    public function getBidClosureReasonLabelAttribute(): string
    {
        $reason = (string) ($this->bid_closure_reason ?? '');

        return self::BID_CLOSURE_REASON_LABELS[$reason] ?? $reason;
    }

    public function canCreateSubmission(): bool
    {
        return in_array((string) ($this->bid_status ?? ''), [
            self::BID_STATUS_SUBMITTED,
            self::BID_STATUS_NEGOTIATION,
        ], true);
    }

    public function availableBidStatusActions(): array
    {
        $currentStatus = (string) ($this->bid_status ?: self::BID_STATUS_DISCOVERED);
        $allowedTransitions = self::BID_ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        return array_map(function (string $status): array {
            $definition = self::BID_ACTION_DEFINITIONS[$status] ?? [
                'label' => self::BID_STATUS_LABELS[$status] ?? $status,
                'tone' => 'primary',
            ];

            return [
                'status' => $status,
                'label' => $definition['label'],
                'tone' => $definition['tone'],
                'requires_closure_reason' => in_array($status, self::BID_STATUSES_REQUIRING_CLOSURE_REASON, true),
            ];
        }, $allowedTransitions);
    }

    public static function bidClosureReasonOptions(): array
    {
        return array_map(fn (string $reason): array => [
            'value' => $reason,
            'label' => self::BID_CLOSURE_REASON_LABELS[$reason],
        ], self::BID_CLOSURE_REASONS);
    }

    public static function assertValidBidStatusTransition(
        string $fromStatus,
        string $toStatus,
        ?string $closureReason = null,
    ): void
    {
        if (! in_array($toStatus, self::BID_STATUSES, true)) {
            throw new \InvalidArgumentException("Unsupported bid status [{$toStatus}].");
        }

        if (! in_array($fromStatus, self::BID_STATUSES, true)) {
            throw new \InvalidArgumentException("Unsupported current bid status [{$fromStatus}].");
        }

        $normalizedClosureReason = self::normalizeBidClosureReason($closureReason);

        if ($normalizedClosureReason !== null && ! in_array($normalizedClosureReason, self::BID_CLOSURE_REASONS, true)) {
            throw new \InvalidArgumentException("Unsupported bid closure reason [{$normalizedClosureReason}].");
        }

        if ($fromStatus === self::BID_STATUS_ARCHIVED) {
            throw new \InvalidArgumentException('Bid status transition from [archived] is not allowed.');
        }

        $allowedTransitions = self::BID_ALLOWED_TRANSITIONS[$fromStatus] ?? [];

        if (! in_array($toStatus, $allowedTransitions, true)) {
            throw new \InvalidArgumentException("Bid status transition from [{$fromStatus}] to [{$toStatus}] is not allowed.");
        }

        if (in_array($toStatus, self::BID_STATUSES_REQUIRING_CLOSURE_REASON, true) && $normalizedClosureReason === null) {
            throw new \InvalidArgumentException("Bid closure reason is required when transitioning to [{$toStatus}].");
        }
    }

    public function transitionBidStatus(
        string $toStatus,
        ?string $closureReason = null,
        ?string $closureNote = null,
    ): self
    {
        $fromStatus = (string) ($this->bid_status ?: self::BID_STATUS_DISCOVERED);
        $normalizedClosureReason = self::normalizeBidClosureReason($closureReason);
        $normalizedClosureNote = self::normalizeBidClosureNote($closureNote);

        self::assertValidBidStatusTransition($fromStatus, $toStatus, $normalizedClosureReason);

        $this->bid_status = $toStatus;

        if (in_array($toStatus, self::BID_STATUSES_REQUIRING_CLOSURE_REASON, true)) {
            $this->bid_closure_reason = $normalizedClosureReason;
            $this->bid_closure_note = $normalizedClosureNote;
        }

        if (in_array($toStatus, self::BID_CLOSING_STATUSES, true)) {
            $this->bid_closed_at ??= now();
        }

        if ($toStatus === self::BID_STATUS_ARCHIVED) {
            $this->archived_at ??= now();
        }

        return $this;
    }

    public function createNextSubmission(?\DateTimeInterface $submittedAt = null): BidSubmission
    {
        return DB::transaction(function () use ($submittedAt): BidSubmission {
            self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();

            $nextSequenceNumber = (int) BidSubmission::query()
                ->where('saved_notice_id', $this->getKey())
                ->max('sequence_number');

            $nextSequenceNumber++;

            return $this->submissions()->create([
                'sequence_number' => $nextSequenceNumber,
                'label' => BidSubmission::defaultLabelForSequence($nextSequenceNumber),
                'submitted_at' => $submittedAt,
            ]);
        });
    }

    private static function normalizeBidClosureReason(?string $closureReason): ?string
    {
        $value = trim((string) $closureReason);

        return $value === '' ? null : $value;
    }

    private static function normalizeBidClosureNote(?string $closureNote): ?string
    {
        $value = trim((string) $closureNote);

        return $value === '' ? null : $value;
    }
}
