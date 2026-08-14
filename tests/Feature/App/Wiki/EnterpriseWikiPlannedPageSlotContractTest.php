<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedPageSlotValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * The page-slot contract: an existing page is named through the slot for the type it already has,
 * and only ever through one slot.
 *
 * Run 55: the decision named page 193 ("Advania", an ENTITY) in `entity_pages` — correctly — and
 * ALSO in `concept_pages`. Nothing checked it until EnterpriseWikiMaintainerDecisionApplyService
 * refused to retype the page, by which point the decision had been validated, repaired, persisted
 * and pages were being created. The guard was right; it was simply the only check that existed.
 *
 * Domain-free: no page title, slug or document is special-cased.
 */
class EnterpriseWikiPlannedPageSlotContractTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_an_existing_entity_named_through_the_entity_slot_is_valid(): void
    {
        $customer = $this->createWikiCustomer();
        $entity = $this->createTypedPage($customer->id, 'Et Selskap', EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        $decision = $this->decision(entityPages: [$this->slotEntry($entity->id, $entity->title)]);

        $this->assertSame([], $this->validator()->findIssues($decision, $customer->id));
    }

    public function test_an_existing_concept_named_through_the_concept_slot_is_valid(): void
    {
        $customer = $this->createWikiCustomer();
        $concept = $this->createTypedPage($customer->id, 'Et Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $decision = $this->decision(conceptPages: [$this->slotEntry($concept->id, $concept->title)]);

        $this->assertSame([], $this->validator()->findIssues($decision, $customer->id));
    }

    /** The run-55 shape. */
    public function test_an_existing_entity_named_through_the_concept_slot_is_rejected(): void
    {
        $customer = $this->createWikiCustomer();
        $entity = $this->createTypedPage($customer->id, 'Et Selskap', EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        $decision = $this->decision(conceptPages: [$this->slotEntry($entity->id, $entity->title)]);

        $issues = $this->validator()->findIssues($decision, $customer->id);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("targets existing page [{$entity->id}] of type [entity] through a [concept] slot", $issues[0]);
        $this->assertStringContainsString('concept_pages[0]', $issues[0], 'must be attributable for bounded repair');
    }

    public function test_an_existing_concept_named_through_the_entity_slot_is_rejected(): void
    {
        $customer = $this->createWikiCustomer();
        $concept = $this->createTypedPage($customer->id, 'Et Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $decision = $this->decision(entityPages: [$this->slotEntry($concept->id, $concept->title)]);

        $issues = $this->validator()->findIssues($decision, $customer->id);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('of type [concept] through a [entity] slot', $issues[0]);
        $this->assertStringContainsString('entity_pages[0]', $issues[0]);
    }

    /** Exactly run 55: the same page named through BOTH slots, one of them correct. */
    public function test_the_same_existing_page_named_through_two_slots_is_rejected(): void
    {
        $customer = $this->createWikiCustomer();
        $entity = $this->createTypedPage($customer->id, 'Et Selskap', EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        $decision = $this->decision(
            conceptPages: [$this->slotEntry($entity->id, $entity->title)],
            entityPages: [$this->slotEntry($entity->id, $entity->title)],
        );

        $issues = $this->validator()->findIssues($decision, $customer->id);

        // The wrong-slot claim is reported; the correct one stands.
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('concept_pages[0]', $issues[0]);
        $this->assertStringContainsString('through a [concept] slot', $issues[0]);
    }

    public function test_the_same_page_named_twice_in_the_correct_slot_is_still_rejected(): void
    {
        $customer = $this->createWikiCustomer();
        $concept = $this->createTypedPage($customer->id, 'Et Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $decision = $this->decision(conceptPages: [
            $this->slotEntry($concept->id, $concept->title),
            $this->slotEntry($concept->id, $concept->title),
        ]);

        $issues = $this->validator()->findIssues($decision, $customer->id);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('already names', $issues[1 - 1]);
    }

    public function test_a_page_created_in_this_same_run_is_never_slot_checked(): void
    {
        // action "create" carries no page_id: there is no existing identity to contradict.
        $customer = $this->createWikiCustomer();

        $decision = $this->decision(conceptPages: [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'Et Nytt Konsept',
            'proposed_slug' => 'et-nytt-konsept',
            'reason' => 'Ny side.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ]]);

        $this->assertSame([], $this->validator()->findIssues($decision, $customer->id));
    }

    public function test_an_unknown_page_identity_is_rejected(): void
    {
        $customer = $this->createWikiCustomer();

        $decision = $this->decision(conceptPages: [$this->slotEntry(999_999, 'Finnes ikke')]);

        $issues = $this->validator()->findIssues($decision, $customer->id);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('not a page of this customer', $issues[0]);
    }

    public function test_another_customers_page_is_not_a_valid_identity(): void
    {
        $customer = $this->createWikiCustomer();
        $other = $this->createWikiCustomer('Annen Kunde');
        $foreign = $this->createTypedPage($other->id, 'Deres Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $decision = $this->decision(conceptPages: [$this->slotEntry($foreign->id, $foreign->title)]);

        $issues = $this->validator()->findIssues($decision, $customer->id);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('not a page of this customer', $issues[0]);
    }

    public function test_a_caller_without_tenant_context_is_skipped_rather_than_failed(): void
    {
        $decision = $this->decision(conceptPages: [$this->slotEntry(1, 'Uansett')]);

        $this->assertSame([], $this->validator()->findIssues($decision, 0));
    }

    // =========================================================================
    // The contract the model is told, and the batch slot that makes it obeyable
    // =========================================================================

    public function test_a_batch_can_reference_an_existing_entity_page_in_its_own_slot(): void
    {
        // Without this slot a batch could only ever put a reuse in concept_pages — the structural
        // reason run 55's decision named an entity through a concept slot in the first place.
        $schema = EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema()['json_schema']['schema'];

        $this->assertArrayHasKey('entity_pages', $schema['properties']);
        $this->assertContains('entity_pages', $schema['required']);

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch([
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [$this->slotEntry(42, 'Et Selskap')],
            'patch_targets' => [],
        ]);

        $this->assertCount(1, $parsed['entity_pages']);
        $this->assertSame(42, $parsed['entity_pages'][0]['page_id']);
    }

    public function test_every_planning_prompt_states_the_slot_rule(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::existingPageSlotRules());

        $this->assertStringContainsString('through the slot for the type it already has', $rules);
        $this->assertStringContainsString('ONE slot only', $rules);

        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);
        $single = (new \ReflectionClass($client))->getMethod('developerPrompt')->invoke($client, 'Norwegian');
        $this->assertStringContainsString('through the slot for the type it already has', $single);

        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $reflection = new \ReflectionClass($coordinator);

        foreach (['globalPlanDeveloperPrompt', 'candidateBatchDeveloperPrompt'] as $method) {
            $prompt = $reflection->getMethod($method)->invoke($coordinator, 'Norwegian');
            $this->assertStringContainsString('through the slot for the type it already has', $prompt, $method);
        }

        $this->assertStringContainsString(
            'through the slot for the type it already has',
            implode("\n", EnterpriseWikiMaintainerDecisionAiClient::repairResolutionRules()),
        );
    }

    public function test_the_rules_stay_domain_free(): void
    {
        $rules = mb_strtolower(implode("\n", EnterpriseWikiMaintainerDecisionAiClient::existingPageSlotRules()));

        foreach (['advania', 'masterdata', 'prosjekt'] as $term) {
            $this->assertStringNotContainsString($term, $rules);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validator(): EnterpriseWikiPlannedPageSlotValidator
    {
        return app(EnterpriseWikiPlannedPageSlotValidator::class);
    }

    private function createTypedPage(int $customerId, string $title, string $pageType): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => Str::slug($title).'-'.$pageType,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);
    }

    private function slotEntry(int $pageId, string $title): array
    {
        return [
            'action' => 'update',
            'page_id' => $pageId,
            'title' => $title,
            'proposed_slug' => Str::slug($title),
            'reason' => 'Dokumentet berører denne siden.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $conceptPages
     * @param  list<array<string, mixed>>  $entityPages
     */
    private function decision(array $conceptPages = [], array $entityPages = []): array
    {
        return [
            'source_article' => ['action' => 'create', 'title' => 'Et Dokument', 'proposed_slug' => 'et-dokument-ab1c2d', 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-ab1c2d', 'reason' => 'r'],
            'concept_candidates' => [],
            'concept_pages' => $conceptPages,
            'entity_pages' => $entityPages,
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }
}
