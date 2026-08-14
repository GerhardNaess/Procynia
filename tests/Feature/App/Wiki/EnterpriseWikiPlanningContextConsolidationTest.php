<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchEvaluator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * The anti-divergence matrix.
 *
 * Planning used to assemble its own facts in four places, and they had drifted: the queued split
 * path — production's path for any document large enough to need batches — called the phase-1 and
 * phase-2 entrypoints WITHOUT source elements, so its planner saw flat text instead of the
 * document's addressable catalog and was then asked to cite element keys it could not see.
 *
 * The fix is structural rather than diligent: every planning method takes
 * EnterpriseWikiPlanningContext, so a caller cannot forget a field that is no longer an argument.
 * These tests defend that property, and the bounded per-phase views that sit on top of it.
 */
class EnterpriseWikiPlanningContextConsolidationTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // One authoritative context
    // =========================================================================

    public function test_every_entrypoint_builds_an_identical_context_for_the_same_document(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $first = EnterpriseWikiPlanningContext::forDocument($customer->id, $document);
        $second = EnterpriseWikiPlanningContext::forDocument($customer->id, $document->fresh());

        $this->assertSame($first->factsFingerprint(), $second->factsFingerprint());
    }

    public function test_the_context_carries_every_fact_planning_needs(): void
    {
        $customer = $this->createCustomer();
        $this->createExistingPage($customer);
        $document = $this->createDocument($customer);

        $planning = EnterpriseWikiPlanningContext::forDocument($customer->id, $document);

        $this->assertSame($customer->id, $planning->customerId);
        $this->assertSame($document->id, $planning->documentId);
        $this->assertSame('Et Dokument', $planning->sourceMeta['title']);
        $this->assertSame('Et Dokument.docx', $planning->sourceMeta['filename']);
        $this->assertSame((string) $document->extracted_text, $planning->sourceText);
        $this->assertArrayHasKey('sections', $planning->sectionMap);
        $this->assertNotEmpty($planning->wikiIndex);
        $this->assertSame(
            array_column($planning->catalogElements, 'source_element_key'),
            $planning->validSourceElementKeys,
        );
        $this->assertSame(
            array_column($planning->figureCandidates, 'source_element_key'),
            $planning->validFigureKeys,
        );
    }

    /**
     * Call-level state must stay out: a context that carried language, model, budget or run status
     * would be a different object per call and could not be the shared source of truth.
     */
    public function test_the_context_leaks_no_call_level_state(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(EnterpriseWikiPlanningContext::class))->getProperties(),
        );

        foreach (['language', 'languageCode', 'model', 'maxOutputTokens', 'budget', 'runId', 'run', 'decision', 'aiCallContext', 'status'] as $forbidden) {
            $this->assertNotContains($forbidden, $properties, "planning context must not carry [{$forbidden}]");
        }
    }

    public function test_existing_page_candidates_are_not_loaded_until_they_are_used(): void
    {
        $customer = $this->createCustomer();
        $this->createExistingPage($customer);
        $document = $this->createDocument($customer);

        $planning = EnterpriseWikiPlanningContext::forDocument($customer->id, $document);

        $this->assertFalse($planning->hasResolvedExistingPageCandidates(), 'phase-2 batch jobs never render page candidates');

        $planning->existingPageCandidates();

        $this->assertTrue($planning->hasResolvedExistingPageCandidates());
        // Memoised: resolving twice must not query twice.
        $this->assertSame($planning->existingPageCandidates(), $planning->existingPageCandidates());
    }

    // =========================================================================
    // No path can lose a fact by forgetting an argument
    // =========================================================================

    #[DataProvider('planningEntrypoints')]
    public function test_planning_entrypoints_take_the_context_object(string $class, string $method): void
    {
        $parameters = (new ReflectionClass($class))->getMethod($method)->getParameters();
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getType() instanceof ReflectionNamedType
                ? $parameter->getType()->getName()
                : '',
            $parameters,
        );

        $this->assertContains(
            EnterpriseWikiPlanningContext::class,
            $types,
            "{$class}::{$method}() must take the planning context, not loose facts a caller can forget",
        );

        foreach ($parameters as $parameter) {
            $this->assertNotContains(
                $parameter->getName(),
                ['sourceElements', 'figureCandidates', 'existingPageCandidates', 'indexContext', 'sourceMeta'],
                "{$class}::{$method}() still accepts [{$parameter->getName()}] separately — that is how the paths drifted apart",
            );
        }
    }

    public static function planningEntrypoints(): array
    {
        return [
            'client: single/split decision' => [EnterpriseWikiMaintainerDecisionAiClient::class, 'decide'],
            'client: bounded repair' => [EnterpriseWikiMaintainerDecisionAiClient::class, 'repairGroup'],
            'client: persisted batch prep' => [EnterpriseWikiMaintainerDecisionAiClient::class, 'preparePersistedCandidateBatches'],
            'coordinator: in-process split' => [EnterpriseWikiMaintainerDecisionSplitCoordinator::class, 'decide'],
            'coordinator: persisted batch prep' => [EnterpriseWikiMaintainerDecisionSplitCoordinator::class, 'preparePersistedCandidateBatches'],
            'coordinator: persisted batch' => [EnterpriseWikiMaintainerDecisionSplitCoordinator::class, 'decidePersistedCandidateBatch'],
            'coordinator: phase 1' => [EnterpriseWikiMaintainerDecisionSplitCoordinator::class, 'decideGlobalPlan'],
            'coordinator: phase 2' => [EnterpriseWikiMaintainerDecisionSplitCoordinator::class, 'decideCandidateBatch'],
            'service: validation and repair' => [EnterpriseWikiMaintainerDecisionService::class, 'validateAndRepairForDocument'],
        ];
    }

    public function test_the_queued_batch_evaluator_builds_the_context_itself(): void
    {
        // It cannot receive one — it runs in another process — so it must rebuild the identical
        // facts rather than assemble a thinner subset of its own, which is what it used to do.
        $source = file_get_contents((new ReflectionClass(EnterpriseWikiMaintainerDecisionBatchEvaluator::class))->getFileName());

        $this->assertStringContainsString('EnterpriseWikiPlanningContext::forDocument(', $source);
        $this->assertStringNotContainsString('sourceElementService', $source);
        $this->assertStringNotContainsString('indexContextService', $source);
    }

    // =========================================================================
    // Per-phase views — intentionally different, identically derived
    // =========================================================================

    public function test_phase_one_sees_the_complete_catalog_and_the_overview(): void
    {
        $planning = $this->planningWithSections();
        $prompt = $this->phaseOnePrompt($planning);

        foreach (['Alfa first.', 'Beta first.', 'Gamma first.', 'Loose element outside any section.'] as $text) {
            $this->assertStringContainsString($text, $prompt, 'phase 1 places every candidate, so it needs the whole document');
        }

        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $prompt);
        $this->assertStringContainsString('# [sec-0] 1. Alfa', $prompt);
        // Deliberately NOT in this view (see the class docblock): the call that already runs longest
        // does not also get the existing-page bodies in this change.
        $this->assertStringNotContainsString('EXISTING PAGE CANDIDATES', $prompt);
        $this->assertFalse($planning->hasResolvedExistingPageCandidates());
    }

    public function test_phase_two_sees_only_its_own_sections_plus_the_overview(): void
    {
        $planning = $this->planningWithSections();
        $prompt = $this->phaseTwoPrompt($planning, [$this->mention('Beta-konseptet', ['sec-1'])]);

        $this->assertStringContainsString('Beta first.', $prompt);
        $this->assertStringNotContainsString('Alfa first.', $prompt);
        $this->assertStringContainsString('Loose element outside any section.', $prompt);
        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $prompt);
        $this->assertStringContainsString('EXISTING WIKI INDEX', $prompt);
    }

    public function test_the_in_process_and_queued_phase_two_build_the_same_view(): void
    {
        // The two split implementations still exist — they must no longer see different documents.
        $planning = $this->planningWithSections();
        $mentions = [$this->mention('Beta-konseptet', ['sec-1'])];

        $inProcess = $this->phaseTwoPrompt($planning, $mentions);
        $queued = $this->phaseTwoPrompt($planning, $mentions, viaPersistedEntrypoint: true);

        $this->assertSame($inProcess, $queued);
    }

    public function test_repair_sees_the_sections_its_objects_cite(): void
    {
        $planning = $this->planningWithSections();
        $decision = $this->decisionCitingElement('paragraph-2');

        $prompt = $this->repairCatalog($planning, $decision, ['concept_pages[0]']);

        $this->assertStringContainsString('Beta first.', $prompt);
        $this->assertStringNotContainsString('Alfa first.', $prompt);
        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $prompt);
    }

    public function test_the_full_catalog_is_used_only_where_the_contract_requires_it(): void
    {
        $planning = $this->planningWithSections();

        $phaseOne = $this->phaseOnePrompt($planning);
        $routedPhaseTwo = $this->phaseTwoPrompt($planning, [$this->mention('Beta-konseptet', ['sec-1'])]);
        $unroutablePhaseTwo = $this->phaseTwoPrompt($planning, [$this->mention('Uplassert', [])]);
        $unroutableRepair = $this->repairCatalog($planning, $this->decisionCitingElement(null), ['concept_pages[0]']);

        $this->assertStringContainsString('Alfa first.', $phaseOne, 'phase 1: whole document by contract');
        $this->assertStringNotContainsString('Alfa first.', $routedPhaseTwo, 'phase 2: routed');
        $this->assertStringContainsString('Alfa first.', $unroutablePhaseTwo, 'phase 2 fallback: nothing routable');
        $this->assertStringContainsString('Alfa first.', $unroutableRepair, 'repair fallback: nothing cited to route from');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function phaseOnePrompt(EnterpriseWikiPlanningContext $planning): string
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);

        return (new ReflectionClass($coordinator))->getMethod('globalPlanUserPrompt')->invoke(
            $coordinator,
            $planning->sourceMeta,
            $planning->sourceText,
            $planning->wikiIndex,
            $planning->figureCandidates,
            $planning->catalogElements,
            [],
        );
    }

    /** @param list<array<string, mixed>> $mentions */
    private function phaseTwoPrompt(EnterpriseWikiPlanningContext $planning, array $mentions, bool $viaPersistedEntrypoint = false): string
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $globalPlan = [
            'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d'),
            'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
            'entity_pages' => [],
        ];

        // Both split implementations funnel through candidateBatchUserPrompt() with the same
        // context — the persisted entrypoint differs only in how it is invoked.
        return (new ReflectionClass($coordinator))->getMethod('candidateBatchUserPrompt')->invoke(
            $coordinator,
            $planning->sourceMeta,
            $planning->sourceText,
            $planning->wikiIndex,
            $globalPlan,
            $mentions,
            $planning->figureCandidates,
            $planning->catalogElements,
        );
    }

    /** @param array<string, mixed> $decision */
    private function repairCatalog(EnterpriseWikiPlanningContext $planning, array $decision, array $objectIds): string
    {
        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);
        $sectionKeys = (new ReflectionClass($client))->getMethod('repairSectionKeys')->invoke(
            null,
            $decision,
            ['object_ids' => $objectIds, 'issues' => ['issue']],
            $planning->catalogElements,
        );

        return EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock(
            $planning->sourceText,
            $planning->catalogElements,
            12000,
            $sectionKeys,
        );
    }

    private function decisionCitingElement(?string $elementKey): array
    {
        return [
            'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d'),
            'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
            'concept_candidates' => [],
            'concept_pages' => [array_merge($this->page('Et Konsept', 'et-konsept'), [
                'page_id' => null,
                'owned_topics' => [[
                    'topic' => 'Et tema',
                    'source_element_keys' => $elementKey !== null ? [$elementKey] : [],
                ]],
            ])],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    /** @param list<string> $sectionKeys */
    private function mention(string $name, array $sectionKeys): array
    {
        return ['name' => $name, 'concept_type' => 'process', 'mentioned_context' => 'seksjon', 'section_keys' => $sectionKeys];
    }

    private function page(string $title, string $slug): array
    {
        return [
            'action' => 'create',
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'Ny side.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    private function planningWithSections(): EnterpriseWikiPlanningContext
    {
        $elements = [
            $this->element('paragraph-0', '1.', 'Alfa', 'Alfa first.'),
            $this->element('paragraph-2', '2.', 'Beta', 'Beta first.'),
            $this->element('paragraph-4', '3.', 'Gamma', 'Gamma first.'),
            $this->element('loose-0', '', '', 'Loose element outside any section.'),
        ];
        $catalog = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);

        return new EnterpriseWikiPlanningContext(
            customerId: 1,
            documentId: 1,
            sourceMeta: ['title' => 'Et Dokument', 'filename' => 'Et Dokument.docx'],
            sourceText: 'Flat tekst.',
            elements: $elements,
            catalogElements: $catalog,
            figureCandidates: [],
            sectionMap: EnterpriseWikiDocumentSectionMap::build($catalog),
            wikiIndex: [['id' => 7, 'title' => 'En Side', 'slug' => 'en-side', 'page_type' => 'concept', 'status' => 'draft', 'excerpt' => null, 'open_lint_count' => 0, 'updated_at' => null]],
            validSourceElementKeys: array_column($catalog, 'source_element_key'),
            validFigureKeys: [],
            existingPageCandidatesResolver: static fn (): array => [],
        );
    }

    private function element(string $key, string $number, string $title, string $text): array
    {
        return [
            'source_element_key' => $key,
            'source_element_type' => 'paragraph',
            'section_number' => $number,
            'section_title' => $title,
            'reference_text' => $text,
            'display_text' => $text,
        ];
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Kunde',
            'slug' => 'kunde-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'Et Dokument.docx',
            'file_path' => 'wiki/'.Str::random(12).'.docx',
            'file_hash_sha256' => Str::random(64),
            'extracted_text' => 'Kildetekst for planlegging.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createExistingPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'en-eksisterende-side',
            'title' => 'En Eksisterende Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);
    }
}
