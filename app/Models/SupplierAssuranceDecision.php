<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * En kontrollbeslutning: what a person with supplier.assure decided about a supplier
 * (docs/supplier-assurance-v2-plan.md §9.3) — Godkjent, Godkjent med oppfølging or Ikke godkjent for
 * nye kjøp, with a begrunnelse, and for Godkjent med oppfølging what is followed up.
 *
 * RETIRED 2026-10-12. The general decision did not say what the supplier was approved for, and
 * overlapped Krav og kvalifikasjoner, so Registrer beslutning was taken out of the workflow: no
 * route, controller or service writes one any more, and creating() below refuses it. The rows
 * already registered are history and stay — read-only under Historikk on the supplier page, and
 * still a reason a supplier cannot be deleted. Nothing reads them as the state now: not
 * Trenger oppmerksomhet, not Oppfølging, not the register, not Ledelsens gjennomgang, not the Wiki.
 *
 * Immutable: the database refuses changes and deletes too (see the migration's trigger). History
 * lists them newest first — newestFirst(): latest decided_on, then the highest id.
 *
 * state_snapshot is the control state the person saw — shown as history, never read as the state
 * now.
 */
class SupplierAssuranceDecision extends Model
{
    public const DECISION_APPROVED = 'approved';

    public const DECISION_APPROVED_WITH_FOLLOW_UP = 'approved_with_follow_up';

    public const DECISION_NOT_APPROVED = 'not_approved';

    /** Godkjent · Godkjent med oppfølging · Ikke godkjent for nye kjøp. */
    public const DECISIONS = [
        self::DECISION_APPROVED,
        self::DECISION_APPROVED_WITH_FOLLOW_UP,
        self::DECISION_NOT_APPROVED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'decision',
        'rationale',
        'follow_up_note',
        'decided_on',
        'decided_by_user_id',
        'recorded_at',
        'state_snapshot',
        'supplier_name',
        'criticality',
    ];

    protected function casts(): array
    {
        return [
            'decided_on' => 'date',
            'recorded_at' => 'datetime',
            'state_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new LogicException('Assurance decisions are retired: no new decision can be registered.');
        });

        static::updating(function (): void {
            throw new LogicException('An assurance decision is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An assurance decision is history and cannot be deleted.');
        });
    }

    /**
     * History order: latest decided_on, then the highest id.
     *
     * @param  iterable<self>  $decisions
     * @return list<self>
     */
    public static function newestFirst(iterable $decisions): array
    {
        $list = is_array($decisions) ? array_values($decisions) : iterator_to_array($decisions, false);

        usort($list, fn (self $a, self $b): int => [$b->decided_on?->toDateString(), $b->id] <=> [$a->decided_on?->toDateString(), $a->id]);

        return $list;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
