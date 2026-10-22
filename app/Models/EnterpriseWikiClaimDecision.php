<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail for an Enterprise Wiki claim: every change of its approval status or
 * blocking, and every source a person links to it — who or what acted, when, any comment, and the
 * previous/new state. EnterpriseWikiClaim's own columns hold only the CURRENT state; this is how it
 * got there. Never updated or deleted (the model refuses, and so does a PostgreSQL trigger); it goes
 * only with its claim.
 *
 * Only EnterpriseWikiClaimDecisionRecorder writes rows, in the same transaction as the change.
 *
 * The actor is a person (ACTOR_USER, decided_by_user_id) or Procynia itself (ACTOR_SYSTEM): a rule
 * that changed the state on its own, named by system_process, with initiated_by_user_id the person
 * whose action set it off, if any. A system event is never attributed to a person.
 */
class EnterpriseWikiClaimDecision extends Model
{
    public const TYPE_APPROVAL_STATUS = 'approval_status';

    public const TYPE_BLOCKING_OVERRIDE = 'blocking_override';

    /** A person linked a document source to the claim («Koble kilde»). */
    public const TYPE_SOURCE_REFERENCE = 'source_reference';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    /** A decided claim got its first real source, so the decision has to be taken again. */
    public const PROCESS_RESET_ON_FIRST_SOURCE = 'reset_on_first_source';

    /** A decided claim lost its only source with a deleted document. */
    public const PROCESS_RESET_ON_LOST_SOURCE = 'reset_on_lost_source';

    /** A new version took over the claim, and with it the state the claim it copies holds. */
    public const PROCESS_COPIED_TO_NEW_VERSION = 'copied_to_new_version';

    /** Written by the migration that made the history complete, where the state had none. */
    public const PROCESS_HISTORY_BACKFILL = 'history_backfill';

    public $timestamps = false;

    protected $fillable = [
        'enterprise_wiki_claim_id',
        'decided_by_user_id',
        'actor_type',
        'system_process',
        'initiated_by_user_id',
        'decision_type',
        'previous_state',
        'new_state',
        'comment',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_state' => 'array',
            'new_state' => 'array',
            'decided_by_user_id' => 'integer',
            'initiated_by_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $decision): void {
            $decision->actor_type ??= self::ACTOR_USER;

            $valid = $decision->actor_type === self::ACTOR_USER
                ? $decision->decided_by_user_id !== null && $decision->system_process === null && $decision->initiated_by_user_id === null
                : $decision->actor_type === self::ACTOR_SYSTEM && $decision->decided_by_user_id === null && $decision->system_process !== null;

            if (! $valid) {
                throw new LogicException('A claim decision is made by a person (decided_by_user_id) or by a named system process, never both or neither.');
            }
        });

        static::updating(static function (): void {
            throw new LogicException('A claim decision is history and cannot be changed.');
        });

        static::deleting(static function (): void {
            throw new LogicException('A claim decision is history and cannot be deleted.');
        });
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class, 'enterprise_wiki_claim_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function isSystemEvent(): bool
    {
        return $this->actor_type === self::ACTOR_SYSTEM;
    }
}
