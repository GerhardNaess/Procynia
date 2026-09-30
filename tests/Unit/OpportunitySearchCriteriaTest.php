<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunityStatus;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The search question, and its translation into one register's parameters.
 *
 * OpportunitySourceAdapter::search() used to take an untyped array, and that array was Doffin's:
 * `publication_period` counted days the way Doffin's API counts them, `keywords_mode` was its
 * spelling of all-or-any, `status` carried ACTIVE. The interface was source-neutral in its type
 * hints and Doffin-shaped in practice, so a second adapter would have had to implement Doffin's
 * parameter vocabulary just to be callable.
 *
 * These tests hold the two halves apart: what the criteria may contain, and what the Doffin
 * adapter turns it into. Nothing between them should have to know either.
 */
class OpportunitySearchCriteriaTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $captured */
    private function doffinFiltersFor(OpportunitySearchCriteria $criteria): array
    {
        $captured = [];
        $liveSearch = Mockery::mock(DoffinLiveSearchService::class);
        $liveSearch->shouldReceive('search')
            ->once()
            ->andReturnUsing(function (array $filters) use (&$captured): array {
                $captured = $filters;

                return ['ok' => true, 'items' => [], 'numHitsTotal' => 0, 'numHitsAccessible' => 0];
            });

        (new DoffinSourceAdapter($liveSearch))->search($criteria, 1, 15);

        return $captured;
    }

    // ------------------------------------------------------------------ the criteria

    /** Blank input is one question, however it was typed. */
    public function test_empty_text_becomes_null_rather_than_an_empty_string(): void
    {
        $criteria = OpportunitySearchCriteria::fromArray([
            'query' => '   ',
            'buyer_name' => '',
        ]);

        $this->assertNull($criteria->query);
        $this->assertNull($criteria->buyerName);
    }

    public function test_keywords_are_a_list_however_they_arrive(): void
    {
        $fromString = OpportunitySearchCriteria::fromArray(['keywords' => "renhold,  tingrett\nrenhold"]);
        $fromList = OpportunitySearchCriteria::fromArray(['keywords' => ['renhold', ' tingrett ', 'renhold']]);

        $this->assertSame(['renhold', 'tingrett'], $fromString->keywords);
        $this->assertSame(['renhold', 'tingrett'], $fromList->keywords);
    }

    /**
     * A CPV code is a number however it is written down, and it stays a separate code — joining
     * them is a wire format, and belongs to whoever owns the wire.
     *
     * This asserted that "90910000-9" became "909100009", which is what the code did and not what
     * anybody wanted: nine digits name no classification, so a register asked for one answered with
     * nothing. The check digit is dropped now, which makes the first and third entries the same
     * code — and therefore one entry.
     */
    public function test_cpv_codes_are_structured_and_reduced_to_the_classification(): void
    {
        $criteria = OpportunitySearchCriteria::fromArray([
            'cpv_codes' => '90910000-9, 72.222.300; 90910000',
        ]);

        $this->assertSame(['90910000', '72222300'], $criteria->cpvCodes);
    }

    /** A value that is not a code is left out rather than passed on to be asked about. */
    public function test_something_that_is_not_a_cpv_code_never_reaches_a_register(): void
    {
        $criteria = OpportunitySearchCriteria::fromArray([
            'cpv_codes' => '909, renhold, 90910000',
        ]);

        $this->assertSame(['90910000'], $criteria->cpvCodes);
    }

    public function test_a_rolling_window_must_be_a_positive_number_of_days(): void
    {
        $this->assertSame(7, OpportunitySearchCriteria::fromArray(['published_within_days' => '7'])->publishedWithinDays);
        $this->assertNull(OpportunitySearchCriteria::fromArray(['published_within_days' => '0'])->publishedWithinDays);
        $this->assertNull(OpportunitySearchCriteria::fromArray(['published_within_days' => ''])->publishedWithinDays);
    }

    /** The register's own word for a lifecycle stage is accepted, and immediately left behind. */
    public function test_a_legacy_status_string_maps_to_the_neutral_lifecycle(): void
    {
        $this->assertSame(OpportunityStatus::Open, OpportunityStatus::fromRequestValue('ACTIVE'));
        $this->assertSame(OpportunityStatus::Expired, OpportunityStatus::fromRequestValue('expired'));
        $this->assertSame(OpportunityStatus::Awarded, OpportunityStatus::fromRequestValue('AWARDED'));
        $this->assertSame(OpportunityStatus::Cancelled, OpportunityStatus::fromRequestValue('CANCELLED'));
        $this->assertNull(OpportunityStatus::fromRequestValue(''));
        $this->assertNull(OpportunityStatus::fromRequestValue('something-else'));
    }

    // ------------------------------------------------------------------ the translation

    public function test_the_adapter_translates_every_field_into_doffin_parameters(): void
    {
        $filters = $this->doffinFiltersFor(new OpportunitySearchCriteria(
            query: 'Domstoladministrasjonen',
            keywords: ['renhold', 'tingrett'],
            matchAllKeywords: false,
            buyerName: 'Oslo kommune',
            cpvCodes: ['90910000', '72222300'],
            status: OpportunityStatus::Open,
            publishedFrom: '2026-03-01',
            publishedTo: '2026-03-31',
            publishedWithinDays: 7,
        ));

        $this->assertSame([
            'q' => 'Domstoladministrasjonen',
            'organization_name' => 'Oslo kommune',
            'cpv' => '90910000,72222300',
            'keywords' => "renhold\ntingrett",
            'keywords_mode' => 'any',
            'publication_date_from' => '2026-03-01',
            'publication_date_to' => '2026-03-31',
            'publication_period' => '7',
            'status' => 'ACTIVE',
        ], $filters);
    }

    /** Nothing asked for is nothing sent — the register sees empty parameters, not invented ones. */
    public function test_an_empty_question_translates_to_empty_parameters(): void
    {
        $filters = $this->doffinFiltersFor(new OpportunitySearchCriteria);

        $this->assertSame([
            'q' => '',
            'organization_name' => '',
            'cpv' => '',
            'keywords' => '',
            'keywords_mode' => 'all',
            'publication_date_from' => '',
            'publication_date_to' => '',
            'publication_period' => '',
            'status' => '',
        ], $filters);
    }

    public function test_each_lifecycle_stage_has_a_doffin_spelling(): void
    {
        foreach ([
            [OpportunityStatus::Open, 'ACTIVE'],
            [OpportunityStatus::Expired, 'EXPIRED'],
            [OpportunityStatus::Awarded, 'AWARDED'],
            [OpportunityStatus::Cancelled, 'CANCELLED'],
        ] as [$status, $expected]) {
            $filters = $this->doffinFiltersFor(new OpportunitySearchCriteria(status: $status));

            $this->assertSame($expected, $filters['status'], $status->value);
        }
    }

    /**
     * Doffin only offers a fixed set of windows. One it does not offer is dropped rather than
     * rounded to a neighbour: quietly searching 30 days when 14 was asked for would be a wrong
     * answer presented as a right one.
     */
    public function test_a_window_doffin_does_not_offer_is_dropped_not_rounded(): void
    {
        foreach ([1, 7, 30, 90, 365] as $days) {
            $filters = $this->doffinFiltersFor(new OpportunitySearchCriteria(publishedWithinDays: $days));

            $this->assertSame((string) $days, $filters['publication_period']);
        }

        foreach ([2, 14, 45, 400] as $days) {
            $filters = $this->doffinFiltersFor(new OpportunitySearchCriteria(publishedWithinDays: $days));

            $this->assertSame('', $filters['publication_period'], "{$days} days is not a Doffin window");
        }
    }

    /**
     * The five keys that used to be passed and ignored: they filter saved cases inside Procynia
     * and never reached the register. The criteria have no way to express them, which is the
     * point — the boundary now states what it actually uses.
     */
    public function test_saved_case_filters_cannot_reach_the_register(): void
    {
        $filters = $this->doffinFiltersFor(new OpportunitySearchCriteria(query: 'noe'));

        foreach (['watch_list_id', 'relevance', 'bid_status', 'history_type', 'cockpit_scope'] as $key) {
            $this->assertArrayNotHasKey($key, $filters);
        }
    }
}
