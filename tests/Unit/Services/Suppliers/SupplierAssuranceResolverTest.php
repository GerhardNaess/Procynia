<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\SupplierAssuranceDecision;
use App\Services\Suppliers\Assurance\SupplierAssuranceResolver as Resolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kontrolltilstand, «Krever beslutning» and which decisions are allowed
 * (docs/supplier-assurance-v2-plan.md §7.1, §8.3, §9.2–9.3) — pure, one row per rule. The
 * visningsstatus of each requirement is SupplierRequirementStatusTest's; here it is the input.
 */
class SupplierAssuranceResolverTest extends TestCase
{
    private const ALL = ['approved', 'approved_with_follow_up', 'not_approved'];

    private const FOLLOW_UP_OR_NOT = ['approved_with_follow_up', 'not_approved'];

    private const NOT_ONLY = ['not_approved'];

    /**
     * One requirement of each level and visningsstatus, and what the state, the signal and the
     * allowed decisions are with no decision in force.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: list<string>}>
     */
    public static function single(): array
    {
        return [
            // Mandatory: only Dokumentert or a valid Midlertidig akseptert closes it.
            'mandatory, not evaluated' => ['mandatory', 'not_evaluated', Resolver::STATE_MANDATORY_OPEN, true, self::NOT_ONLY],
            'mandatory, documented' => ['mandatory', 'documented', Resolver::STATE_IN_ORDER, false, self::ALL],
            'mandatory, partially documented' => ['mandatory', 'partially_documented', Resolver::STATE_MANDATORY_OPEN, true, self::NOT_ONLY],
            'mandatory, missing' => ['mandatory', 'missing', Resolver::STATE_MANDATORY_OPEN, true, self::NOT_ONLY],
            'mandatory, renewal due' => ['mandatory', 'renewal_due', Resolver::STATE_MANDATORY_OPEN, true, self::NOT_ONLY],
            'mandatory, temporarily accepted' => ['mandatory', 'temporarily_accepted', Resolver::STATE_FOLLOW_UP_REQUIRED, false, self::FOLLOW_UP_OR_NOT],
            'mandatory, acceptance expired' => ['mandatory', 'acceptance_expired', Resolver::STATE_MANDATORY_OPEN, true, self::NOT_ONLY],
            // Important: never a blocker, but Godkjent needs it documented.
            'important, missing' => ['important', 'missing', Resolver::STATE_FOLLOW_UP_REQUIRED, false, self::FOLLOW_UP_OR_NOT],
            'important, temporarily accepted' => ['important', 'temporarily_accepted', Resolver::STATE_FOLLOW_UP_REQUIRED, false, self::FOLLOW_UP_OR_NOT],
            'important, documented' => ['important', 'documented', Resolver::STATE_IN_ORDER, false, self::ALL],
            // Oppfølging: no effect on the allowed decisions.
            'standard, missing' => ['standard', 'missing', Resolver::STATE_FOLLOW_UP_REQUIRED, false, self::ALL],
            'standard, not evaluated' => ['standard', 'not_evaluated', Resolver::STATE_FOLLOW_UP_REQUIRED, false, self::ALL],
            'standard, renewal due' => ['standard', 'renewal_due', Resolver::STATE_FOLLOW_UP_REQUIRED, false, self::ALL],
        ];
    }

    /** @param  list<string>  $allowed */
    #[DataProvider('single')]
    public function test_one_requirement(string $level, string $status, string $state, bool $decisionRequired, array $allowed): void
    {
        $result = Resolver::resolve([$this->row(1, 'Krav', $level, $status)], null);

        $this->assertSame([$state, $decisionRequired, $allowed], [$result['state'], $result['decision_required'], $result['allowed_decisions']]);
        $this->assertSame(1, $result['applicable_count']);
        $this->assertSame(1, $result['counts'][$status]);
        $this->assertSame($state === Resolver::STATE_MANDATORY_OPEN ? [1] : [], array_column($result['open_mandatory'], 'id'));
    }

    public function test_nothing_applies_means_no_state_at_all(): void
    {
        $this->assertNull(Resolver::resolve([], null));
        $this->assertNull(Resolver::resolve([], SupplierAssuranceDecision::DECISION_APPROVED));
    }

