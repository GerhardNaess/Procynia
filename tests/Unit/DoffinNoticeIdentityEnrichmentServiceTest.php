<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeIdentityEnrichmentService;
use App\Services\Doffin\DoffinPublicClient;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunityNoticeIdentity;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedSourceAdapter;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Buying Doffin's answer to "who is this notice", deliberately.
 *
 * The identifiers exist and Doffin will hand them over, but only from the detail endpoint, one
 * request per notice. Discovery reads the search endpoint and must stay that way, so the question
 * is asked here instead — by a caller that meant to ask it.
 *
 * The fixtures are real. Notice 2026-113736 is Sykehusinnkjøp's annual inspection contract for
 * St. Olavs, and the two UUIDs below are what the live notices-api returned for it on
 * 30 September 2026; TED publishes the identical pair under 599740-2026. Nothing here touches the
 * network.
 */
class DoffinNoticeIdentityEnrichmentServiceTest extends TestCase
{
    private const PROCEDURE = '19379be5-7821-4761-b6bf-30876e2f678e';

    private const NOTICE = '50859edc-926f-405a-a1ad-6590a08a5ba9';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $hit */
    private function doffinNotice(array $hit = []): NormalizedNotice
    {
        $adapter = new DoffinSourceAdapter(Mockery::mock(DoffinLiveSearchService::class));

        return $adapter->normalizeLiveSearchHit([
            'id' => '2026-113736',
            'heading' => 'Årlig kontroll av sikkerhetsventilasjonskap',
            'status' => 'EXPIRED',
            'publicationDate' => '2026-09-01',
            'sentToTed' => true,
            ...$hit,
        ]);
    }

    private function service(MockInterface $client): DoffinNoticeIdentityEnrichmentService
    {
        return new DoffinNoticeIdentityEnrichmentService($client);
    }

    private function client(): MockInterface
    {
        return Mockery::mock(DoffinPublicClient::class);
    }

    // ------------------------------------------------------------------ what the detail says

    public function test_a_doffin_detail_says_which_procurement_and_which_document(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-113736')
            ->andReturn([
                'id' => '2026-113736',
                'procedureId' => self::PROCEDURE,
                'eFormId' => self::NOTICE,
                'tedId' => '599740-2026',
            ]);

        $enriched = $this->service($client)->enrich($this->doffinNotice());

        $this->assertSame(self::PROCEDURE, $enriched->identity->procedureIdentifier);
        $this->assertSame(self::NOTICE, $enriched->identity->noticeIdentifier);
        // The register record is still the register record. tedId was in the detail and was not read.
        $this->assertSame('2026-113736', $enriched->externalId);
        $this->assertSame('doffin', $enriched->sourceKey);
    }

