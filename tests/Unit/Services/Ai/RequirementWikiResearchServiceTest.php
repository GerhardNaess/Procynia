<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticSearchPlanAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify the bounded, agent-like Wiki-research loop — direct search, controlled page
 * reading, real wikilink/backlink navigation to discover further candidates, and deterministic,
 * testable safety limits that are never hardcoded to exactly one link hop.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiResearchServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->mock(EnterpriseWikiSemanticSearchPlanAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('planWikiReading')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $input, array $index): array => $this->semanticReadingPlan($index)));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_it_returns_no_pages_read_without_any_ai_call_when_the_catalog_has_no_relevant_candidates(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv problembehandling.');

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame([], $context['pages']);
        $this->assertSame('no_relevant_candidates', $context['limits']['stop_reason']);
    }

    public function test_it_selects_and_reads_a_directly_relevant_page(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $page = $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling og rotårsaksanalyse.');

        $this->mockResearchClient(fn (array $candidates) => [
            'action' => 'read_pages',
            'page_ids' => [$page->id],
            'search_terms' => [],
            'reason' => 'Direkte relevant.',
        ]);

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertCount(1, $context['pages']);
        $this->assertSame($page->id, $context['pages'][0]['page_id']);
        $this->assertSame('direct_search', $context['pages'][0]['selection_type']);
        $this->assertStringContainsString('rotårsaksanalyse', $context['pages'][0]['content_markdown']);
    }

    public function test_it_can_stop_after_the_first_round(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling.');

        $this->mockResearchClient(fn (array $candidates) => [
            'action' => 'enough_context',
            'page_ids' => [],
            'search_terms' => [],
            'reason' => 'Ikke nødvendig å lese noe.',
        ]);

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame([], $context['pages']);
        $this->assertSame('enough_context', $context['limits']['stop_reason']);
        $this->assertSame(1, $context['limits']['rounds_used']);
    }

    public function test_it_can_request_a_new_search_when_initial_candidates_are_insufficient(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling.');
        $searchTarget = $this->createWikiPageWithVersion($customer, 'Endringsstyre', 'Innhold om endringsstyre og endringshåndtering.');

        // The original candidate ("Problembehandling") is still a legitimate, unread candidate
        // after the search_more round, so the AI genuinely gets a third round to decide it isn't
        // needed either — a realistic three-round sequence, not a bug in the loop.
        $callCount = 0;
        $this->mock(RequirementWikiResearchAiClient::class, function (MockInterface $mock) use (&$callCount, $searchTarget): void {
            $mock->shouldReceive('selectNextAction')->times(3)->andReturnUsing(
                function (string $identifier, string $text, array $candidates, array $alreadyRead, array $budget) use (&$callCount, $searchTarget): array {
                    $callCount++;

                    return match ($callCount) {
                        1 => ['action' => 'search_more', 'page_ids' => [], 'search_terms' => ['endringsstyre', 'endringshåndtering'], 'reason' => 'Trenger annet søk.'],
                        2 => ['action' => 'read_pages', 'page_ids' => [$searchTarget->id], 'search_terms' => [], 'reason' => 'Funnet via nytt søk.'],
                        default => ['action' => 'enough_context', 'page_ids' => [], 'search_terms' => [], 'reason' => 'Tilstrekkelig etter dette.'],
                    };
                },
            );
        });

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame(3, $context['limits']['rounds_used']);
        $this->assertSame('enough_context', $context['limits']['stop_reason']);
        $this->assertCount(1, $context['pages']);
        $this->assertSame($searchTarget->id, $context['pages'][0]['page_id']);
    }

    public function test_an_unknown_page_id_is_rejected_by_the_service(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling.');

        $this->mockResearchClient(fn (array $candidates) => [
            'action' => 'read_pages',
            'page_ids' => [999999],
            'search_terms' => [],
            'reason' => 'Hallusinert side.',
        ]);

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame([], $context['pages']);
        $this->assertSame('no_valid_pages_selected', $context['limits']['stop_reason']);
    }

    public function test_a_page_belonging_to_another_customer_can_never_be_read(): void
    {
        $customerA = $this->createWikiCustomer('Customer A');
        $customerB = $this->createWikiCustomer('Customer B');
        $requirement = $this->createRequirement($customerA, 'Beskriv rutinen for problembehandling.');
        $this->createWikiPageWithVersion($customerA, 'Problembehandling', 'Innhold om problembehandling.');
        $otherCustomerPage = $this->createWikiPageWithVersion($customerB, 'Problembehandling hos B', 'Innhold hos kunde B.');

        $this->mockResearchClient(fn (array $candidates) => [
            'action' => 'read_pages',
            'page_ids' => [$otherCustomerPage->id],
            'search_terms' => [],
            'reason' => 'Forsøk på å lese side hos annen kunde.',
        ]);

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customerA->id, 'no');

        $this->assertSame([], $context['pages']);
    }

    public function test_a_draft_page_can_never_be_read(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling.');
        $draftPage = $this->createWikiPageWithVersion($customer, 'Problembehandling kladd', 'Kladdinnhold om problembehandling.', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);

        $this->mockResearchClient(fn (array $candidates) => [
            'action' => 'read_pages',
            'page_ids' => [$draftPage->id],
            'search_terms' => [],
            'reason' => 'Forsøk på å lese kladd.',
        ]);

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame([], $context['pages']);
    }

    public function test_the_same_page_is_never_read_twice_even_if_rediscovered(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $pageA = $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling som lenker videre.');
        $pageB = $this->createWikiPageWithVersion($customer, 'Hendelsesbehandling', 'Innhold om hendelsesbehandling.');
        $this->createWikilink($customer, $pageA, $pageB);
        $this->createWikilink($customer, $pageB, $pageA);

        $callCount = 0;
        $this->mock(RequirementWikiResearchAiClient::class, function (MockInterface $mock) use (&$callCount, $pageA): void {
            $mock->shouldReceive('selectNextAction')->twice()->andReturnUsing(
                function (string $identifier, string $text, array $candidates, array $alreadyRead, array $budget) use (&$callCount, $pageA): array {
                    $callCount++;
                    $candidateIds = array_column($candidates, 'page_id');

                    if ($callCount === 1) {
                        // Round 1: pageB isn't even offered yet (only direct-search candidates).
                        return ['action' => 'read_pages', 'page_ids' => [$pageA->id], 'search_terms' => [], 'reason' => 'Direkte treff.'];
                    }

                    // Round 2: pageA (already read) must not be among this round's candidates —
                    // pageB (discovered via the A->B wikilink) should be, since B also links back
                    // to A, but A itself can never be re-offered.
                    if (in_array($pageA->id, $candidateIds, true)) {
                        throw new \LogicException('Already-read page A must never be offered again.');
                    }

                    return ['action' => 'read_pages', 'page_ids' => $candidateIds, 'search_terms' => [], 'reason' => 'Les oppdaget side.'];
                },
            );
        });

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $readPageIds = array_column($context['pages'], 'page_id');
        $this->assertSame([$pageA->id, $pageB->id], $readPageIds);
        $this->assertCount(2, array_unique($readPageIds));
    }

    public function test_the_maximum_number_of_research_rounds_is_enforced(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling og relaterte prosesser.');
        $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling og relaterte prosesser.');

        // A response that never makes progress and never stops on its own — the service's own
        // round ceiling, not the AI, must end the run.
        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->times(RequirementWikiResearchService::MAX_RESEARCH_ROUNDS)
            ->andReturn(['action' => 'search_more', 'page_ids' => [], 'search_terms' => ['problembehandling'], 'reason' => 'Fortsetter å søke.']));

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame('max_rounds_reached', $context['limits']['stop_reason']);
        $this->assertSame(RequirementWikiResearchService::MAX_RESEARCH_ROUNDS, $context['limits']['rounds_used']);
    }

    public function test_the_maximum_number_of_pages_read_is_enforced(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling og relaterte prosesser i drift.');

        $pageIds = [];

        for ($i = 0; $i < 10; $i++) {
            $pageIds[] = $this->createWikiPageWithVersion(
                $customer,
                "Problembehandling del {$i}",
                "Innhold om problembehandling og relaterte prosesser i drift, del {$i}.",
            )->id;
        }

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->andReturnUsing(function (string $identifier, string $text, array $candidates): array {
                $ids = array_slice(array_column($candidates, 'page_id'), 0, 4);

                return ['action' => 'read_pages', 'page_ids' => $ids, 'search_terms' => [], 'reason' => 'Les flere.'];
            }));

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame(RequirementWikiResearchService::MAX_PAGES_READ, $context['limits']['pages_read']);
        $this->assertSame('max_pages_reached', $context['limits']['stop_reason']);
    }

    public function test_the_total_context_size_limit_is_enforced(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling og relaterte prosesser i drift.');

        // Each page is kept under FULL_CONTENT_MAX_CHARS (sent whole, ~3900 chars) so the overall
        // MAX_CONTEXT_SIZE limit — not MAX_PAGES_READ — is what stops the run.
        for ($i = 0; $i < 8; $i++) {
            $this->createWikiPageWithVersion(
                $customer,
                "Problembehandling del {$i}",
                $this->contentOfLength("Problembehandling del {$i}", 3900),
            );
        }

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->andReturnUsing(function (string $identifier, string $text, array $candidates): array {
                $ids = array_slice(array_column($candidates, 'page_id'), 0, 4);

                return ['action' => 'read_pages', 'page_ids' => $ids, 'search_terms' => [], 'reason' => 'Les flere.'];
            }));

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $this->assertSame('max_context_reached', $context['limits']['stop_reason']);
        $this->assertLessThanOrEqual(RequirementWikiResearchService::MAX_CONTEXT_SIZE, $context['limits']['context_size']);
        $this->assertLessThan(8, $context['limits']['pages_read']);
    }

    /**
     * The core acceptance property: a page two wikilink hops away from the original direct-search
     * hit can still be discovered and read, across three separate rounds — proving the solution is
     * a bounded research LOOP, not a single hardcoded one-hop rule.
     */
    public function test_a_page_two_link_hops_away_can_be_discovered_and_read_across_multiple_rounds(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');

        $pageA = $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling som lenker videre.');
        $pageB = $this->createWikiPageWithVersion($customer, 'Kontinuerlig forbedring', 'Innhold om kontinuerlig forbedring som ikke nevner det opprinnelige kravordet.');
        $pageC = $this->createWikiPageWithVersion($customer, 'Målstyring', 'Innhold om målstyring, to lenkehopp unna det opprinnelige søket.');
        $this->createWikilink($customer, $pageA, $pageB);
        $this->createWikilink($customer, $pageB, $pageC);

        $this->mock(EnterpriseWikiSemanticSearchPlanAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('planWikiReading')
            ->once()
            ->andReturn($this->semanticReadingPlan([[
                'page_id' => $pageA->id,
                'intended_use' => 'navigation_seed',
                'reason' => 'The initial concept is the navigation seed.',
            ]])));

        $callCount = 0;
        $this->mock(RequirementWikiResearchAiClient::class, function (MockInterface $mock) use (&$callCount, $pageA, $pageB, $pageC): void {
            $mock->shouldReceive('selectNextAction')->times(3)->andReturnUsing(
                function (string $identifier, string $text, array $candidates) use (&$callCount, $pageA, $pageB, $pageC): array {
                    $callCount++;
                    $candidateIds = array_column($candidates, 'page_id');

                    return match ($callCount) {
                        1 => ['action' => 'read_pages', 'page_ids' => [$pageA->id], 'search_terms' => [], 'reason' => 'Direkte treff.'],
                        2 => in_array($pageB->id, $candidateIds, true)
                            ? ['action' => 'read_pages', 'page_ids' => [$pageB->id], 'search_terms' => [], 'reason' => 'Oppdaget via lenke fra A.']
                            : ['action' => 'insufficient', 'page_ids' => [], 'search_terms' => [], 'reason' => 'Fant ikke B.'],
                        default => in_array($pageC->id, $candidateIds, true)
                            ? ['action' => 'read_pages', 'page_ids' => [$pageC->id], 'search_terms' => [], 'reason' => 'Oppdaget via lenke fra B.']
                            : ['action' => 'insufficient', 'page_ids' => [], 'search_terms' => [], 'reason' => 'Fant ikke C.'],
                    };
                },
            );
        });

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');

        $readPageIds = array_column($context['pages'], 'page_id');
        $this->assertSame([$pageA->id, $pageB->id, $pageC->id], $readPageIds);

        $pageCContext = collect($context['pages'])->firstWhere('page_id', $pageC->id);
        $this->assertSame('wikilink', $pageCContext['selection_type']);
        $this->assertSame($pageB->id, $pageCContext['discovered_from_page_id']);
        $this->assertSame(3, $context['limits']['rounds_used']);
    }

    public function test_it_throws_when_candidates_exist_but_wiki_ai_is_disabled(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv rutinen for problembehandling.');
        $this->createWikiPageWithVersion($customer, 'Problembehandling', 'Innhold om problembehandling.');

        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'no');
    }

    private function mockResearchClient(callable $responder): void
    {
        $this->mock(RequirementWikiResearchAiClient::class, function (MockInterface $mock) use ($responder): void {
            $mock->shouldReceive('selectNextAction')
                ->once()
                ->andReturnUsing(fn (string $identifier, string $text, array $candidates): array => $responder($candidates));
        });
    }

    private function contentOfLength(string $title, int $targetLength): string
    {
        $sentence = 'Problembehandling og relaterte prosesser i drift dekkes her med relevant innhold. ';
        $body = str_repeat($sentence, (int) ceil($targetLength / mb_strlen($sentence, 'UTF-8')));

        return "# {$title}\n\n".mb_substr($body, 0, $targetLength - mb_strlen($title, 'UTF-8') - 4, 'UTF-8');
    }

    private function createRequirement(Customer $customer, string $requirementText): SavedNoticeAiRequirement
    {
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'WIKI-RESEARCH-'.Str::random(8),
            'title' => 'Wiki research test case',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/wiki-research-test',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-04-01 00:00:00',
            'deadline' => '2026-05-01 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'requirement_identifier' => '1.1',
            'requirement_text' => $requirementText,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function semanticReadingPlan(array $indexOrSelectedPages, array $overrides = []): array
    {
        $selectedPages = isset($indexOrSelectedPages[0]['intended_use'])
            ? $indexOrSelectedPages
            : array_map(static fn (array $page): array => [
                'page_id' => $page['page_id'],
                'intended_use' => 'primary_evidence',
                'reason' => 'Test navigation plan.',
            ], $indexOrSelectedPages);

        return array_merge([
            'query_understanding' => [
                'topic' => 'unknown',
                'intent' => 'find documented knowledge',
                'explicit_entities' => [],
                'explicit_services_or_systems' => [],
                'scope' => 'unknown',
            ],
            'selected_pages' => $selectedPages,
            'model' => 'stub/1.0',
        ], $overrides);
    }
}
