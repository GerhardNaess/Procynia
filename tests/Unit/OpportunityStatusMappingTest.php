<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\Doffin\DoffinWatchProfileInboxDiscoveryService;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunityStatus;
use Mockery;
use Tests\TestCase;

/**
 * A result's status, read as a lifecycle rather than as a register's word.
 *
 * NormalizedNotice used to carry Doffin's own string, so anything wanting to know whether a notice
 * was still open compared against 'ACTIVE' — in the watch discovery service and again in the
 * controller. Both would have been wrong for a register that spells it differently, and neither
 * had any way to notice.
 *
 * The meaning is typed now. What the register actually said stays in the raw payload, because the
 * discovery card prints it verbatim as its badge and relabelling every card is a UI change.
 *
 * Boots the application: sourceUrl() reads Doffin's public URL from config.
 */
class OpportunityStatusMappingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function normalize(?string $status, array $extra = []): NormalizedNotice
    {
        $adapter = new DoffinSourceAdapter(Mockery::mock(DoffinLiveSearchService::class));

        return $adapter->normalizeLiveSearchHit(array_merge([
            'id' => '2026-400001',
            'heading' => 'Rammeavtale',
            'status' => $status,
        ], $extra));
    }

    public function test_every_doffin_status_is_read_as_the_lifecycle_it_means(): void
    {
        foreach ([
            ['ACTIVE', OpportunityStatus::Open],
            ['EXPIRED', OpportunityStatus::Expired],
            ['AWARDED', OpportunityStatus::Awarded],
            ['CANCELLED', OpportunityStatus::Cancelled],
            // The register's casing and padding are its own business.
            ['active', OpportunityStatus::Open],
            [' Awarded ', OpportunityStatus::Awarded],
        ] as [$doffinStatus, $expected]) {
            $this->assertSame($expected, $this->normalize($doffinStatus)->status, $doffinStatus);
        }
    }

    /**
     * A word Doffin has not used before is not quietly read as open. Guessing the friendlier
     * answer would put a notice nobody can bid on into a watch inbox as if it were live.
     */
    public function test_an_unknown_status_is_not_read_as_open(): void
    {
        $notice = $this->normalize('SOMETHING_NEW');

        $this->assertNull($notice->status);
        // Still shown to the user, because it is what the register said.
        $this->assertSame('SOMETHING_NEW', $notice->providerStatusLabel());
    }

    public function test_a_missing_status_is_absent_rather_than_a_lifecycle(): void
    {
        $this->assertNull($this->normalize(null)->status);
        $this->assertNull($this->normalize(null)->providerStatusLabel());
        $this->assertNull($this->normalize('   ')->providerStatusLabel());
    }

    /**
     * Watch eligibility, which is where reading the status wrongly costs something: a notice
     * nobody can bid on would land in somebody's inbox as a fresh opportunity.
     *
     * Three cases, and the middle one is why this is not a single comparison. Said nothing:
     * nothing to exclude on, so it is kept — that has always been the behaviour. Said "open":
     * kept. Said something Procynia cannot read: excluded, because an unknown state is not a
     * known-good one.
     */
    public function test_watch_eligibility_keeps_open_and_silent_notices_and_excludes_the_rest(): void
    {
        $eligible = function (?string $status): bool {
            $notice = $this->normalize($status);
            $method = new \ReflectionMethod(DoffinWatchProfileInboxDiscoveryService::class, 'hasEligibleStatus');

            return $method->invoke(app(DoffinWatchProfileInboxDiscoveryService::class), $notice);
        };

        $this->assertTrue($eligible('ACTIVE'), 'open is eligible');
        $this->assertTrue($eligible(null), 'a register that says nothing gives nothing to exclude on');
        $this->assertTrue($eligible('   '), 'and neither does whitespace');
        $this->assertFalse($eligible('EXPIRED'));
        $this->assertFalse($eligible('AWARDED'));
        $this->assertFalse($eligible('CANCELLED'));
        $this->assertFalse($eligible('SOMETHING_NEW'), 'an unknown state is not a known-good one');
    }

    /**
     * The badge on a discovery card prints this string verbatim, so it is display text. Typing the
     * meaning must not relabel every card, and an unrecognised status must not vanish from one.
     */
    public function test_the_discovery_payload_still_carries_the_registers_own_word(): void
    {
        $this->assertSame('ACTIVE', $this->normalize('ACTIVE')->toDiscoveryPayload()['status']);
        $this->assertSame('AWARDED', $this->normalize('AWARDED')->toDiscoveryPayload()['status']);
        $this->assertSame('SOMETHING_NEW', $this->normalize('SOMETHING_NEW')->toDiscoveryPayload()['status']);
        $this->assertNull($this->normalize(null)->toDiscoveryPayload()['status']);
    }
}
