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
                && ! isset($request['facets']);
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

    public function test_it_keeps_keywords_out_of_the_organization_name_search_string(): void
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
                    'hits' => [],
                ], 200),
        ]);

        app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'renhold, tingrett',
            'organization_name' => 'Domstoladministrasjonen',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Domstoladministrasjonen';
        });
    }

    public function test_it_uses_the_primary_query_without_keywords_in_the_search_string(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 0,
                'numHitsAccessible' => 0,
                'hits' => [],
            ], 200),
        ]);

        app(DoffinLiveSearchService::class)->search([
            'q' => 'helse nord',
            'keywords' => 'pasvik',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'helse nord';
        });
    }

    public function test_it_uses_direct_publication_date_range_filters_when_present(): void
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
            'publication_date_from' => '2026-03-01',
            'publication_date_to' => '2026-03-31',
            'publication_period' => '365',
            'status' => 'active',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Domstoladministrasjonen'
                && $request['facets']['publicationDate']['from'] === '2026-03-01'
                && $request['facets']['publicationDate']['to'] === '2026-03-31'
                && $request['facets']['status']['checkedItems'] === ['ACTIVE'];
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

    public function test_it_maps_the_combined_live_search_request_to_text_and_structured_filters_and_harvests_keyword_pages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 12:00:00'));

        Http::fake(function ($request) {
            if ($request['searchString'] === 'Domstoladministrasjonen') {
                return Http::response([
                    'numHitsTotal' => 1,
                    'numHitsAccessible' => 1,
                    'hits' => [
                        [
                            'id' => 'buyer-resolve',
                            'buyer' => [
                                [
                                    'id' => 'buyer-1',
                                    'organizationId' => '984195796',
                                    'name' => 'Domstoladministrasjonen',
                                ],
                            ],
                            'heading' => 'Buyer lookup',
                            'description' => '',
                            'status' => null,
                            'publicationDate' => null,
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            if ($request['searchString'] === 'helse nord' && (int) ($request['page'] ?? 1) === 1) {
                return Http::response([
                    'numHitsTotal' => 4,
                    'numHitsAccessible' => 4,
                    'hits' => [
                        [
                            'id' => 'hit-a',
                            'buyer' => [
                                [
                                    'id' => 'buyer-1',
                                    'organizationId' => '123456789',
                                    'name' => 'Helse Nord RHF',
                                ],
                            ],
                            'heading' => 'Anskaffelse pasienttransport i anbudsområdene Hasvik, Vardø, Neiden og Bugøynes',
                            'description' => 'Transport i helsesektoren.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-20',
                            'deadline' => null,
                        ],
                        [
                            'id' => 'hit-b',
                            'buyer' => [
                                [
                                    'id' => 'buyer-1',
                                    'organizationId' => '123456789',
                                    'name' => 'Helse Nord RHF',
                                ],
                            ],
                            'heading' => 'Anskaffelse pasienttransport i anbudsområdene Alta og Hammerfest',
                            'description' => 'Transport i helsesektoren.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-20',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            if ($request['searchString'] === 'helse nord' && (int) ($request['page'] ?? 1) === 2) {
                return Http::response([
                    'numHitsTotal' => 4,
                    'numHitsAccessible' => 4,
                    'hits' => [
                        [
                            'id' => 'hit-c',
                            'buyer' => [
                                [
                                    'id' => 'buyer-2',
                                    'organizationId' => '999999999',
                                    'name' => 'Pasvik Transport AS',
                                ],
                            ],
                            'heading' => 'Kjøreoppdrag i Pasvik',
                            'description' => 'Transporttjenester.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-20',
                            'deadline' => null,
                        ],
                        [
                            'id' => 'hit-d',
                            'buyer' => [
                                [
                                    'id' => 'buyer-1',
                                    'organizationId' => '123456789',
                                    'name' => 'Helse Nord RHF',
                                ],
                            ],
                            'heading' => 'Anskaffelse pasienttransport i anbudsområdene Hasvik, Vardø, Neiden og Bugøynes',
                            'description' => 'Transport i helsesektoren.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-20',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 500);
        });

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'helse nord',
            'keywords' => 'pasvik',
            'organization_name' => 'Domstoladministrasjonen',
            'cpv' => '90910000',
            'publication_period' => '30',
            'status' => 'active',
        ], 1, 2);

        Carbon::setTestNow();

        Http::assertSentCount(3);

        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'Domstoladministrasjonen'
                && ! isset($request['facets']);
        });

        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'helse nord'
                && ($request['page'] ?? null) === 1
                && $request['facets']['buyer']['checkedItems'] === ['buyer-1']
                && $request['facets']['cpvCodesId']['checkedItems'] === ['90910000']
                && $request['facets']['status']['checkedItems'] === ['ACTIVE']
                && $request['facets']['publicationDate']['from'] === '2026-03-06'
                && $request['facets']['publicationDate']['to'] === '2026-04-05';
        });

        Http::assertSent(function ($request): bool {
            return $request['searchString'] === 'helse nord'
                && ($request['page'] ?? null) === 2
                && $request['facets']['buyer']['checkedItems'] === ['buyer-1']
                && $request['facets']['cpvCodesId']['checkedItems'] === ['90910000']
                && $request['facets']['status']['checkedItems'] === ['ACTIVE']
                && $request['facets']['publicationDate']['from'] === '2026-03-06'
                && $request['facets']['publicationDate']['to'] === '2026-04-05';
        });

        $filteredIds = array_values(array_map(static fn (array $hit): string => $hit['id'], $result['hits']));

        $this->assertSame(['hit-c'], $filteredIds);
        $this->assertSame(1, $result['page']);
        $this->assertSame(2, $result['perPage']);
        $this->assertSame(1, $result['numHitsTotal']);
        $this->assertSame(1, $result['numHitsAccessible']);
    }

    public function test_it_filters_hits_locally_when_only_keywords_are_provided(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 2,
                'numHitsAccessible' => 2,
                'hits' => [
                    [
                        'id' => 'hit-keep',
                        'buyer' => [
                            [
                                'id' => 'buyer-1',
                                'organizationId' => '123456789',
                                'name' => 'Kystverket',
                            ],
                        ],
                        'heading' => 'Transportanskaffelse i nord',
                        'description' => 'Pasvik er nevnt i leveranseområdet.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                    [
                        'id' => 'hit-drop',
                        'buyer' => [
                            [
                                'id' => 'buyer-2',
                                'organizationId' => '987654321',
                                'name' => 'Kystverket',
                            ],
                        ],
                        'heading' => 'Transportanskaffelse i nord',
                        'description' => 'Ingen keyword-match her.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'pasvik',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return ! isset($request['searchString'])
                && ! isset($request['facets']);
        });

        $filteredIds = array_values(array_map(static fn (array $hit): string => $hit['id'], $result['hits']));

        $this->assertSame(['hit-keep'], $filteredIds);
    }

    public function test_it_harvests_keyword_pages_so_relevant_soc_hits_are_not_lost_on_later_doffin_pages(): void
    {
        $pageTwoRequested = false;

        Http::fake(function ($request) use (&$pageTwoRequested) {
            $page = (int) ($request['page'] ?? 1);
            $statusItems = $request['facets']['status']['checkedItems'] ?? [];

            if ($statusItems === [] && $page === 1) {
                return Http::response([
                    'numHitsTotal' => 30,
                    'numHitsAccessible' => 30,
                    'hits' => [
                        [
                            'id' => 'all-page-1',
                            'buyer' => [
                                [
                                    'id' => 'buyer-all',
                                    'organizationId' => '123456789',
                                    'name' => 'Trondheim kommune',
                                ],
                            ],
                            'heading' => 'Digitalt reguleringsverktøy for delt mikromobilitet i Trondheim',
                            'description' => 'Anskaffelse av et reguleringsverktøy.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-27',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            if ($statusItems === [] && $page === 2) {
                $pageTwoRequested = true;

                return Http::response([
                    'numHitsTotal' => 30,
                    'numHitsAccessible' => 30,
                    'hits' => [
                        [
                            'id' => 'nrk-page-2',
                            'buyer' => [
                                [
                                    'id' => 'buyer-nrk',
                                    'organizationId' => '984760967',
                                    'name' => 'Norsk rikskringkasting AS',
                                ],
                            ],
                            'heading' => 'NRK 2026 - 41 SOC-tjenester',
                            'description' => 'NRK søker en strategisk sikkerhetspartner for en døgnkontinuerlig SOC-tjeneste.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-04',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            if ($statusItems === ['EXPIRED'] && $page === 1) {
                return Http::response([
                    'numHitsTotal' => 1,
                    'numHitsAccessible' => 1,
                    'hits' => [
                        [
                            'id' => 'nrk-page-1',
                            'buyer' => [
                                [
                                    'id' => 'buyer-nrk',
                                    'organizationId' => '984760967',
                                    'name' => 'Norsk rikskringkasting AS',
                                ],
                            ],
                            'heading' => 'NRK 2026 - 41 SOC-tjenester',
                            'description' => 'NRK søker en strategisk sikkerhetspartner for en døgnkontinuerlig SOC-tjeneste.',
                            'status' => 'EXPIRED',
                            'publicationDate' => '2026-03-04',
                            'deadline' => null,
                        ],
                        [
                            'id' => 'expired-page-1-no-match',
                            'buyer' => [
                                [
                                    'id' => 'buyer-other',
                                    'organizationId' => '111111111',
                                    'name' => 'Vestland fylkeskommune',
                                ],
                            ],
                            'heading' => 'Utgått anskaffelse uten keyword match',
                            'description' => 'Ingen relevant tekst her.',
                            'status' => 'EXPIRED',
                            'publicationDate' => '2026-03-04',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 500);
        });

        $allStatusesResult = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'soc',
            'organization_name' => '',
            'cpv' => '32412100,32412110,32412120,32424000,48000000,64200000,72000000',
            'publication_period' => '365',
            'status' => '',
        ], 1, 15);

        $expiredResult = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'soc',
            'organization_name' => '',
            'cpv' => '32412100,32412110,32412120,32424000,48000000,72000000',
            'publication_period' => '365',
            'status' => 'expired',
        ], 1, 15);

        $this->assertTrue($pageTwoRequested, 'Procynia should harvest page 2 when keywords are present.');

        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            return ($request['page'] ?? null) === 1
                && ($request['sortBy'] ?? null) === 'RELEVANCE'
                && ($request['numHitsPerPage'] ?? null) === 15
                && ($request['facets']['status']['checkedItems'] ?? []) === [];
        });
        Http::assertSent(function ($request): bool {
            return ($request['page'] ?? null) === 1
                && ($request['sortBy'] ?? null) === 'RELEVANCE'
                && ($request['numHitsPerPage'] ?? null) === 15
                && ($request['facets']['status']['checkedItems'] ?? []) === ['EXPIRED'];
        });

        $this->assertSame(['nrk-page-2'], array_map(static fn (array $hit): string => $hit['id'], $allStatusesResult['hits']));
        $this->assertSame(['nrk-page-1'], array_map(static fn (array $hit): string => $hit['id'], $expiredResult['hits']));
    }

    public function test_it_paginates_filtered_keyword_hits_locally_after_harvesting_all_keyword_pages(): void
    {
        Http::fake(function ($request) {
            $page = (int) ($request['page'] ?? 1);

            if ($page === 1) {
                return Http::response([
                    'numHitsTotal' => 2,
                    'numHitsAccessible' => 2,
                    'hits' => [
                        [
                            'id' => 'keep-1',
                            'buyer' => [
                                [
                                    'id' => 'buyer-1',
                                    'organizationId' => '123456789',
                                    'name' => 'Kystverket',
                                ],
                            ],
                            'heading' => 'Pasvik sikkerhetstiltak',
                            'description' => 'Første treff i keyword-settet.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-16',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            if ($page === 2) {
                return Http::response([
                    'numHitsTotal' => 2,
                    'numHitsAccessible' => 2,
                    'hits' => [
                        [
                            'id' => 'keep-2',
                            'buyer' => [
                                [
                                    'id' => 'buyer-2',
                                    'organizationId' => '987654321',
                                    'name' => 'Kystverket',
                                ],
                            ],
                            'heading' => 'Oppfølging av SOC-beredskap',
                            'description' => 'Pasvik er omtalt i beskrivelser av leveransen.',
                            'status' => 'ACTIVE',
                            'publicationDate' => '2026-03-16',
                            'deadline' => null,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 500);
        });

        $pageOneResult = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'pasvik',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 1);

        $pageTwoResult = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'pasvik',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 2, 1);

        Http::assertSentCount(4);

        $this->assertSame(1, $pageOneResult['page']);
        $this->assertSame(2, $pageTwoResult['page']);
        $this->assertSame(2, $pageOneResult['numHitsTotal']);
        $this->assertSame(2, $pageOneResult['numHitsAccessible']);
        $this->assertSame(['keep-1'], array_map(static fn (array $hit): string => $hit['id'], $pageOneResult['hits']));
        $this->assertSame(['keep-2'], array_map(static fn (array $hit): string => $hit['id'], $pageTwoResult['hits']));
    }

    public function test_it_uses_a_single_keyword_only_for_local_filtering_when_no_primary_query_is_provided(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => 'hit-keep',
                        'buyer' => [
                            [
                                'id' => 'buyer-1',
                                'organizationId' => '123456789',
                                'name' => 'Kystverket',
                            ],
                        ],
                        'heading' => 'Transportanskaffelse i nord',
                        'description' => 'Pasvik er nevnt i leveranseområdet.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'ferge',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return ! isset($request['searchString'])
                && ! isset($request['facets']);
        });

        $this->assertSame([], $result['hits']);
    }

    public function test_it_uses_all_meaningful_keywords_only_for_local_filtering_when_no_primary_query_is_provided(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => 'hit-keep',
                        'buyer' => [
                            [
                                'id' => 'buyer-1',
                                'organizationId' => '123456789',
                                'name' => 'Kystverket',
                            ],
                        ],
                        'heading' => 'Havn og ferge i sourcing partner-sammenheng',
                        'description' => 'Her er alle keywords til stede.',
                        'status' => 'ACTIVE',
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => '',
            'keywords' => 'havn, ferge, sourcing partner',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return ! isset($request['searchString'])
                && ! isset($request['facets']);
        });

        $this->assertSame(['hit-keep'], array_values(array_map(static fn (array $hit): string => $hit['id'], $result['hits'])));
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

    public function test_it_returns_invalid_request_when_doffin_rejects_the_request_with_a_400_response(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'message' => 'Bad request',
            ], 400),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error_type']);
        $this->assertSame(400, $result['upstream_status']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['hits']);
        $this->assertFalse($result['fallback_used']);
        Http::assertSentCount(1);
    }

    public function test_it_returns_upstream_unavailable_when_doffin_returns_a_5xx_response(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'message' => 'Server error',
            ], 503),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        $this->assertFalse($result['ok']);
        $this->assertSame('upstream_unavailable', $result['error_type']);
        $this->assertSame(503, $result['upstream_status']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['hits']);
        $this->assertFalse($result['fallback_used']);
        Http::assertSentCount(1);
    }

    public function test_it_returns_timeout_when_the_http_client_times_out(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::failedConnection(
                'cURL error 28: Operation timed out after 30000 milliseconds',
            ),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        $this->assertFalse($result['ok']);
        $this->assertSame('timeout', $result['error_type']);
        $this->assertNull($result['upstream_status']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['hits']);
        $this->assertFalse($result['fallback_used']);
        Http::assertSentCount(1);
    }

    public function test_it_returns_connection_error_when_the_http_client_cannot_connect(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::failedConnection(
                'cURL error 6: Could not resolve host: api.doffin.no',
            ),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        $this->assertFalse($result['ok']);
        $this->assertSame('connection_error', $result['error_type']);
        $this->assertNull($result['upstream_status']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['hits']);
        $this->assertFalse($result['fallback_used']);
        Http::assertSentCount(1);
    }

    public function test_it_returns_unexpected_response_when_doffin_body_is_missing_hits(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
            ], 200),
        ]);

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_response', $result['error_type']);
        $this->assertSame(200, $result['upstream_status']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['hits']);
        Http::assertSentCount(1);
    }

    public function test_it_sanitizes_empty_request_fields_before_sending_them_to_doffin(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 0,
                'numHitsAccessible' => 0,
                'hits' => [],
            ], 200),
        ]);

        app(DoffinLiveSearchService::class)->search([
            'q' => '   ',
            'keywords' => ' , ',
            'organization_name' => '',
            'cpv' => '',
            'publication_date_from' => '',
            'publication_date_to' => '',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return ! isset($request['searchString'])
                && ! isset($request['facets']);
        });
    }

    public function test_it_uses_buyer_lookup_fallback_when_the_buyer_lookup_request_fails_with_a_5xx_response(): void
    {
        Http::fake(function ($request) {
            if (($request['searchString'] ?? null) === 'Domstoladministrasjonen') {
                return Http::response([
                    'message' => 'Temporary upstream failure',
                ], 503);
            }

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
        });

        $result = app(DoffinLiveSearchService::class)->search([
            'q' => 'Renhold',
            'organization_name' => 'Domstoladministrasjonen',
            'publication_period' => '',
            'status' => '',
        ], 1, 15);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['fallback_used']);
        $this->assertSame('2026-105164', $result['hits'][0]['id']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return ($request['searchString'] ?? null) === 'Domstoladministrasjonen';
        });
        Http::assertSent(function ($request): bool {
            return ($request['searchString'] ?? null) === 'Renhold'
                && ! isset($request['facets']);
        });
    }
}
