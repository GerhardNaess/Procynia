<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageRelinkAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiInboundLinkProposalAiClient;
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGraphDataService;
use App\Services\EnterpriseWiki\EnterpriseWikiInboundLinkInserter;
use App\Services\EnterpriseWiki\EnterpriseWikiIncrementalRelinkService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inbound relinking, relink v2 (D8 — docs/enterprise-wiki-knowledge-integration-plan.md §6.4).
 *
 * The AI only names a block, an anchor and a reason. The backend inserts the link into the
 * unchanged text, proves that nothing but link markup changed, and leaves review and publication
 * to the existing rules.
 */
class EnterpriseWikiIncrementalRelinkServiceTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Placement: only link markup is ever added
    // =========================================================================

    public function test_a_concept_trigger_links_an_existing_mentioning_page_by_inserting_markup_only(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $original = "# Artikkel\n\nThis article discusses the Business Case in detail.\n\nA second paragraph stays exactly as it is.";
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $original);
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-0002', 'Business Case', 'The article explains the business case the concept defines.');

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['applied']);
        $current = $this->currentVersion($article);
        $this->assertSame(2, $current->version_number);
        $this->assertSame("# Artikkel\n\nThis article discusses the [[business-case|Business Case]] in detail.\n\nA second paragraph stays exactly as it is.", $current->content_markdown);
        $this->assertSame($original, EnterpriseWikiInboundLinkInserter::stripWikilinks($current->content_markdown), 'visible text is byte-identical');

        $blocks = $current->content_blocks_json;
        $this->assertSame('# Artikkel', $blocks[0]['markdown']);
        $this->assertSame('A second paragraph stays exactly as it is.', $blocks[2]['markdown']);
        $this->assertSame('source_based', $blocks[1]['content_origin'], 'provenance untouched');
        $this->assertSame(4242, $blocks[1]['source_id']);
        $this->assertSame([[
            'intent_id' => "inbound-{$run->id}-{$trigger->id}",
            'target_page_id' => $trigger->id,
            'anchor_text' => 'Business Case',
            'reason' => 'The article explains the business case the concept defines.',
            'origin' => 'inbound_relink',
        ]], $blocks[1]['link_intents']);

        $this->assertDatabaseHas('enterprise_wiki_page_relink_attempts', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'trigger_page_id' => $trigger->id,
            'candidate_page_id' => $article->id,
            'status' => EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED,
            'created_page_version_id' => $current->id,
        ]);
    }

    public function test_a_new_article_triggers_relinking_of_the_existing_page_the_planner_named(): void
    {
        [$customer, $document] = $this->tenant();
        $existing = $this->createVersionedPage($customer, 'leverandorvurdering', 'Leverandørvurdering', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, "# Leverandørvurdering\n\nVurderingen følges opp gjennom løpende kontroll av leverandøren.");
        $article = $this->createVersionedPage($customer, 'leverandorkontroll', 'Leverandørkontroll', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, '# Leverandørkontroll');
        $run = $this->createAppliedRun($customer, $document, [$article], decision: [
            'source_article' => ['title' => 'Leverandørkontroll', 'related_page_guidance' => [
                ['page_title' => 'Leverandørvurdering', 'page_id' => $existing->id, 'relationship' => 'Grunnlaget for kontrollen.'],
            ]],
        ]);

        $this->mockProposal('block-0002', 'løpende kontroll', 'Kontrollen er beskrevet i den nye artikkelen.');

        $this->assertSame(1, $this->service()->relinkForRun($run)['applied']);
        $this->assertStringContainsString('[[leverandorkontroll|løpende kontroll]]', $this->currentVersion($existing)->content_markdown);
    }

    public function test_updated_articles_and_summaries_are_not_triggers(): void
    {
        [$customer, $document] = $this->tenant();
        $article = $this->createVersionedPage($customer, 'artikkel-b', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, '# Business Case');
        $summary = $this->createVersionedPage($customer, 'sammendrag-b', 'Business Case sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY, '# Business Case sammendrag');
        $this->createVersionedPage($customer, 'annen', 'Annen', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Mentions Business Case here.');
        $updatedRun = $this->createAppliedRun($customer, $document, [$article], EnterpriseWikiIngestRunPage::ACTION_UPDATED);
        $summaryRun = $this->createAppliedRun($customer, $document, [$summary]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldNotReceive('propose');

        $this->assertSame(0, $this->service()->relinkForRun($updatedRun)['triggers_processed']);
        $this->assertSame(0, $this->service()->relinkForRun($summaryRun)['triggers_processed']);
    }

    public function test_a_trigger_without_content_never_receives_inbound_links(): void
    {
        [$customer, $document] = $this->tenant();
        $shell = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Mentions Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$shell]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldNotReceive('propose');

        $this->assertSame(0, $this->service()->relinkForRun($run)['triggers_processed']);
    }

    public function test_long_existing_content_is_never_truncated(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $longParagraphs = implode("\n\n", array_map(fn (int $i): string => "Avsnitt {$i}: ".str_repeat('lang tekst ', 60), range(1, 20)));
        $original = $longParagraphs."\n\nTil slutt omtales Business Case her.";
        $this->assertGreaterThan(10000, strlen($original));
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $original);
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-0021', 'Business Case', 'Omtaler konseptet.');

        $this->service()->relinkForRun($run);

        $current = $this->currentVersion($article)->content_markdown;
        $this->assertSame(strlen($original) + strlen('[[business-case|]]'), strlen($current));
        $this->assertSame($original, EnterpriseWikiInboundLinkInserter::stripWikilinks($current));
    }

    public function test_a_legacy_page_without_stored_blocks_is_linked_in_its_markdown_only(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, "# Artikkel\n\nThe Business Case matters.", withBlocks: false);
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('paragraph-2', 'Business Case', 'x');

        $this->service()->relinkForRun($run);

        $current = $this->currentVersion($article);
        $this->assertSame("# Artikkel\n\nThe [[business-case|Business Case]] matters.", $current->content_markdown);
        $this->assertEmpty($current->content_blocks_json, 'no block JSON is invented for a legacy page');
    }

    // =========================================================================
    // Rejections: ambiguous or protected anchors never produce a version
    // =========================================================================

    public function test_an_ambiguous_anchor_is_rejected_and_no_version_is_written(): void
    {
        $this->assertProposalRejected('Business Case first, and Business Case again.', 'Business Case', EnterpriseWikiInboundLinkInserter::REJECT_AMBIGUOUS_ANCHOR);
    }

    public function test_an_anchor_that_is_not_in_the_block_is_rejected(): void
    {
        $this->assertProposalRejected('This discusses Business Case here.', 'forretningscase', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_NOT_FOUND);
    }

    public function test_an_anchor_inside_a_code_block_is_protected(): void
    {
        $this->assertProposalRejected("Business Case:\n```\nBusiness Case = true\n```", 'Business Case =', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED);
    }

    public function test_an_anchor_inside_an_existing_markdown_link_is_protected(): void
    {
        $this->assertProposalRejected('Read [the Business Case](https://example.test) first.', 'the Business Case', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED);
    }

    public function test_an_anchor_inside_an_existing_wikilink_or_inline_code_is_protected(): void
    {
        $this->assertProposalRejected('See [[annen-side|Business Case]] and `Business Case`.', 'Business Case', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED);
    }

    public function test_an_anchor_that_is_part_of_a_longer_word_is_not_a_match(): void
    {
        // "Business Casen" contains the anchor, but wrapping part of a word would break it.
        $this->assertProposalRejected('Business Casen er grundig.', 'Business Case', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_NOT_FOUND);
    }

    public function test_a_proposal_naming_an_unknown_block_is_rejected(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-9999', 'Business Case', 'x');

        $this->assertSame(1, $this->service()->relinkForRun($run)['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
        $this->assertSame(EnterpriseWikiPageRelinkAttempt::REASON_INVALID_REVISION.':'.EnterpriseWikiInboundLinkInserter::REJECT_UNKNOWN_BLOCK, $this->attempt($run, $article)->reason);
    }

    public function test_the_ai_declining_creates_no_new_version(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Business Case is mentioned only in passing here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldReceive('propose')->once()
            ->andReturn(['decision' => 'no_link', 'block_key' => null, 'anchor_text' => null, 'reason' => null]);

        $this->assertSame(1, $this->service()->relinkForRun($run)['skipped']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
        $this->assertSame(EnterpriseWikiPageRelinkAttempt::REASON_AI_DECLINED_LINK, $this->attempt($run, $article)->reason);
    }

    // =========================================================================
    // Candidates
    // =========================================================================

    public function test_an_irrelevant_page_without_a_mention_or_planner_reference_is_not_a_candidate(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $unrelated = $this->createVersionedPage($customer, 'unrelated', 'Unrelated', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This page is about something else entirely.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->assertSame(0, $this->service()->relinkForRun($run)['candidates_considered']);
        $this->assertDatabaseMissing('enterprise_wiki_page_relink_attempts', ['candidate_page_id' => $unrelated->id]);
        $this->assertSame(1, $this->currentVersion($unrelated)->version_number);
    }

    public function test_an_existing_valid_wikilink_is_not_duplicated(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[business-case|Business Case]] for details.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldNotReceive('propose');

        $this->assertSame(1, $this->service()->relinkForRun($run)['skipped']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
        $this->assertSame(EnterpriseWikiPageRelinkAttempt::REASON_ALREADY_LINKED, $this->attempt($run, $article)->reason);
    }

    public function test_another_customers_page_is_never_a_candidate_even_when_the_planner_names_it(): void
    {
        [$customerA, $document] = $this->tenant('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $trigger = $this->createVersionedPage($customerA, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $foreign = $this->createVersionedPage($customerB, 'foreign', 'Foreign', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This also discusses Business Case.');
        $run = $this->createAppliedRun($customerA, $document, [$trigger], decision: [
            'concept_pages' => [['title' => 'Business Case', 'related_page_guidance' => [
                ['page_title' => 'Foreign', 'page_id' => $foreign->id, 'relationship' => 'x'],
            ]]],
        ]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldNotReceive('propose');

        $this->assertSame(0, $this->service()->relinkForRun($run)['candidates_considered']);
        $this->assertDatabaseMissing('enterprise_wiki_page_relink_attempts', ['candidate_page_id' => $foreign->id]);
    }

    public function test_a_page_in_review_is_never_a_candidate(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $inReview = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $inReview->update(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW]);
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldNotReceive('propose');

        $this->assertSame(0, $this->service()->relinkForRun($run)['candidates_considered']);
        $this->assertSame(1, $this->currentVersion($inReview)->version_number);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $inReview->fresh()->status);
    }

    public function test_per_trigger_and_per_run_candidate_caps_are_enforced(): void
    {
        [$customer, $document] = $this->tenant();
        $triggers = [];

        foreach (['Alfa', 'Beta', 'Gamma', 'Delta'] as $name) {
            $triggers[] = $this->createVersionedPage($customer, Str::slug($name), $name, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, "# {$name}");
        }

        for ($i = 0; $i < EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER + 3; $i++) {
            $this->createVersionedPage($customer, "page-{$i}", "Page {$i}", EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Alfa, Beta, Gamma og Delta omtales her.');
        }

        $run = $this->createAppliedRun($customer, $document, $triggers);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldReceive('propose')
            ->times(EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_RUN)
            ->andReturn(['decision' => 'no_link', 'block_key' => null, 'anchor_text' => null, 'reason' => null]);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_RUN, $result['candidates_considered']);
        foreach ($triggers as $trigger) {
            $this->assertLessThanOrEqual(
                EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER,
                EnterpriseWikiPageRelinkAttempt::query()->where('trigger_page_id', $trigger->id)->count(),
            );
        }
    }

    public function test_a_candidate_gets_at_most_one_new_inbound_link_per_run(): void
    {
        [$customer, $document] = $this->tenant();
        $alfa = $this->createVersionedPage($customer, 'alfa', 'Alfa', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Alfa');
        $beta = $this->createVersionedPage($customer, 'beta', 'Beta', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Beta');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Alfa og Beta henger sammen.');
        $run = $this->createAppliedRun($customer, $document, [$alfa, $beta]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldReceive('propose')->once()
            ->andReturn(['decision' => 'link', 'block_key' => 'block-0001', 'anchor_text' => 'Alfa', 'reason' => 'x']);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(2, $this->currentVersion($article)->version_number);
        $this->assertSame(EnterpriseWikiIncrementalRelinkService::REASON_CANDIDATE_ALREADY_RELINKED, EnterpriseWikiPageRelinkAttempt::query()->where('trigger_page_id', $beta->id)->value('reason'));
    }

    // =========================================================================
    // Review, user edits and idempotency
    // =========================================================================

    public function test_a_published_candidate_gets_a_draft_working_version_and_its_published_version_stays(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $published = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $publishedVersion = $this->currentVersion($published);
        $published->update(['status' => EnterpriseWikiPage::STATUS_APPROVED, 'published_version_id' => $publishedVersion->id]);
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-0001', 'Business Case', 'x');

        $this->service()->relinkForRun($run);

        $published->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $published->status, 'the change goes through ordinary review');
        $this->assertSame($publishedVersion->id, $published->published_version_id, 'nothing is published automatically');
        $this->assertSame('This discusses Business Case here.', EnterpriseWikiPageVersion::query()->find($publishedVersion->id)->content_markdown);
        $this->assertStringContainsString('[[business-case|Business Case]]', $this->currentVersion($published)->content_markdown);
    }

    public function test_a_version_written_while_the_proposal_was_made_is_never_overwritten(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldReceive('propose')->once()->andReturnUsing(function () use ($article): array {
            // A person saves an edit while the model is answering.
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->update(['is_current' => false]);
            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'version_number' => 2,
                'is_current' => true,
                'content_markdown' => 'Edited by a person: Business Case here.',
            ]);

            return ['decision' => 'link', 'block_key' => 'block-0001', 'anchor_text' => 'Business Case', 'reason' => 'x'];
        });

        $this->assertSame(1, $this->service()->relinkForRun($run)['skipped']);
        $this->assertSame('Edited by a person: Business Case here.', $this->currentVersion($article)->content_markdown);
        $this->assertSame(EnterpriseWikiIncrementalRelinkService::REASON_STALE_CANDIDATE_VERSION, $this->attempt($run, $article)->reason);
    }

    public function test_a_repeated_run_makes_no_second_ai_call_version_or_link(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-0001', 'Business Case', 'x');

        $this->service()->relinkForRun($run);
        $this->service()->relinkForRun($run);

        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
        $this->assertSame(1, EnterpriseWikiPageRelinkAttempt::query()->where('enterprise_wiki_ingest_run_id', $run->id)->count());
        $this->assertSame(1, EnterpriseWikiPageLink::query()->where('from_page_id', $article->id)->where('to_page_id', $trigger->id)->count());
        $this->assertSame(1, substr_count($this->currentVersion($article)->content_markdown, '[[business-case|'));
    }

    public function test_the_new_link_is_materialized_and_visible_in_backlinks_traversal_and_graph(): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-0001', 'Business Case', 'Explains the concept.');

        $this->service()->relinkForRun($run);

        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $trigger->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
        $this->assertTrue(app(EnterpriseWikiPageTraversalService::class)->incoming($trigger)->pluck('id')->contains($article->id));

        $graph = app(EnterpriseWikiGraphDataService::class)->build($customer->id, EnterpriseWikiPage::STATUSES, pageId: $trigger->id);
        $edge = collect($graph['edges'])->first(fn (array $edge) => $edge['from_page_id'] === $article->id && $edge['to_page_id'] === $trigger->id);
        $this->assertNotNull($edge);
    }

    public function test_the_whole_markdown_reviser_is_no_longer_used_for_inbound_links(): void
    {
        $source = file_get_contents((new \ReflectionClass(EnterpriseWikiIncrementalRelinkService::class))->getFileName());

        $this->assertStringNotContainsString(class_basename(WikiLinkRevisionAiClient::class), $source);
        $this->assertStringNotContainsString('reviseLinks', $source);
    }

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiIncrementalRelinkService', $source);
        $this->assertStringNotContainsString('relinkForRun', $source);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertProposalRejected(string $content, string $anchor, string $reason): void
    {
        [$customer, $document] = $this->tenant();
        $trigger = $this->createVersionedPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, '# Business Case');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, "# Artikkel\n\n{$content}");
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockProposal('block-0002', $anchor, 'x');

        $this->assertSame(1, $this->service()->relinkForRun($run)['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number, 'no version is written');
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
        $this->assertSame(EnterpriseWikiPageRelinkAttempt::REASON_INVALID_REVISION.':'.$reason, $this->attempt($run, $article)->reason);
        $this->assertDatabaseMissing('enterprise_wiki_page_links', ['from_page_id' => $article->id, 'to_page_id' => $trigger->id]);
    }

    private function mockProposal(string $blockKey, string $anchor, string $reason): void
    {
        $this->mock(WikiInboundLinkProposalAiClient::class)->shouldReceive('propose')->once()
            ->andReturn(['decision' => 'link', 'block_key' => $blockKey, 'anchor_text' => $anchor, 'reason' => $reason]);
    }

    private function attempt(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $candidate): EnterpriseWikiPageRelinkAttempt
    {
        return EnterpriseWikiPageRelinkAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('candidate_page_id', $candidate->id)
            ->firstOrFail();
    }

    /** @return array{0: Customer, 1: EnterpriseWikiDocument} */
    private function tenant(string $name = 'Test AS'): array
    {
        $customer = $this->createCustomer($name);

        return [$customer, $this->createDocument($customer)];
    }

    private function service(): EnterpriseWikiIncrementalRelinkService
    {
        return app(EnterpriseWikiIncrementalRelinkService::class);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
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
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This is the extracted text from the source document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /**
     * An existing page as the pipeline writes one: content_markdown is its blocks joined, and every
     * block carries its document provenance. Pass $withBlocks=false for a legacy markdown-only page.
     */
    private function createVersionedPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType,
        string $content,
        bool $withBlocks = true,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $slug, $title, $pageType);
        $paragraphs = preg_split("/\n{2,}/", trim($content)) ?: [];
        $blocks = [];

        foreach ($paragraphs as $index => $paragraph) {
            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'position' => $index,
                'markdown' => $paragraph,
                'content_origin' => 'source_based',
                'source_type' => 'enterprise_wiki_document',
                'source_id' => 4242,
                'source_elements' => [['source_type' => 'enterprise_wiki_document', 'source_id' => 4242, 'source_element_key' => "paragraph-{$index}"]],
                'link_intents' => [],
            ];
        }

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content,
            'content_blocks_json' => $withBlocks ? $blocks : null,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document, array $triggerPages, string $action = EnterpriseWikiIngestRunPage::ACTION_CREATED, ?array $decision = null): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decision,
        ]);

        foreach ($triggerPages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => $action,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            ]);
        }

        return $run;
    }
}
