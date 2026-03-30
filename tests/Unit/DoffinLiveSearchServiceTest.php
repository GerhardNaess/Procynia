<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinLiveSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoffinLiveSearchServiceTest extends TestCase
{
    public function test_it_returns_live_doffin_hits_for_domstoladministrasjonen(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => null,
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Domstoladministrasjonen',
            'organization_name' => '',
            'publication_period' => '',
        ], 1, 15);

        $this->assertSame(1, $result['numHitsAccessible']);
        $this->assertSame('2026-105164', $result['hits'][0]['id']);
        $this->assertSame('Domstoladministrasjonen', $result['hits'][0]['buyer'][0]['name']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.doffin.no/webclient/api/v2/search-api/search'
                && $request->method() === 'POST'
                && $request['searchString'] === 'Domstoladministrasjonen'
                && $request['numHitsPerPage'] === 15
                && $request['page'] === 1;
        });
    }

    public function test_it_does_not_short_circuit_when_organization_filter_cannot_be_resolved(): void
    {
        Http::fake(function ($request) {
            if ($request['searchString'] === 'Domstoladministrasjonen') {
                return Http::response([
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'hits' => [],
                ], 200);
            }

            if ($request['searchString'] === 'Renhold') {
                return Http::response([
                    'numHitsTotal' => 1,
                    'numHitsAccessible' => 1,
                    'hits' => [
                        [
                            'id' => '2026-105164',
                            'buyer' => [
                                [
                                    'id' => 'e7c38cb469460081ad1de749d4670c71',
                                    'organizationId' => '984195796',
                                    'name' => 'Domstoladministrasjonen',
                                ],
                            ],
                            'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                            'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                            'status' => null,
                            'publicationDate' => '2026-03-16',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 500);
        });

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => 'Domstoladministrasjonen',
            'publication_period' => '',
        ], 1, 15);

        $this->assertSame(1, $result['numHitsAccessible']);
        $this->assertSame('2026-105164', $result['hits'][0]['id']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Renhold'
                && $request['facets']['buyer']['checkedItems'] === [];
        });
    }

    public function test_it_uses_organization_name_as_search_string_when_primary_query_is_empty(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::sequence()
                ->push([
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'hits' => [],
                ], 200)
                ->push([
                    'numHitsTotal' => 1,
                    'numHitsAccessible' => 1,
                    'hits' => [
                        [
                            'id' => '2026-105164',
                            'buyer' => [
                                [
                                    'id' => 'e7c38cb469460081ad1de749d4670c71',
                                    'organizationId' => '984195796',
                                    'name' => 'Domstoladministrasjonen',
                                ],
                            ],
                            'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                            'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                            'status' => null,
                            'publicationDate' => '2026-03-16',
                            'deadline' => null,
                        ],
                    ],
                ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'organization_name' => 'Domstoladministrasjonen',
            'publication_period' => '',
        ], 1, 15);

        $this->assertSame(1, $result['numHitsAccessible']);
        $this->assertSame('2026-105164', $result['hits'][0]['id']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Domstoladministrasjonen'
                && $request['numHitsPerPage'] === 15
                && $request['page'] === 1;
        });
    }

    public function test_it_maps_cpv_and_status_filters_to_doffin_facets(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Domstoladministrasjonen',
            'organization_name' => '',
            'cpv' => '90910000, 90911000',
            'publication_period' => '30',
            'status' => 'active',
        ], 1, 15);

        $this->assertSame(1, $result['numHitsAccessible']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Domstoladministrasjonen'
                && $request['facets']['cpvCodesId']['checkedItems'] === ['90910000', '90911000']
                && $request['facets']['status']['checkedItems'] === ['ACTIVE']
                && $request['facets']['publicationDate']['from'] !== null
                && $request['facets']['publicationDate']['to'] !== null;
        });
    }

    public function test_it_merges_keywords_into_the_primary_search_string_without_replacing_q(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        app(DoffinLiveSearchService::class)->search([
            'q' => 'sjøfart',
            'keywords' => 'havn, ferge, havn',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'sjøfart havn ferge';
        });
    }

    public function test_empty_keywords_do_not_change_the_primary_search_string(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        app(DoffinLiveSearchService::class)->search([
            'q' => 'sjøfart',
            'keywords' => '   ',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'sjøfart';
        });
    }

    public function test_it_supports_365_days_as_a_publication_period(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        app(DoffinLiveSearchService::class)->search([
            'q' => 'Domstoladministrasjonen',
            'keywords' => '',
            'organization_name' => '',
            'cpv' => '',
            'publication_period' => '365',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Domstoladministrasjonen'
                && $request['facets']['publicationDate']['from'] !== null
                && $request['facets']['publicationDate']['to'] !== null;
        });
    }

    public function test_it_supports_1_day_as_a_publication_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-29 12:00:00'));

        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'heading' => 'Recent notice',
                        'publicationDate' => '2026-03-29T08:30:00',
                    ],
                ],
            ], 200),
        ]);

        try {
            app(DoffinLiveSearchService::class)->search([
                'q' => 'recent',
                'keywords' => '',
                'organization_name' => '',
                'cpv' => '',
                'publication_period' => '1',
                'status' => 'ACTIVE',
            ], 1, 15);

            Http::assertSentCount(1);
            Http::assertSent(function ($request): bool {
                return $request['searchString'] === 'recent'
                    && $request['facets']['status']['checkedItems'] === ['ACTIVE']
                    && $request['facets']['publicationDate']['from'] === '2026-03-28'
                    && $request['facets']['publicationDate']['to'] === '2026-03-29';
            });
        } finally {
            Carbon::setTestNow();
        }
    }
}