    /**
     * Doffin's spelling is Doffin's business.
     *
     * The normalisation is OpportunityNoticeIdentity's, not this service's — there is one rule for
     * what an identifier is, and a second copy of it here would be a second rule waiting to differ.
     */
    public function test_how_doffin_writes_the_identifiers_is_not_part_of_them(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')->once()->andReturn([
            'procedureId' => '  '.strtoupper(self::PROCEDURE).'  ',
            'eFormId' => self::NOTICE,
        ]);

        $enriched = $this->service($client)->enrich($this->doffinNotice());

        $this->assertSame(self::PROCEDURE, $enriched->identity->procedureIdentifier);
    }

    public function test_an_empty_or_unusable_detail_value_is_not_an_identity(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')->once()->andReturn([
            'procedureId' => '   ',
            'eFormId' => ['not', 'a', 'uuid'],
        ]);

        $enriched = $this->service($client)->enrich($this->doffinNotice());

        $this->assertNull($enriched->identity->procedureIdentifier);
        $this->assertNull($enriched->identity->noticeIdentifier);
        $this->assertFalse($enriched->identity->isKnown());
    }

    // ------------------------------------------------------------------ asking once

    /**
     * A page of search results holds the same notice more than once often enough — the same id
     * reached from two watch profiles, or a list re-enriched after filtering. Within one run it is
     * one question with one answer.
     */
    public function test_the_same_notice_is_only_asked_about_once(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-113736')
            ->andReturn(['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]);

        $service = $this->service($client);
        $first = $service->enrich($this->doffinNotice());
        $second = $service->enrich($this->doffinNotice());

        $this->assertSame(self::PROCEDURE, $first->identity->procedureIdentifier);
        $this->assertSame($first->identity->procedureIdentifier, $second->identity->procedureIdentifier);
    }

    public function test_two_different_notices_are_two_questions(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-113736')
            ->andReturn(['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]);
        $client->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-102030')
            ->andReturn([
                'procedureId' => '7d02de59-3686-415d-a2a1-2fc26652e0d0',
                'eFormId' => 'ceaeb487-d3bb-42a4-9fcc-2eb4a3df55d8',
            ]);

        $enriched = $this->service($client)->enrichAll([
            $this->doffinNotice(),
            $this->doffinNotice(['id' => '2026-102030']),
        ]);

        $this->assertSame(self::PROCEDURE, $enriched[0]->identity->procedureIdentifier);
        $this->assertSame('7d02de59-3686-415d-a2a1-2fc26652e0d0', $enriched[1]->identity->procedureIdentifier);
    }

    public function test_a_notice_that_already_knows_who_it_is_costs_no_request(): void
    {
        $client = $this->client();
        $client->shouldNotReceive('noticeDetail');

        $notice = $this->doffinNotice(['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]);
        $enriched = $this->service($client)->enrich($notice);

        $this->assertSame(self::PROCEDURE, $enriched->identity->procedureIdentifier);
        $this->assertSame(self::NOTICE, $enriched->identity->noticeIdentifier);
    }

    /** This service reads Doffin's endpoint, so a TED notice passes through it untouched. */
    public function test_a_notice_from_another_register_is_left_alone(): void
    {
        $client = $this->client();
        $client->shouldNotReceive('noticeDetail');

        $ted = (new TedSourceAdapter(Mockery::mock(TedSearchClient::class)))->normalizeLiveSearchHit([
            'publication-number' => '599740-2026',
            'procedure-identifier' => self::PROCEDURE,
            'notice-identifier' => self::NOTICE,
        ]);

        $this->assertSame($ted, $this->service($client)->enrich($ted));
    }

    // ------------------------------------------------------------------ when Doffin cannot answer

    /**
     * A withdrawn notice answers 404 and a slow one never answers at all. Neither is allowed to
     * end a discovery run, and neither may leave an identifier behind: a fabricated one would
     * match something later, confidently and wrongly.
     */
    public function test_a_failed_lookup_breaks_nothing_and_invents_nothing(): void
    {
        foreach ([
            new RuntimeException('Doffin public notice_detail failed with status 404.'),
            new RuntimeException('Doffin notice detail request failed.', 0, new ConnectionException('timed out')),
            new RuntimeException('Doffin notice detail returned invalid JSON data.'),
        ] as $index => $failure) {
            $client = $this->client();
            $client->shouldReceive('noticeDetail')->once()->andThrow($failure);

            $enriched = $this->service($client)->enrich($this->doffinNotice());

            $this->assertFalse($enriched->identity->isKnown(), "case {$index}");
            $this->assertSame('2026-113736', $enriched->externalId, "case {$index}");
            $this->assertSame('Årlig kontroll av sikkerhetsventilasjonskap', $enriched->title, "case {$index}");
        }
    }

    /** A lookup that failed is still a lookup; retrying it within the run buys the same nothing. */
    public function test_a_failed_lookup_is_not_repeated_within_the_run(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->andThrow(new RuntimeException('Doffin public notice_detail failed with status 404.'));

        $service = $this->service($client);
        $service->enrich($this->doffinNotice());
        $second = $service->enrich($this->doffinNotice());

        $this->assertFalse($second->identity->isKnown());
    }

    // ------------------------------------------------------------------ sentToTed

    /**
     * sentToTed says this notice was not forwarded. It does not say the procurement is absent from
     * TED — an earlier notice in the same procedure may well be there, under the same procedureId.
     * A caller may use the flag to choose what to enrich; the lookup itself answers what it is asked.
     */
    public function test_sent_to_ted_false_does_not_block_an_explicit_lookup(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->andReturn(['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]);

        $enriched = $this->service($client)->enrich($this->doffinNotice(['sentToTed' => false]));

        $this->assertSame(self::PROCEDURE, $enriched->identity->procedureIdentifier);
        // And the flag is still raw data, neither consumed nor rewritten.
        $this->assertFalse($enriched->rawPayload['sentToTed']);
    }

    // ------------------------------------------------------------------ what did not change

    /** Discovery reads the search endpoint. Enrichment is somewhere else, on purpose. */
    public function test_an_ordinary_doffin_search_asks_for_no_notice_details(): void
    {
        $client = $this->client();
        $client->shouldNotReceive('noticeDetail');
        $this->app->instance(DoffinPublicClient::class, $client);

        $liveSearch = Mockery::mock(DoffinLiveSearchService::class);
        $liveSearch->shouldReceive('search')->once()->andReturn([
            'ok' => true,
            'page' => 1,
            'perPage' => 15,
            'numHitsTotal' => 1,
            'numHitsAccessible' => 1,
            'items' => [['id' => '2026-113736', 'heading' => 'Årlig kontroll', 'status' => 'ACTIVE']],
        ]);

        $result = (new DoffinSourceAdapter($liveSearch))->search(new OpportunitySearchCriteria, 1, 15);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->notices);
        $this->assertFalse($result->notices[0]->identity->isKnown());
    }

    public function test_the_discovery_payload_is_unchanged_by_enrichment(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->andReturn(['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]);

        $notice = $this->doffinNotice();
        $enriched = $this->service($client)->enrich($notice);

        $this->assertTrue($enriched->identity->isKnown());
        $this->assertSame($notice->toDiscoveryPayload(), $enriched->toDiscoveryPayload());
        $this->assertArrayNotHasKey('identity', $enriched->toDiscoveryPayload());
    }

    /** Everything a notice was, it still is; only the answer to "who" was added. */
    public function test_enrichment_changes_nothing_but_the_identity(): void
    {
        $client = $this->client();
        $client->shouldReceive('noticeDetail')
            ->once()
            ->andReturn(['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]);

        $notice = $this->doffinNotice();
        $enriched = $this->service($client)->enrich($notice);

        $this->assertSame($notice->sourceKey, $enriched->sourceKey);
        $this->assertSame($notice->externalId, $enriched->externalId);
        $this->assertSame($notice->title, $enriched->title);
        $this->assertSame($notice->description, $enriched->description);
        $this->assertSame($notice->buyerName, $enriched->buyerName);
        $this->assertSame($notice->publicationDate, $enriched->publicationDate);
        $this->assertSame($notice->deadline, $enriched->deadline);
        $this->assertSame($notice->status, $enriched->status);
        $this->assertSame($notice->sourceUrl, $enriched->sourceUrl);
        $this->assertSame($notice->cpvCodes, $enriched->cpvCodes);
        $this->assertSame($notice->rawPayload, $enriched->rawPayload);
        // The notice handed in is untouched — enrichment answers with a copy.
        $this->assertFalse($notice->identity->isKnown());
        $this->assertNotSame($notice->identity, $enriched->identity);
        $this->assertInstanceOf(OpportunityNoticeIdentity::class, $enriched->identity);
    }
}
