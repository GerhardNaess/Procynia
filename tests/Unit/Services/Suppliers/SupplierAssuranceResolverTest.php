<?php

namespace Tests\Unit\Services\Suppliers;

use App\Services\Suppliers\Assurance\SupplierAssuranceResolver as Resolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kravstatus (docs/supplier-assurance-v2-plan.md §7.1, §8.3, §9.2) — pure, one row per rule. The
 * visningsstatus of each requirement is SupplierRequirementStatusTest's; here it is the input. Since
 * the kontrollbeslutning was retired (2026-10-12) no decision is an input and none is an output.
 */
class SupplierAssuranceResolverTest extends TestCase
{
    /**
     * One requirement of each level and visningsstatus, and the state it gives.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function single(): array
    {
        return [
            // Mandatory: only Dokumentert or a valid Midlertidig akseptert closes it.
            'mandatory, not evaluated' => ['mandatory', 'not_evaluated', Resolver::STATE_MANDATORY_OPEN],
            'mandatory, documented' => ['mandatory', 'documented', Resolver::STATE_IN_ORDER],
            'mandatory, partially documented' => ['mandatory', 'partially_documented', Resolver::STATE_MANDATORY_OPEN],
            'mandatory, missing' => ['mandatory', 'missing', Resolver::STATE_MANDATORY_OPEN],
            'mandatory, renewal due' => ['mandatory', 'renewal_due', Resolver::STATE_MANDATORY_OPEN],
            'mandatory, temporarily accepted' => ['mandatory', 'temporarily_accepted', Resolver::STATE_FOLLOW_UP_REQUIRED],
            'mandatory, acceptance expired' => ['mandatory', 'acceptance_expired', Resolver::STATE_MANDATORY_OPEN],
            // Important: never opens the state, but is named as unmet until documented.
            'important, missing' => ['important', 'missing', Resolver::STATE_FOLLOW_UP_REQUIRED],
            'important, temporarily accepted' => ['important', 'temporarily_accepted', Resolver::STATE_FOLLOW_UP_REQUIRED],
            'important, documented' => ['important', 'documented', Resolver::STATE_IN_ORDER],
            // Oppfølging: never more than Krever oppfølging.
            'standard, missing' => ['standard', 'missing', Resolver::STATE_FOLLOW_UP_REQUIRED],
            'standard, not evaluated' => ['standard', 'not_evaluated', Resolver::STATE_FOLLOW_UP_REQUIRED],
            'standard, renewal due' => ['standard', 'renewal_due', Resolver::STATE_FOLLOW_UP_REQUIRED],
        ];
    }

    #[DataProvider('single')]
    public function test_one_requirement(string $level, string $status, string $state): void
    {
        $result = Resolver::resolve([$this->row(1, 'Krav', $level, $status)]);

        $this->assertSame($state, $result['state']);
        $this->assertSame(1, $result['applicable_count']);
        $this->assertSame(1, $result['counts'][$status]);
        $this->assertSame($state === Resolver::STATE_MANDATORY_OPEN ? [1] : [], array_column($result['open_mandatory'], 'id'));
    }

    public function test_nothing_applies_means_no_state_at_all(): void
    {
        $this->assertNull(Resolver::resolve([]));
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
        ]);

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
            ['state', 'applicable_count', 'counts', 'open_mandatory', 'unmet'],
            array_keys($result),
        );
    }

    /** @return array{id: int, title: string, level: string, display_status: string} */
    private function row(int $id, string $title, string $level, string $status): array
    {
        return ['id' => $id, 'title' => $title, 'level' => $level, 'display_status' => $status];
    }
}
