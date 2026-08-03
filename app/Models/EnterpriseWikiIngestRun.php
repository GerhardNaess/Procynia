<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseWikiIngestRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SECTIONS_PLANNED = 'sections_planned';

    public const STATUS_MAINTAINER_DECISION = 'maintainer_decision';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_GENERATING_PAGES = 'generating_pages';

    // Phase 2 of applied page generation: article/summary pages have all finished
    // successfully and concept/entity page jobs (which read their finished content as
    // context) have been dispatched.
    public const STATUS_GENERATING_CONCEPT_ENTITY_PAGES = 'generating_concept_entity_pages';

    public const STATUS_VERIFICATION_LINKING = 'verification_linking';

    public const STATUS_QA = 'qa';

    public const STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL = 'awaiting_document_owner_approval';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ESCALATED = 'escalated';

    // Used when the run stores only a maintainer decision — no pages are created.
    public const STATUS_DECISION_ONLY = 'decision_only';

    // Manually cancelled by a user (e.g. so the source document can be deleted) — terminal,
    // distinct from STATUS_FAILED/STATUS_ESCALATED because nothing went wrong.
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
        self::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_ESCALATED,
        self::STATUS_DECISION_ONLY,
        self::STATUS_CANCELLED,
    ];

    public const MAINTAINER_DECISION_STATUS_PENDING = 'pending';

    public const MAINTAINER_DECISION_STATUS_APPLIED = 'applied';

    public const QA_STATUS_PENDING = 'pending';

    public const QA_STATUS_RUNNING = 'running';

    public const QA_STATUS_PASSED = 'passed';

    public const QA_STATUS_REPAIR_REQUIRED = 'repair_required';

    public const QA_STATUS_FAILED = 'failed';

    public const QA_STATUS_ESCALATED = 'escalated';

    public const QA_STATUSES = [
        self::QA_STATUS_PENDING,
        self::QA_STATUS_RUNNING,
        self::QA_STATUS_PASSED,
        self::QA_STATUS_REPAIR_REQUIRED,
        self::QA_STATUS_FAILED,
        self::QA_STATUS_ESCALATED,
    ];

    /**
     * Maximum number of automatic claim-content repair attempts (EnterpriseWikiClaimContentRepairService)
     * per run. Bounds the repair loop — after this many attempts a run with unresolved claim-integrity
     * defects stays escalated for manual/technical follow-up instead of retrying indefinitely.
     */
    public const MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS = 2;

    public const TRIGGER_TYPE_MANUAL = 'manual';

    public const TRIGGER_TYPE_SCHEDULE = 'schedule';

    public const TRIGGER_TYPE_SOURCE_CHANGE = 'source_change';

    public const TRIGGER_TYPES = [
        self::TRIGGER_TYPE_MANUAL,
        self::TRIGGER_TYPE_SCHEDULE,
        self::TRIGGER_TYPE_SOURCE_CHANGE,
    ];

    public const SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION = 'knowledge_item_version';

    public const SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT = 'enterprise_wiki_document';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
        self::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_ESCALATED,
        self::STATUS_CANCELLED,
    ];

    public const NON_TERMINAL_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
        self::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
        self::STATUS_DECISION_ONLY,
    ];

    /**
     * The single, central definition of "this run is still being worked on by the automatic
     * pipeline" — deliberately narrower than NON_TERMINAL_STATUSES. STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL
     * and STATUS_DECISION_ONLY are both non-terminal (the run genuinely isn't finished/failed/
     * cancelled) but neither has any active job or lease behind it: the automatic pipeline has
     * already finished everything it is going to do, and the run is now waiting on a human
     * decision (or, for decision_only, was never meant to progress further at all). Presenting
     * "Avbryt kjøring" for either is misleading — there is nothing left to interrupt.
     *
     * Every caller that decides whether to show/allow the ordinary run-cancellation action
     * (WikiController's `can_cancel` flag and cancelRun() guard) must use this method rather than
     * re-deriving its own status list, so the Kjøringer tab and the backend can never disagree
     * about which runs are actually cancellable.
     */
    public const CANCELLABLE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
    ];

    /**
     * The single, central definition of "the automatic pipeline currently expects to make
     * technical progress on this run" — used to decide whether a long gap since the last
     * recorded activity is actually suspicious (a "seems stalled" warning) or just an ordinary
     * wait for something else to happen. Deliberately a SEPARATE concept from
     * CANCELLABLE_STATUSES above, even though their membership happens to be identical today:
     * cancellability is a policy/permission question ("can a user interrupt this run right
     * now"), while this is a technical-health question ("is there still automatic work
     * scheduled here, such that silence would be unusual"). A run waiting on a human decision
     * (STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL) or one that was never meant to progress further
     * (STATUS_DECISION_ONLY) is correctly excluded from both lists for genuinely different
     * reasons, and the two must be free to diverge later without one silently dragging the
     * other along — never derive one from the other just to save a line.
     *
     * Every caller that decides whether a long-idle run should be flagged as stalled (the
     * Kjøringer/Kildedokumenter "Ser ut til å stå stille" warning) must use
     * expectsAutomaticProgress() rather than re-deriving its own status list.
     */
    public const EXPECTS_AUTOMATIC_PROGRESS_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
    ];

    /**
     * Every in-flight pipeline phase `failed_phase` may legitimately record — the exact phase the
     * run was in the instant it failed (Wiki run-588: the run's own `status` is unconditionally
     * overwritten to STATUS_FAILED by markRunFailed(), so without this separate field the phase at
     * failure is lost the moment the row is persisted). Deliberately narrower than
     * NON_TERMINAL_STATUSES: STATUS_DECISION_ONLY is never a phase a run can "fail out of" via
     * markRunFailed() — a decision_only run follows a completely different code path that never
     * calls it.
     */
    public const FAILED_PHASES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
        self::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
    ];

    protected $fillable = [
        'uuid',
        'customer_id',
        'enterprise_wiki_page_id',
        'trigger_type',
        'source_type',
        'source_id',
        'source_hash',
        'status',
        'failed_phase',
        'model_used',
        'input_tokens',
        'output_tokens',
        'cost_estimate_nok',
        'error_message',
        'started_at',
        'finished_at',
        'maintainer_decision_json',
        'maintainer_decision_status',
        'maintainer_decision_generated_at',
        'qa_status',
        'qa_started_at',
        'qa_completed_at',
        'qa_attempt_count',
        'qa_last_error',
        'qa_result',
        'maintenance_triggered_at',
        'maintenance_source_hash',
        'deep_repair_attempted_at',
        'deep_repair_source_hash',
        'deep_repair_result',
        'claim_content_repair_attempt_count',
        'claim_content_repair_attempted_at',
        'claim_content_repair_result',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_estimate_nok' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'maintainer_decision_json' => 'array',
            'maintainer_decision_generated_at' => 'datetime',
            'qa_started_at' => 'datetime',
            'qa_completed_at' => 'datetime',
            'qa_attempt_count' => 'integer',
            'qa_result' => 'array',
            'maintenance_triggered_at' => 'datetime',
            'deep_repair_attempted_at' => 'datetime',
            'deep_repair_result' => 'array',
            'claim_content_repair_attempt_count' => 'integer',
            'claim_content_repair_attempted_at' => 'datetime',
            'claim_content_repair_result' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(EnterpriseWikiIngestSection::class, 'enterprise_wiki_ingest_run_id');
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(
            EnterpriseWikiPage::class,
            'enterprise_wiki_ingest_run_pages',
            'enterprise_wiki_ingest_run_id',
            'enterprise_wiki_page_id',
        )->withPivot('action')->withTimestamps();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Whether $phase is one of the known pipeline phases `failed_phase` may record — used by
     * EnterpriseWikiDocumentFlowService::markRunFailed() to avoid ever persisting an
     * unvalidated/free-text value (e.g. a future ad-hoc bookkeeping string local to a service
     * method) into this column.
     */
    public static function isValidFailedPhase(?string $phase): bool
    {
        return $phase !== null && in_array($phase, self::FAILED_PHASES, true);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /**
     * Whether the ordinary "Avbryt kjøring" action should be offered/allowed for this run. See
     * CANCELLABLE_STATUSES's own docblock for why this is narrower than !isTerminal().
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    /**
     * Whether a long gap since this run's last recorded activity should be treated as
     * suspicious. See EXPECTS_AUTOMATIC_PROGRESS_STATUSES's own docblock for why this is not
     * simply isCancellable() or !isTerminal().
     */
    public function expectsAutomaticProgress(): bool
    {
        return in_array($this->status, self::EXPECTS_AUTOMATIC_PROGRESS_STATUSES, true);
    }

    /**
     * Whether this run is non-terminal but has no active technical job behind it — waiting on a
     * human decision (STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL) or never meant to progress further
     * (STATUS_DECISION_ONLY) — rather than genuinely still being processed. The complement of
     * expectsAutomaticProgress() within the non-terminal statuses.
     *
     * Callers deciding whether source-document deletion may proceed directly (auto-ending the
     * human-waiting run as part of deletion) rather than requiring an explicit prior cancel must
     * use this method, not a re-derived status list. See
     * EnterpriseWikiDocumentDeletionService::hasActiveRun().
     */
    public function isAwaitingHumanAction(): bool
    {
        return ! $this->isTerminal() && ! $this->expectsAutomaticProgress();
    }
}