    public function test_the_counts_add_up_and_name_what_is_open_without_any_score(): void
    {
        $result = Resolver::resolve([
            $this->row(1, 'Databehandleravtale', 'mandatory', 'acceptance_expired'),
            $this->row(2, 'Behandlingsoversikt', 'important', 'missing'),
            $this->row(3, 'ISO 27001', 'important', 'documented'),
            $this->row(4, 'Etiske retningslinjer', 'standard', 'not_evaluated'),
            $this->row(5, 'Beredskapsplan', 'standard', 'renewal_due'),
            $this->row(6, 'Ansvarsforsikring', 'mandatory', 'temporarily_accepted'),
        ], null);

        $this->assertSame(6, $result['applicable_count']);
        $this->assertSame(6, array_sum($result['counts']));
        $this->assertSame([
            'documented' => 1, 'partially_documented' => 0, 'missing' => 1, 'not_evaluated' => 1,
            'temporarily_accepted' => 1, 'renewal_due' => 1, 'acceptance_expired' => 1,
        ], $result['counts']);
        // Only the expired acceptance is open; the valid one is accepted.
        $this->assertSame([['id' => 1, 'title' => 'Databehandleravtale', 'level' => 'mandatory', 'display_status' => 'acceptance_expired']], $result['open_mandatory']);
        // Mandatory and important ones not documented: mandatory first, then by title.
        $this->assertSame([6, 1, 2], array_column($result['unmet'], 'id'));
        $this->assertSame(
            ['state', 'decision_required', 'applicable_count', 'counts', 'open_mandatory', 'unmet', 'allowed_decisions'],
            array_keys($result),
        );
    }

    public function test_only_not_approved_switches_the_signal_off_while_a_mandatory_requirement_is_open(): void
    {
        $open = [$this->row(1, 'Databehandleravtale', 'mandatory', 'missing')];

        foreach ([null, SupplierAssuranceDecision::DECISION_APPROVED, SupplierAssuranceDecision::DECISION_APPROVED_WITH_FOLLOW_UP] as $inForce) {
            $this->assertTrue(Resolver::resolve($open, $inForce)['decision_required'], (string) $inForce);
        }

        $decided = Resolver::resolve($open, SupplierAssuranceDecision::DECISION_NOT_APPROVED);
        $this->assertSame([Resolver::STATE_MANDATORY_OPEN, false], [$decided['state'], $decided['decision_required']]);

        // A decision never changes the state, nor what may be decided next.
        $this->assertSame(self::NOT_ONLY, $decided['allowed_decisions']);
        $inOrder = [$this->row(1, 'Databehandleravtale', 'mandatory', 'documented')];
        $this->assertSame(Resolver::resolve($inOrder, null), Resolver::resolve($inOrder, SupplierAssuranceDecision::DECISION_NOT_APPROVED));
    }

    public function test_the_snapshot_keeps_the_state_counts_and_what_was_not_documented_only(): void
    {
        $state = Resolver::resolve([
            $this->row(1, 'Databehandleravtale', 'mandatory', 'temporarily_accepted'),
            $this->row(2, 'Underleverandører', 'standard', 'missing'),
        ], null);

        $snapshot = Resolver::snapshot($state);

        $this->assertSame(['state', 'applicable_count', 'counts', 'unmet'], array_keys($snapshot));
        $this->assertSame([Resolver::STATE_FOLLOW_UP_REQUIRED, 2], [$snapshot['state'], $snapshot['applicable_count']]);
        $this->assertSame([1], array_column($snapshot['unmet'], 'id'));
    }

    public function test_the_decision_in_force_is_the_latest_decision_date_then_the_highest_id(): void
    {
        $decision = fn (int $id, string $on): SupplierAssuranceDecision => (new SupplierAssuranceDecision)->setRawAttributes(['id' => $id, 'decided_on' => $on]);

        $this->assertSame(8, Resolver::decisionInForce([$decision(9, '2026-05-01'), $decision(7, '2026-06-01'), $decision(8, '2026-06-01')])->id);
        $this->assertNull(Resolver::decisionInForce([]));
    }

    /** @return array{id: int, title: string, level: string, display_status: string} */
    private function row(int $id, string $title, string $level, string $status): array
    {
        return ['id' => $id, 'title' => $title, 'level' => $level, 'display_status' => $status];
    }
}
