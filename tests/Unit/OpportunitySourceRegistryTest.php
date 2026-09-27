<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\Exceptions\UnknownOpportunitySourceException;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunitySourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceRegistry;
use App\Services\OpportunitySources\OpportunitySourceSearchResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Source key in, adapter out.
 *
 * The container used to bind OpportunitySourceAdapter to DoffinSourceAdapter, so every
 * source-neutral consumer was really holding Doffin while calling it "the source". That is
 * indistinguishable from correct until a second register exists, and then it is silently wrong:
 * a TED record would have been handed Doffin's URL builder with nothing to notice it by.
 *
 * These tests use a stand-in adapter rather than a placeholder TED one. The question is whether
 * the registry keeps two sources apart at all — not whether any particular second register has
 * been built, which it has not.
 */
class OpportunitySourceRegistryTest extends TestCase
{
    private function fakeAdapter(string $sourceKey): OpportunitySourceAdapter
    {
        return new class($sourceKey) implements OpportunitySourceAdapter
        {
            public function __construct(private readonly string $key) {}

            public function sourceKey(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Stand-in for '.$this->key;
            }

            public function search(OpportunitySearchCriteria $criteria, int $page, int $perPage): OpportunitySourceSearchResult
            {
                return new OpportunitySourceSearchResult(
                    ok: true,
                    notices: [],
                    page: $page,
                    perPage: $perPage,
                    numHitsTotal: 0,
                    numHitsAccessible: 0,
                    fallbackUsed: false,
                );
            }

            public function normalizeLiveSearchHit(array $hit): ?NormalizedNotice
            {
                return null;
            }

            public function sourceUrl(string $externalId): ?string
            {
                return 'https://'.$this->key.'.test/notices/'.$externalId;
            }
        };
    }

    public function test_an_adapter_is_found_under_its_own_key(): void
    {
        $doffin = $this->fakeAdapter(DoffinSourceAdapter::SOURCE_KEY);
        $registry = new OpportunitySourceRegistry([$doffin]);

        $this->assertSame($doffin, $registry->get('doffin'));
    }

    /** Keys come from the adapters, so there is no second list to keep in step with the first. */
    public function test_the_key_comes_from_the_adapter_not_from_a_parallel_list(): void
    {
        $registry = new OpportunitySourceRegistry([$this->fakeAdapter('some-register')]);

        $this->assertSame(['some-register'], $registry->keys());
        $this->assertSame('some-register', $registry->get('some-register')->sourceKey());
    }

    public function test_all_returns_every_adapter_keyed_by_source(): void
    {
        $doffin = $this->fakeAdapter('doffin');
        $other = $this->fakeAdapter('other-register');
        $registry = new OpportunitySourceRegistry([$doffin, $other]);

        $this->assertSame(['doffin' => $doffin, 'other-register' => $other], $registry->all());
    }

    /**
     * The failure mode this whole class exists to prevent: an unknown source quietly becoming
     * whichever adapter happens to be registered.
     */
    public function test_an_unknown_source_never_resolves_to_a_registered_one(): void
    {
        $registry = new OpportunitySourceRegistry([$this->fakeAdapter('doffin')]);

        $this->expectException(UnknownOpportunitySourceException::class);
        $this->expectExceptionMessageMatches('/ted/');

        $registry->get('ted');
    }

    /** The message says what is registered, so the answer is in the failure rather than a guess. */
    public function test_the_failure_names_what_is_registered(): void
    {
        $registry = new OpportunitySourceRegistry([$this->fakeAdapter('doffin')]);

        try {
            $registry->get('ted');
            $this->fail('an unknown source must throw');
        } catch (UnknownOpportunitySourceException $exception) {
            $this->assertStringContainsString('doffin', $exception->getMessage());
        }
    }

    /**
     * For a caller reading a stored row rather than running a search: null is an answer it can act
     * on, and still not another register's adapter.
     */
    public function test_find_answers_null_for_an_unknown_source(): void
    {
        $registry = new OpportunitySourceRegistry([$this->fakeAdapter('doffin')]);

        $this->assertNull($registry->find('ted'));
        $this->assertNull($registry->find(null));
        $this->assertNotNull($registry->find('doffin'));
    }

    /**
     * A silent overwrite is the worst outcome available: two adapters claiming one key would leave
     * whichever registered last in charge, and the collision would only show as results coming
     * from the wrong place.
     */
    public function test_two_adapters_cannot_claim_one_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/doffin/');

        new OpportunitySourceRegistry([$this->fakeAdapter('doffin'), $this->fakeAdapter('doffin')]);
    }

    public function test_a_source_without_a_key_cannot_be_registered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OpportunitySourceRegistry([$this->fakeAdapter('   ')]);
    }

    public function test_keys_are_matched_without_surrounding_whitespace(): void
    {
        $registry = new OpportunitySourceRegistry([$this->fakeAdapter('doffin')]);

        $this->assertNotNull($registry->find(' doffin '));
    }
}
