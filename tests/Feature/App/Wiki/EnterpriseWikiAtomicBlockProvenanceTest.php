<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiBlockProvenanceAmbiguousException;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ATOMIC PROVENANCE: a source-based block represents substance from exactly one document.
 *
 * A page aggregates many documents; a block does not. The block is the unit the Wiki can trace,
 * update and withdraw — so a block holding substance from two documents cannot be withdrawn without
 * either losing the surviving document's knowledge or keeping the removed document's.
 *
 * It was not an invariant before this change, only a property of the data: `replace` rewrote a
 * SUBSTRING inside an existing block and merged the patch document's elements into that block's
 * provenance (mergeSourceElements), leaving one block citing two documents while its own source_id
 * still named only the first. It had simply never run in production. Splitting such a block was not
 * an option either — blocks serialize with a blank line between them, so a sub-paragraph split would
 * break the paragraph in two.
 *
 * So `replace` now exchanges a WHOLE block, text and provenance together, and the writer refuses to
 * persist any source-based block whose provenance is ambiguous.
 */
class EnterpriseWikiAtomicBlockProvenanceTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // The guard at the choke point
    // =========================================================================

    public function test_the_writer_refuses_a_source_based_block_citing_two_documents(): void
    {
        $page = $this->page();

        $this->expectException(EnterpriseWikiBlockProvenanceAmbiguousException::class);
        $this->expectExceptionMessage('source elements name 2 different documents');

        app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'Blandet substans.',
            'content_blocks_json' => [$this->sourceBlock('Blandet substans.', [11, 12])],
        ]);
    }

    public function test_the_writer_refuses_a_block_whose_own_source_id_disagrees_with_its_elements(): void
    {
        $page = $this->page();
        $block = $this->sourceBlock('Substans fra ett dokument.', [11]);
        $block['source_id'] = 99;

        $this->expectException(EnterpriseWikiBlockProvenanceAmbiguousException::class);
        $this->expectExceptionMessage('declares source [enterprise_wiki_document#99]');

        app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'Substans fra ett dokument.',
            'content_blocks_json' => [$block],
        ]);
    }

    public function test_a_rejected_write_leaves_the_previous_current_version_intact(): void
    {
        $page = $this->page();
        $writer = app(EnterpriseWikiPageVersionWriter::class);
        $writer->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'Opprinnelig.',
            'content_blocks_json' => [$this->sourceBlock('Opprinnelig.', [11])],
        ]);

        try {
            $writer->writeNewCurrentVersion($page->id, [
                'content_markdown' => 'Blandet.',
                'content_blocks_json' => [$this->sourceBlock('Blandet.', [11, 12])],
            ]);
            $this->fail('an ambiguous block must never be persisted');
        } catch (EnterpriseWikiBlockProvenanceAmbiguousException) {
            // expected
        }

        $current = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)->where('is_current', true)->firstOrFail();
        $this->assertSame('Opprinnelig.', $current->content_markdown);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
    }

    public function test_an_ordinary_single_document_page_is_written_normally(): void
    {
        $page = $this->page();

        $version = app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, [
            'content_markdown' => "## Overskrift\n\nSubstans.\n\nAnbefaling.",
            'content_blocks_json' => [
                $this->structuralBlock('## Overskrift'),
                $this->sourceBlock('Substans.', [11]),
                // A best-practice block may cite the elements that MOTIVATED it, from any document,
                // without that being an origin claim — this guard must not touch it.
                $this->bestPracticeBlock('Anbefaling.', [11, 12]),
            ],
        ]);

        $this->assertCount(3, $version->fresh()->content_blocks_json);
    }

    public function test_a_block_with_no_document_provenance_is_not_this_guards_business(): void
    {
        $page = $this->page();
        $block = $this->sourceBlock('Uten elementer.', []);
        unset($block['source_type'], $block['source_id']);

        $version = app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'Uten elementer.',
            'content_blocks_json' => [$block],
        ]);

        $this->assertNotNull($version->id);
    }

    // =========================================================================
    // replace is now whole-block
    // =========================================================================

    public function test_a_sub_block_replace_splits_into_three_provenance_atoms(): void
    {
        [$run, $page, $document] = $this->patchScenario();

        $result = $this->applyPatch($run, [$this->target($page->id, 'frister på 30 minutter', 'frister på 15 minutter')]);

        $this->assertSame(1, $result['targets_applied']);

        $blocks = $this->currentBlocks($page);

        // ## Krav | prefix (A) | replacement (B) | suffix (A) | untouched (A)
        $this->assertCount(5, $blocks);
        $this->assertSame('Innledningen gjelder generelt. Her gjelder', $blocks[1]['markdown']);
        $this->assertSame('frister på 15 minutter', $blocks[2]['markdown']);
        $this->assertSame('for alle hendelser. Klassifiseringen er uendret.', $blocks[3]['markdown']);

        $this->assertSame(self::ORIGINAL_DOCUMENT_ID, $blocks[1]['source_id'], 'the prefix keeps its own document');
        $this->assertSame($document->id, $blocks[2]['source_id'], 'the new substance belongs to the patch document');
        $this->assertSame(self::ORIGINAL_DOCUMENT_ID, $blocks[3]['source_id'], 'the suffix keeps its own document');

        foreach ([1, 2, 3] as $index) {
            $this->assertCount(
                1,
                array_unique(array_column($blocks[$index]['source_elements'], 'source_id')),
                "block {$index} cites exactly one document",
            );
        }
    }

    public function test_the_untouched_fragments_survive_byte_for_byte(): void
    {
        // Preserved MECHANICALLY: the fragments are cut out of the original markdown by offset, never
        // retyped by a model, so there is nothing for a paraphrase to creep into.
        [$run, $page] = $this->patchScenario();
        $before = $this->currentBlocks($page)[1]['markdown'];

        $this->applyPatch($run, [$this->target($page->id, 'frister på 30 minutter', 'frister på 15 minutter')]);

        $blocks = $this->currentBlocks($page);

        $this->assertSame(
            $before,
            trim($blocks[1]['markdown'].' frister på 30 minutter '.$blocks[3]['markdown']),
            'prefix + superseded + suffix reconstructs the original paragraph exactly',
        );
    }

    public function test_the_split_renders_as_separate_paragraphs(): void
    {
        // The accepted cost of atomic provenance: one corrected paragraph becomes three.
        [$run, $page] = $this->patchScenario();

        $this->applyPatch($run, [$this->target($page->id, 'frister på 30 minutter', 'frister på 15 minutter')]);

        $current = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)->where('is_current', true)->firstOrFail();

        $this->assertSame(
            "## Krav\n\nInnledningen gjelder generelt. Her gjelder\n\nfrister på 15 minutter\n\n"
            ."for alle hendelser. Klassifiseringen er uendret.\n\nUberørt substans fra samme dokument.",
            $current->content_markdown,
        );
    }

    public function test_a_replace_covering_the_whole_block_produces_no_empty_segments(): void
    {
        [$run, $page, $document] = $this->patchScenario();

        $this->applyPatch($run, [$this->target(
            $page->id,
            'Innledningen gjelder generelt. Her gjelder frister på 30 minutter for alle hendelser. Klassifiseringen er uendret.',
            'Hele avsnittet er erstattet.',
        )]);

        $blocks = $this->currentBlocks($page);

        $this->assertCount(3, $blocks, 'no empty prefix or suffix block is created');
        $this->assertSame('Hele avsnittet er erstattet.', $blocks[1]['markdown']);
        $this->assertSame($document->id, $blocks[1]['source_id']);
    }

    public function test_a_replace_at_the_start_of_a_block_creates_no_empty_prefix(): void
    {
        [$run, $page] = $this->patchScenario();

        $this->applyPatch($run, [$this->target($page->id, 'Innledningen gjelder generelt.', 'Innledningen er opphevet.')]);

        $blocks = $this->currentBlocks($page);

        $this->assertCount(4, $blocks);
        $this->assertSame('Innledningen er opphevet.', $blocks[1]['markdown']);
        $this->assertSame('Her gjelder frister på 30 minutter for alle hendelser. Klassifiseringen er uendret.', $blocks[2]['markdown']);
    }

    public function test_a_link_intent_follows_the_segment_that_holds_its_link(): void
    {
        [$run, $page, , $linked] = $this->patchScenario(withLink: true);

        $this->applyPatch($run, [$this->target($page->id, 'frister på 30 minutter', 'frister på 15 minutter')]);

        $blocks = $this->currentBlocks($page);

        $this->assertStringContainsString('[['.$linked->slug.'|', $blocks[3]['markdown']);
        $this->assertSame([$linked->id], array_column($blocks[3]['link_intents'], 'target_page_id'), 'the intent follows its link');
        $this->assertSame([], $blocks[1]['link_intents']);
        $this->assertSame([], $blocks[2]['link_intents'], 'new substance carries no inherited intent');
    }

    public function test_two_replaces_in_one_block_are_refused(): void
    {
        [$run, $page] = $this->patchScenario();

        $result = $this->applyPatch($run, [
            $this->target($page->id, 'frister på 30 minutter', 'frister på 15 minutter'),
            $this->target($page->id, 'Innledningen gjelder generelt.', 'Innledningen er opphevet.'),
        ]);

        $this->assertSame(0, $result['targets_applied']);
        $this->assertStringContainsString('address the same content block', implode("\n", $result['failures']));
        $this->assertCount(3, $this->currentBlocks($page), 'the page is left exactly as it was');
    }

    public function test_a_replace_that_names_no_existing_block_still_fails_as_not_found(): void
    {
        [$run, $page] = $this->patchScenario();

        $result = $this->applyPatch($run, [$this->target($page->id, 'Tekst som ikke står på siden.', 'Ny tekst.')]);

        // The earlier verbatim resolver catches this one first, and its repair guidance tells the
        // model to copy only what the document supersedes — the server preserves the rest itself.
        $failures = implode("\n", $result['failures']);
        $this->assertStringContainsString('is not present verbatim', $failures);
        $this->assertStringContainsString('Copy only what this document supersedes', $failures);
        $this->assertNoNewVersion($page);
    }

    public function test_a_replace_may_not_target_a_structural_block(): void
    {
        [$run, $page] = $this->patchScenario();

        $result = $this->applyPatch($run, [$this->target($page->id, '## Krav', '## Nye krav')]);

        $this->assertStringContainsString('names a [structural] block', implode("\n", $result['failures']));
        $this->assertNoNewVersion($page);
    }

    private function assertNoNewVersion(EnterpriseWikiPage $page): void
    {
        $this->assertSame(
            1,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count(),
            'a rejected patch writes no version at all',
        );
    }

    public function test_the_merge_helper_no_longer_exists(): void
    {
        // The one construction site for cross-document provenance. Removed rather than guarded, so
        // no later caller can reintroduce it.
        $this->assertFalse(
            method_exists(EnterpriseWikiPatchApplicationService::class, 'mergeSourceElements'),
        );
        $this->assertStringNotContainsString(
            'mergeSourceElements(',
            preg_replace('/^\s*\/\/.*$/m', '', file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiPatchApplicationService.php'))),
        );
    }

    public function test_cross_page_reconciliation_uses_the_same_replace_contract(): void
    {
        // It has no replace implementation of its own: it derives targets and hands them to the
        // same service, so the whole-block rule and the guard apply unchanged.
        $source = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiCrossPageReconciliationService.php'));

        $this->assertStringContainsString('applyAdditionalTargetsForRun(', $source);
        $this->assertStringNotContainsString('superseded_substance', preg_replace('/^\s*\*.*$/m', '', $source));
    }

    public function test_amend_is_unchanged_and_carries_only_the_patch_document(): void
    {
        [$run, $page, $document] = $this->patchScenario();

        $result = $this->applyPatch($run, [
            array_merge($this->target($page->id, '', 'Ny presisering.'), [
                'operation' => 'amend',
                'relationship' => 'topic_extended',
                'superseded_substance' => null,
            ]),
        ]);

        $this->assertSame(1, $result['targets_applied']);

        $blocks = $this->currentBlocks($page);
        $this->assertCount(4, $blocks, 'amend appends a new block, it never rewrites one');
        $appended = $blocks[3];
        $this->assertSame('Ny presisering.', $appended['markdown']);
        $this->assertSame($document->id, $appended['source_id']);
        $this->assertSame(self::ORIGINAL_DOCUMENT_ID, $blocks[1]['source_id'], 'the original block is untouched');
    }

    public function test_the_patch_prompt_asks_only_for_the_superseded_substance(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules());

        // The model names only what the source supersedes; the backend preserves the rest itself.
        $this->assertStringContainsString('Copy ONLY the substance this document actually supersedes', $rules);
        $this->assertStringContainsString('you must never retype neighbouring text to preserve it', $rules);
        $this->assertStringNotContainsString('COPY ONE COMPLETE PARAGRAPH', $rules);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private const ORIGINAL_DOCUMENT_ID = 11;

    /** @return array{targets_applied: int, pages_patched: int} */
    private function applyPatch(EnterpriseWikiIngestRun $run, array $targets): array
    {
        return app(EnterpriseWikiPatchApplicationService::class)->applyAdditionalTargetsForRun($run, $targets);
    }

    /** @return list<array<string, mixed>> */
    private function currentBlocks(EnterpriseWikiPage $page): array
    {
        return (array) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail()
            ->content_blocks_json;
    }

    /** @return array<string, mixed> */
    private function target(int $pageId, string $superseded, string $replacement): array
    {
        return [
            'target_page_id' => $pageId,
            'target_page_title' => 'Krav',
            'target_page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'target_topic' => 'Krav',
            'target_heading' => 'Krav',
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => $superseded,
            'replacement_substance' => $replacement,
            'source_element_keys' => ['paragraph-0'],
            'preserve_topics' => [],
            'reason' => 'Kilden endrer denne substansen.',
        ];
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiDocument, 3: ?EnterpriseWikiPage} */
    private function patchScenario(bool $withLink = false): array
    {
        $customer = $this->customer();
        $patchDocument = $this->document($customer, 'Ny kilde.docx', 'Endringsnotat: frister på 15 minutter.');
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $patchDocument->id,
            'status' => EnterpriseWikiIngestRun::STATUS_APPLYING,
        ]);

        $page = $this->page($customer);
        $linked = null;
        $suffixText = 'for alle hendelser. Klassifiseringen er uendret.';

        if ($withLink) {
            $linked = EnterpriseWikiPage::query()->create([
                'customer_id' => $customer->id,
                'slug' => 'klassifisering-ab1c2d',
                'title' => 'Klassifisering',
                'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'status' => EnterpriseWikiPage::STATUS_DRAFT,
                'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                'last_source_hash' => str_pad('hash', 64, '0'),
            ]);
            $suffixText = 'for alle hendelser. [[klassifisering-ab1c2d|Klassifiseringen]] er uendret.';
        }

        $paragraph = 'Innledningen gjelder generelt. Her gjelder frister på 30 minutter '.$suffixText;
        $paragraphBlock = $this->sourceBlock($paragraph, [self::ORIGINAL_DOCUMENT_ID]);

        if ($withLink) {
            $paragraphBlock['link_intents'] = [[
                'intent_id' => 'lnk-1',
                'target_page_id' => $linked->id,
                'anchor_text' => 'Klassifiseringen',
                'reason' => 'Peker til siden som eier temaet.',
            ]];
        }

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "## Krav\n\n{$paragraph}\n\nUberørt substans fra samme dokument.",
            'content_blocks_json' => [
                $this->structuralBlock('## Krav'),
                $paragraphBlock,
                $this->sourceBlock('Uberørt substans fra samme dokument.', [self::ORIGINAL_DOCUMENT_ID]),
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        return [$run, $page, $patchDocument, $linked];
    }

    /** @param list<int> $documentIds */
    private function sourceBlock(string $markdown, array $documentIds): array
    {
        $elements = array_map(fn (int $id): array => [
            'source_type' => 'enterprise_wiki_document',
            'source_id' => $id,
            'source_label' => "dokument-{$id}.docx",
            'source_element_key' => 'paragraph-0',
            'source_element_type' => 'paragraph',
            'source_excerpt' => $markdown,
        ], $documentIds);

        return [
            'block_key' => 'block-'.substr(md5($markdown), 0, 8),
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => $documentIds === [] ? null : 'enterprise_wiki_document',
            'source_id' => $documentIds[0] ?? null,
            'source_element_key' => 'paragraph-0',
            'source_elements' => $elements,
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    private function structuralBlock(string $markdown): array
    {
        return [
            'block_key' => 'block-'.substr(md5($markdown), 0, 8),
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
            'source_elements' => [],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    /** @param list<int> $motivatingDocumentIds */
    private function bestPracticeBlock(string $markdown, array $motivatingDocumentIds): array
    {
        $block = $this->sourceBlock($markdown, $motivatingDocumentIds);
        $block['content_origin'] = EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE;
        $block['best_practice_reason'] = 'Kilden mangler dette.';

        return $block;
    }

    private function page(?Customer $customer = null): EnterpriseWikiPage
    {
        $customer ??= $this->customer();

        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'krav-'.Str::lower(Str::random(6)),
            'title' => 'Krav',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Provenance AS',
            'slug' => 'provenance-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function document(Customer $customer, string $filename, string $text): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', $filename),
            'extracted_text' => $text,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
