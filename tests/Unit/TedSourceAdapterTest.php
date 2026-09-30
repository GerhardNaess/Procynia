<?php

namespace Tests\Unit;

use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunityStatus;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedSourceAdapter;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * TED as a second opportunity source.
 *
 * The first real test of whether the preceding phases bought anything: nothing above this adapter
 * changed to accommodate it. It answers the same OpportunitySearchCriteria and returns the same
 * NormalizedNotice, so the registry, the controller and every saved-case path carry on unchanged.
 *
 * The fixtures are real TED responses, trimmed. The API contract they encode was verified against
 * the live endpoint on 27 September 2026 — POST /v3/notices/search, `query` and `fields` both
 * required, multilingual title and buyer maps, deadlines as a list, and no status field at all.
 * Nothing here touches the network.
 */
class TedSourceAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, mixed> A real TED notice, trimmed to the fields Procynia reads. */
    private function tedHit(array $overrides = []): array
    {
        return array_merge([
            'publication-number' => '337011-2026',
            'notice-type' => 'cn-standard',
            'notice-title' => [
                'pol' => 'Zakup subskrypcji',
                'eng' => 'Purchase of subscriptions',
            ],
            'description-proc' => [
                'eng' => '  Supply of   licences and support  ',
            ],
            // A list even when there is one buyer.
            'buyer-name' => ['eng' => ['Oslo kommune']],
            'publication-date' => '2026-01-02+01:00',
            // One per lot.
            'deadline-receipt-request' => ['2026-03-01T00:00:00+01:00', '2026-02-01T12:00:00+01:00'],
            'classification-cpv' => ['72000000', '72000000', '48000000'],
            'links' => [
                'html' => ['ENG' => 'https://ted.europa.eu/en/notice/-/detail/337011-2026'],
            ],
        ], $overrides);
    }

    private function adapterCapturing(?array &$captured, array $response = []): TedSourceAdapter
    {
        $client = Mockery::mock(TedSearchClient::class);
        $client->shouldReceive('search')
            ->andReturnUsing(function (string $query, array $fields, int $page, int $perPage) use (&$captured, $response): array {
                $captured = ['query' => $query, 'fields' => $fields, 'page' => $page, 'perPage' => $perPage];

                return array_merge([
                    'ok' => true,
                    'items' => [],
                    'page' => $page,
                    'perPage' => $perPage,
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'fallback_used' => false,
                ], $response);
            });

        return new TedSourceAdapter($client);
    }

    private function queryFor(OpportunitySearchCriteria $criteria): string
    {
        $captured = null;
        $this->adapterCapturing($captured)->search($criteria, 1, 15);

        return $captured['query'];
    }

    private function normalize(array $hit): ?NormalizedNotice
    {
        return $this->adapterCapturing($ignored)->normalizeLiveSearchHit($hit);
    }

    // ------------------------------------------------------------------- identity

    public function test_the_source_key_is_stable_and_explicit(): void
    {
        $this->assertSame('ted', TedSourceAdapter::SOURCE_KEY);
        $this->assertSame('ted', $this->adapterCapturing($ignored)->sourceKey());
    }

    public function test_the_public_notice_url_is_built_from_the_publication_number(): void
    {
        $adapter = $this->adapterCapturing($ignored);

        $this->assertSame('https://ted.europa.eu/en/notice/-/detail/337011-2026', $adapter->sourceUrl('337011-2026'));
        $this->assertNull($adapter->sourceUrl('  '));
    }

    // ------------------------------------------------- criteria to TED expert query

    public function test_free_text_and_buyer_become_ted_search_clauses(): void
    {
        $query = $this->queryFor(new OpportunitySearchCriteria(
            query: 'renhold',
            buyerName: 'Oslo kommune',
        ));

        $this->assertStringContainsString('FT~"renhold"', $query);
        $this->assertStringContainsString('buyer-name~"Oslo kommune"', $query);
        $this->assertStringContainsString(' AND ', $query);
    }

    /** TED takes a list, so the structured codes go in as a list rather than a joined string. */
    public function test_cpv_codes_become_an_in_list(): void
    {
        $query = $this->queryFor(new OpportunitySearchCriteria(cpvCodes: ['72000000', '48000000']));

        $this->assertStringContainsString('classification-cpv IN (72000000 48000000)', $query);
    }

    /**
     * TED classifies by the eight digits, so that is what it is asked about.
     *
     * The adapter does no normalising of its own — criteria arrive normalised, which is why a code
     * written with its check digit anywhere upstream reaches the query as the classification rather
     * than as a nine-digit string TED has no notices under.
     */
    public function test_a_written_check_digit_never_reaches_the_query(): void
    {
        $query = $this->queryFor(OpportunitySearchCriteria::fromArray([
            'cpv_codes' => ['72000000-5', '48.000.000'],
        ]));

        $this->assertStringContainsString('classification-cpv IN (72000000 48000000)', $query);
    }

    public function test_keywords_honour_all_or_any(): void
    {
        $all = $this->queryFor(new OpportunitySearchCriteria(keywords: ['renhold', 'vask'], matchAllKeywords: true));
        $any = $this->queryFor(new OpportunitySearchCriteria(keywords: ['renhold', 'vask'], matchAllKeywords: false));

        $this->assertStringContainsString('(FT~"renhold" AND FT~"vask")', $all);
        $this->assertStringContainsString('(FT~"renhold" OR FT~"vask")', $any);
    }

    public function test_a_single_keyword_needs_no_grouping(): void
    {
        $this->assertStringContainsString('FT~"renhold"', $this->queryFor(new OpportunitySearchCriteria(keywords: ['renhold'])));
        $this->assertStringNotContainsString('(FT~', $this->queryFor(new OpportunitySearchCriteria(keywords: ['renhold'])));
    }

    /** TED wants YYYYMMDD and has no rolling window, so the window becomes the range it means. */
    public function test_dates_are_translated_and_a_window_becomes_a_range(): void
    {
        Carbon::setTestNow('2026-03-15 09:00:00');

        $explicit = $this->queryFor(new OpportunitySearchCriteria(
            publishedFrom: '2026-01-01',
            publishedTo: '2026-01-31',
        ));
        $window = $this->queryFor(new OpportunitySearchCriteria(publishedWithinDays: 7));

        Carbon::setTestNow();

        $this->assertStringContainsString('publication-date>=20260101', $explicit);
        $this->assertStringContainsString('publication-date<=20260131', $explicit);
        $this->assertStringContainsString('publication-date>=20260308', $window);
    }

    /**
     * An explicit range wins over a window, the same precedence Doffin's client applies — asking
     * for both is asking for the range, and silently widening it would search the wrong period.
     */
    public function test_an_explicit_range_wins_over_a_rolling_window(): void
    {
        $query = $this->queryFor(new OpportunitySearchCriteria(
            publishedFrom: '2026-01-01',
            publishedWithinDays: 365,
        ));

        $this->assertStringContainsString('publication-date>=20260101', $query);
        $this->assertStringNotContainsString('20250', $query);
    }

    /**
     * TED rejects an empty query outright, and "every notice the EU has ever published" is not
     * what an empty form means.
     */
    public function test_an_empty_search_is_narrowed_rather_than_rejected(): void
    {
        Carbon::setTestNow('2026-03-15 09:00:00');
        $query = $this->queryFor(new OpportunitySearchCriteria);
        Carbon::setTestNow();

        $this->assertSame('publication-date>=20260308', $query);
    }

    /** A quote inside a term would close the clause early and change what is searched for. */
    public function test_a_quote_in_a_term_cannot_break_out_of_the_clause(): void
    {
        $query = $this->queryFor(new OpportunitySearchCriteria(query: 'drift "og" vedlikehold'));

        $this->assertSame('FT~"drift  og  vedlikehold"', $query);
        $this->assertSame(2, substr_count($query, '"'));
    }

    public function test_the_configured_field_list_is_what_is_requested(): void
    {
        $captured = null;
        $this->adapterCapturing($captured)->search(new OpportunitySearchCriteria, 2, 25);

        $this->assertSame(config('ted.search_fields'), $captured['fields']);
        $this->assertSame(2, $captured['page']);
        $this->assertSame(25, $captured['perPage']);
    }

    /**
     * TED returns nothing it was not asked for, so a notice's identity has to be requested.
     *
     * Asserted against the wire rather than against config, because config being right is not the
     * same as the request carrying it — and a field quietly dropped here would not fail anything
     * else: every notice would simply report an unknown procurement, which reads exactly like a
     * register that does not publish one.
     */
    public function test_the_identity_fields_are_among_the_ones_asked_for(): void
    {
        $captured = null;
        $this->adapterCapturing($captured)->search(new OpportunitySearchCriteria, 1, 15);

        $this->assertContains('procedure-identifier', $captured['fields']);
        $this->assertContains('notice-identifier', $captured['fields']);
    }

    // --------------------------------------------------- TED result to NormalizedNotice

    public function test_a_ted_notice_becomes_a_normalized_notice(): void
    {
        $notice = $this->normalize($this->tedHit());

        $this->assertSame('ted', $notice->sourceKey);
        $this->assertSame('337011-2026', $notice->externalId);
        $this->assertSame('Purchase of subscriptions', $notice->title, 'English is preferred');
        $this->assertSame('Supply of licences and support', $notice->description, 'and squished');
        $this->assertSame('Oslo kommune', $notice->buyerName, 'even though TED sends a list');
        $this->assertSame('2026-01-02', $notice->publicationDate, 'the offset is dropped');
        $this->assertSame(['72000000', '48000000'], $notice->cpvCodes, 'deduplicated');
        $this->assertSame('https://ted.europa.eu/en/notice/-/detail/337011-2026', $notice->sourceUrl);
    }

    /** One deadline per lot; the earliest is the one that actually constrains a bidder. */
    public function test_the_earliest_lot_deadline_is_the_deadline(): void
    {
        $this->assertSame('2026-02-01', $this->normalize($this->tedHit())->deadline);
    }

    /** A notice with no publication number cannot be identified, stored or reopened. */
    public function test_a_notice_without_a_publication_number_is_dropped(): void
    {
        $this->assertNull($this->normalize($this->tedHit(['publication-number' => '  '])));
    }

    /** Procynia has one title field; an untranslated notice is better than a blank card. */
    public function test_a_notice_with_no_english_falls_back_to_what_it_has(): void
    {
        $notice = $this->normalize($this->tedHit([
            'notice-title' => ['pol' => 'Zakup subskrypcji'],
            'buyer-name' => ['pol' => ['Szpital Kliniczny']],
        ]));

        $this->assertSame('Zakup subskrypcji', $notice->title);
        $this->assertSame('Szpital Kliniczny', $notice->buyerName);
    }

    /** The register's own link is better than a rebuilt one: it keeps working if TED moves. */
    public function test_teds_own_link_is_preferred_over_a_rebuilt_one(): void
    {
        $notice = $this->normalize($this->tedHit([
            'links' => ['htmlDirect' => ['ENG' => 'https://ted.europa.eu/en/notice/337011-2026/direct']],
        ]));

        $this->assertSame('https://ted.europa.eu/en/notice/337011-2026/direct', $notice->sourceUrl);
    }

    public function test_a_notice_without_links_falls_back_to_the_canonical_url(): void
    {
        $notice = $this->normalize($this->tedHit(['links' => []]));

        $this->assertSame('https://ted.europa.eu/en/notice/-/detail/337011-2026', $notice->sourceUrl);
    }

    // ------------------------------------------------------------------- status

    /**
     * TED has no status field — the API rejects `notice-status`. What it has is the kind of
     * document, and two kinds genuinely settle the question.
     */
    public function test_notice_type_is_read_as_the_lifecycle_it_implies(): void
    {
        foreach (['cn-standard', 'cn-social', 'CN-Standard'] as $type) {
            $this->assertSame(OpportunityStatus::Open, $this->normalize($this->tedHit(['notice-type' => $type]))->status, $type);
        }

        foreach (['can-standard', 'can-social', 'can-tran'] as $type) {
            $this->assertSame(OpportunityStatus::Awarded, $this->normalize($this->tedHit(['notice-type' => $type]))->status, $type);
        }
    }

    /**
     * A prior information notice announces something not yet open for offers. It is not Open, and
     * it is not any of the other three either — so it is null rather than the friendliest guess.
     */
    public function test_a_prior_information_notice_is_not_open(): void
    {
        $notice = $this->normalize($this->tedHit(['notice-type' => 'pin-only']));

        $this->assertNull($notice->status);
        $this->assertNotSame(OpportunityStatus::Open, $notice->status);
    }

    public function test_an_unknown_or_missing_notice_type_is_never_open(): void
    {
        foreach (['something-new', '', '   '] as $type) {
            $this->assertNull($this->normalize($this->tedHit(['notice-type' => $type]))->status, var_export($type, true));
        }

        $this->assertNull($this->normalize($this->tedHit(['notice-type' => null]))->status);
    }

    /** Asking TED for a lifecycle stage, in the only terms TED offers. */
    public function test_a_status_filter_becomes_a_notice_type_clause(): void
    {
        $this->assertStringContainsString('notice-type~"cn-"', $this->queryFor(new OpportunitySearchCriteria(status: OpportunityStatus::Open)));
        $this->assertStringContainsString('notice-type~"can-"', $this->queryFor(new OpportunitySearchCriteria(status: OpportunityStatus::Awarded)));
    }

    /**
     * TED records the call and the award, not the silence between them. Filtering for a stage it
     * cannot express returns nothing rather than returning live procurements instead.
     */
    public function test_a_stage_ted_cannot_express_returns_nothing_rather_than_the_wrong_thing(): void
    {
        foreach ([OpportunityStatus::Expired, OpportunityStatus::Cancelled] as $status) {
            $query = $this->queryFor(new OpportunitySearchCriteria(status: $status));

            $this->assertStringNotContainsString('notice-type~"cn-"', $query, $status->value);
            $this->assertStringNotContainsString('notice-type~"can-"', $query, $status->value);
        }
    }

    // ---------------------------------------------------------------- the payload

    /**
     * The contract every consumer above already reads. A TED result has to arrive in the same
     * shape as a Doffin one or the discovery page would need a second renderer.
     */
    public function test_a_ted_result_produces_the_existing_discovery_payload(): void
    {
        $payload = $this->normalize($this->tedHit())->toDiscoveryPayload();

        $this->assertSame([
            'id', 'notice_id', 'title', 'buyer_name', 'summary', 'publication_date', 'deadline',
            'status', 'relevance_level', 'score', 'department', 'saved_search_name', 'cpv_code',
            'is_new', 'external_url', 'is_saved', 'is_in_history',
        ], array_keys($payload));

        $this->assertSame('337011-2026', $payload['notice_id']);
        $this->assertSame('Purchase of subscriptions', $payload['title']);
        $this->assertSame('72000000', $payload['cpv_code']);
        $this->assertSame('2026-02-01', $payload['deadline']);
    }

    /**
     * A TED card carries no status badge, and should not.
     *
     * The badge prints the register's own status word verbatim. TED publishes no such word — the
     * closest thing it has is the document kind, and "cn-standard" on a card would be an internal
     * code shown to a bid manager as if it meant something. With the label absent the card falls
     * through to the deadline, which is what a reader of a live call actually wants to know.
     *
     * The lifecycle is still known; it just travels typed in $status rather than as display text.
     */
    public function test_a_ted_card_shows_no_status_badge_and_falls_back_to_the_deadline(): void
    {
        $notice = $this->normalize($this->tedHit());

        $this->assertNull($notice->toDiscoveryPayload()['status']);
        $this->assertNull($notice->providerStatusLabel());
        $this->assertSame(OpportunityStatus::Open, $notice->status, 'the meaning is not lost');
        $this->assertSame('2026-02-01', $notice->toDiscoveryPayload()['deadline'], 'which the card uses instead');
    }

    // ----------------------------------------------------------------- failures

    public function test_a_refused_search_is_reported_rather_than_thrown(): void
    {
        $client = Mockery::mock(TedSearchClient::class);
        $client->shouldReceive('search')->andReturn([
            'ok' => false,
            'items' => [],
            'page' => 1,
            'perPage' => 15,
            'error_type' => 'invalid_request',
            'error_message' => 'Unknown search field',
            'upstream_status' => 400,
        ]);

        $result = (new TedSourceAdapter($client))->search(new OpportunitySearchCriteria, 1, 15);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_request', $result->errorType);
        $this->assertSame(400, $result->upstreamStatus);
        $this->assertStringContainsString('TED', $result->userMessage);
        $this->assertSame([], $result->notices);
    }

    /** TED answers a partial result when its own search runs long, and says so. */
    public function test_a_timed_out_search_is_marked_as_a_partial_answer(): void
    {
        $captured = null;
        $adapter = $this->adapterCapturing($captured, [
            'items' => [$this->tedHit()],
            'numHitsTotal' => 4200,
            'numHitsAccessible' => 4200,
            'fallback_used' => true,
        ]);

        $result = $adapter->search(new OpportunitySearchCriteria, 1, 15);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->fallbackUsed);
        $this->assertSame(4200, $result->numHitsTotal);
        $this->assertCount(1, $result->notices);
    }

    public function test_an_unusable_hit_is_skipped_rather_than_failing_the_page(): void
    {
        $adapter = $this->adapterCapturing($ignored, [
            'items' => [$this->tedHit(), ['publication-number' => ''], 'not-an-array'],
            'numHitsTotal' => 3,
        ]);

        $this->assertCount(1, $adapter->search(new OpportunitySearchCriteria, 1, 15)->notices);
    }
}
