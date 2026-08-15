<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiPatchTargetRegenerationBlockedException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 8K-3 — real, bounded patching of existing Wiki pages.
 *
 * The failure class this locks down is run 26's, measured on a real page: `action=update` sent an
 * existing page through ordinary generation, which writes the page from the new source document plus
 * owned_topics alone. One of four blocks survived, the entity's own definition and an unrelated
 * section were lost, every original provenance record was reallocated to the change note, and two
 * wikilinks disappeared. QA passed it.
 *
 * The invariant asserted throughout: patch only what the patch target authorizes. Every other block
 * is carried over byte-identically — markdown, provenance and links together.
 *
 * The engine is deterministic and calls no AI, so these tests exercise the real production path with
 * no mocking. Fixtures are domain-free: invented values A/B/C, generic titles.
 */
class EnterpriseWikiPatchApplicationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_A = 'terskelverdien er 99 enheter';

    private const NEW_A = 'terskelverdien er 120 enheter fra og med neste periode';

    private const OLD_B = 'fristen er 30 minutter';

    private const NEW_B = 'fristen er 15 minutter';

    private const UNRELATED_IN_SECTION = 'Klassifiseringen av hendelser i tre niveaaer er uendret.';

    private const UNRELATED_SECTION = 'Denne seksjonen handler om noe helt annet og skal aldri roeres av en patch.';

    // =========================================================================
    // replace
    // =========================================================================

    public function test_replace_swaps_only_the_superseded_substance(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);

        $result = $this->service()->applyForRun($run);

        $version = $pages['article']->fresh()->currentVersion;

        $this->assertSame([], $result['failures']);
        $this->assertSame(1, $result['pages_patched']);
        $this->assertStringContainsString(self::NEW_A, $version->content_markdown);
        $this->assertStringNotContainsString(self::OLD_A, $version->content_markdown);
        $this->assertStringContainsString(self::UNRELATED_IN_SECTION, $version->content_markdown, 'neighbouring substance in the same block survives');
        $this->assertStringContainsString(self::UNRELATED_SECTION, $version->content_markdown, 'an unrelated section survives');
        $this->assertStringContainsString(self::OLD_B, $version->content_markdown, 'a value no target named is untouched');
    }

    public function test_replace_writes_exactly_one_new_current_version(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);
        $before = $pages['article']->currentVersion;

        $this->service()->applyForRun($run);

        $page = $pages['article']->fresh();

        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
        $this->assertSame($before->version_number + 1, $page->currentVersion->version_number);
        $this->assertFalse($before->fresh()->is_current, 'the previous version becomes historical');
        $this->assertStringContainsString(self::OLD_A, $before->fresh()->content_markdown, 'history keeps the old value');
    }

    public function test_patched_version_is_marked_as_a_patch_not_a_generation(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);

        $this->service()->applyForRun($run);

        $this->assertSame(
            EnterpriseWikiPatchApplicationService::GENERATED_BY,
            $pages['article']->fresh()->currentVersion->generated_by_model,
        );
        $this->assertStringContainsString('section-patch', EnterpriseWikiPatchApplicationService::GENERATED_BY);
    }

    // =========================================================================
    // amend
    // =========================================================================

    public function test_amend_appends_new_substance_and_rewrites_nothing(): void
    {
        [$run, $pages] = $this->scenario([$this->amendTargetC()]);
        $before = $pages['article']->currentVersion->content_markdown;

        $result = $this->service()->applyForRun($run);

        $after = $pages['article']->fresh()->currentVersion->content_markdown;

        $this->assertSame(1, $result['pages_patched']);
        $this->assertStringContainsString('Ny utvidende bestemmelse C2 gjelder fra neste periode.', $after);
        // Every original sentence still present, verbatim.
        foreach ([self::OLD_A, self::OLD_B, self::UNRELATED_IN_SECTION, self::UNRELATED_SECTION] as $original) {
            $this->assertStringContainsString($original, $after, 'an amend must not rewrite existing substance');
        }
        $this->assertGreaterThan(mb_strlen($before), mb_strlen($after), 'an amend adds content');
    }

    public function test_amend_is_idempotent_and_never_duplicates_substance(): void
    {
        [$run, $pages] = $this->scenario([$this->amendTargetC()]);

        $this->service()->applyForRun($run);

        // Force a second pass over the same decision by clearing only the idempotency pivot, so the
        // engine's own content-level no-op is what has to hold.
        EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->delete();

        $this->service()->applyForRun($run->fresh());

        $markdown = $pages['article']->fresh()->currentVersion->content_markdown;

        $this->assertSame(1, mb_substr_count($markdown, 'Ny utvidende bestemmelse C2 gjelder fra neste periode.'));
    }

    // =========================================================================
    // preserve
    // =========================================================================

    public function test_preserve_only_page_gets_no_new_version(): void
    {
        [$run, $pages] = $this->scenario([$this->preserveTarget()]);
        $before = $pages['concept']->currentVersion;

        $result = $this->service()->applyForRun($run);

        $this->assertSame(0, $result['pages_patched']);
        $this->assertSame(1, $result['pages_skipped']);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['concept']->id)->count());
        $this->assertSame($before->id, $pages['concept']->fresh()->currentVersion->id, 'the current version is untouched');
    }

    public function test_preserve_alongside_a_replace_on_the_same_page_still_yields_one_version(): void
    {
        // A preserve target on the SAME page as a replace: it asserts one section is left untouched
        // while another is changed, and must not add a second version of its own.
        $preserveOnArticle = array_merge($this->preserveTarget(), [
            '_page' => 'article',
            'target_topic' => 'Urelatert',
            'target_heading' => 'Helt urelatert seksjon',
        ]);

        [$run, $pages] = $this->scenario([$this->replaceTargetA(), $preserveOnArticle]);

        $result = $this->service()->applyForRun($run);

        $this->assertSame([], $result['failures']);
        $this->assertSame(1, $result['pages_patched']);
        $this->assertStringContainsString(
            self::UNRELATED_SECTION,
            $pages['article']->fresh()->currentVersion->content_markdown,
            'the preserved section is still there',
        );

        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['article']->id)->count());
    }

    // =========================================================================
    // Several targets on one page — including the run-27 duplicate-heading class
    // =========================================================================

    public function test_two_targets_on_one_page_produce_a_single_new_version(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA(), $this->replaceTargetB()]);

        $result = $this->service()->applyForRun($run);

        $markdown = $pages['article']->fresh()->currentVersion->content_markdown;

        $this->assertSame(1, $result['pages_patched']);
        $this->assertSame(2, $result['targets_applied']);
        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['article']->id)->count());
        $this->assertStringContainsString(self::NEW_A, $markdown);
        $this->assertStringContainsString(self::NEW_B, $markdown);
        $this->assertStringNotContainsString(self::OLD_A, $markdown);
        $this->assertStringNotContainsString(self::OLD_B, $markdown);
    }

    /**
     * Two replaces inside ONE paragraph would have to be planned as a single multi-way split, with
     * every offset re-based after each cut. That is real complexity for a case the maintainer can
     * always express as two targets on two paragraphs — so it fails closed, and the page is left
     * exactly as it was rather than half-patched.
     */
    public function test_two_replacements_in_the_same_block_are_refused_without_writing(): void
    {
        [$run, $page] = $this->sameBlockReplacementScenario();
        $before = $page->currentVersion->content_markdown;

        $result = $this->service()->applyForRun($run);

        $this->assertSame(0, $result['targets_applied']);
        $this->assertSame(0, $result['pages_patched']);
        $this->assertStringContainsString('address the same content block', implode("\n", $result['failures']));
        $this->assertSame($before, $page->fresh()->currentVersion->content_markdown);
        $this->assertStringContainsString(self::OLD_A, $page->fresh()->currentVersion->content_markdown);
    }

    /**
     * The run-27 regression: one page stated the SAME requirement under TWO headings. 8K-2 keeps both
     * targets (identity includes the heading); 8K-3 must patch both occurrences, in one version, and
     * leave no stale copy behind.
     */
    public function test_the_same_topic_under_two_headings_is_patched_in_both_places(): void
    {
        [$run, $pages] = $this->duplicateHeadingScenario();

        $result = $this->service()->applyForRun($run);

        $markdown = $pages['article']->fresh()->currentVersion->content_markdown;

        $this->assertSame(1, $result['pages_patched'], 'one page, one version');
        $this->assertSame(2, $result['targets_applied'], 'neither target was dropped');
        $this->assertSame(2, mb_substr_count($markdown, self::NEW_A), 'both occurrences updated');
        $this->assertStringNotContainsString(self::OLD_A, $markdown, 'no stale copy remains in any targeted section');
        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['article']->id)->count());
    }

    public function test_targets_across_several_pages_each_get_their_own_single_version(): void
    {
        [$run, $pages] = $this->scenario([
            $this->replaceTargetA(),
            $this->replaceTargetOnSummary(),
        ]);

        $result = $this->service()->applyForRun($run);

        $this->assertSame(2, $result['pages_patched']);

        foreach (['article', 'summary'] as $key) {
            $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages[$key]->id)->count());
        }
    }

    // =========================================================================
    // Flat pages — no sub-sections (the run-28 summary case)
    // =========================================================================

    public function test_a_flat_summary_can_be_patched_by_replace(): void
    {
        [$run, $page] = $this->flatPageScenario([$this->flatReplaceTarget()]);

        $result = $this->service()->applyForRun($run);

        $markdown = $page->fresh()->currentVersion->content_markdown;

        $this->assertSame([], $result['failures']);
        $this->assertSame(1, $result['pages_patched']);
        $this->assertStringContainsString(self::NEW_A, $markdown);
        $this->assertStringNotContainsString(self::OLD_A, $markdown);
    }

    public function test_a_flat_summary_can_be_patched_by_amend(): void
    {
        [$run, $page] = $this->flatPageScenario([array_merge($this->flatReplaceTarget(), [
            'operation' => 'amend',
            'relationship' => 'topic_extended',
            'superseded_substance' => null,
            'replacement_substance' => 'Ny utvidende bestemmelse for sammendraget.',
        ])]);

        $result = $this->service()->applyForRun($run);
        $markdown = $page->fresh()->currentVersion->content_markdown;

        $this->assertSame([], $result['failures']);
        $this->assertStringContainsString('Ny utvidende bestemmelse for sammendraget.', $markdown);
        $this->assertStringContainsString(self::OLD_A, $markdown, 'an amend rewrites nothing');
    }

    public function test_patching_a_flat_page_never_touches_its_title(): void
    {
        [$run, $page] = $this->flatPageScenario([$this->flatReplaceTarget()]);
        $before = $page->currentVersion->content_blocks_json[0];

        $this->service()->applyForRun($run);

        $after = $page->fresh()->currentVersion->content_blocks_json[0];

        $this->assertSame('# Sammendrag: Styrende prosedyre', $after['markdown']);
        $this->assertSame($this->comparable($before), $this->comparable($after), 'the H1 block is untouched');
    }

    public function test_a_flat_page_patch_preserves_unrelated_body_blocks_provenance_and_links(): void
    {
        [$run, $page] = $this->flatPageScenario([$this->flatReplaceTarget()]);
        $before = $page->currentVersion->content_blocks_json;

        $this->service()->applyForRun($run);

        $after = $page->fresh()->currentVersion->content_blocks_json;

        $this->assertCount(count($before), $after);

        foreach ($before as $index => $originalBlock) {
            if (str_contains((string) $originalBlock['markdown'], self::OLD_A)) {
                continue; // the one authorized block
            }

            $this->assertSame(
                $this->comparable($originalBlock),
                $this->comparable($after[$index]),
                "flat-page block {$index} was not authorized to change",
            );
        }

        $markdown = $page->fresh()->currentVersion->content_markdown;

        $this->assertStringContainsString(self::UNRELATED_SECTION, $markdown, 'unrelated body substance survives');
        $this->assertStringContainsString('[[nabotema-alfa|Nabotema Alfa]]', $markdown, 'an existing wikilink survives');
    }

    public function test_a_flat_page_replace_that_is_not_present_fails_without_writing(): void
    {
        $target = $this->flatReplaceTarget();
        $target['superseded_substance'] = 'Denne setningen finnes ikke i sammendraget.';

        [$run, $page] = $this->flatPageScenario([$target]);
        $before = $page->currentVersion;

        $result = $this->service()->applyForRun($run);

        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString('is not present verbatim', $result['failures'][0]);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
        $this->assertSame($before->content_markdown, $page->fresh()->currentVersion->content_markdown);
    }

    public function test_a_sectioned_page_gets_no_flat_fallback(): void
    {
        // The article fixture HAS sub-sections, so a headingless target must fail controlled rather
        // than silently treating the whole page as the area.
        $target = $this->replaceTargetA();
        $target['target_heading'] = null;
        $target['target_topic'] = 'Et tema som ikke er en overskrift';

        $this->assertControlledFailure($target, 'does not identify a section');
    }

    // =========================================================================
    // Page-type agnosticism
    // =========================================================================

    public function test_the_patch_engine_works_on_every_page_type(): void
    {
        foreach ([
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            EnterpriseWikiPage::PAGE_TYPE_ENTITY,
        ] as $pageType) {
            [$run, $page] = $this->singlePageScenario($pageType);

            $result = $this->service()->applyForRun($run);

            $this->assertSame(1, $result['pages_patched'], "patching must work for [{$pageType}]");
            $this->assertStringContainsString(self::NEW_A, $page->fresh()->currentVersion->content_markdown);
            $this->assertSame($pageType, $page->fresh()->page_type, 'page_type is never changed by a patch');
        }
    }

    // =========================================================================
    // Preservation, provenance, wikilinks, identity
    // =========================================================================

    public function test_blocks_outside_the_target_area_are_byte_identical_including_provenance(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);
        $before = $pages['article']->currentVersion->content_blocks_json;

        $this->service()->applyForRun($run);

        $after = $pages['article']->fresh()->currentVersion->content_blocks_json;

        // A replace SPLITS its block into provenance atoms, so the count grows. Every block the patch
        // did not authorise must still be there, byte-identical — matched by content rather than by
        // index, since a split shifts every later index.
        $this->assertGreaterThan(count($before), count($after), 'a sub-block replace splits its block');

        $afterByMarkdown = [];

        foreach ($after as $block) {
            $afterByMarkdown[trim((string) $block['markdown'])] = $block;
        }

        foreach ($before as $index => $originalBlock) {
            if (str_contains((string) $originalBlock['markdown'], self::OLD_A)) {
                continue; // the one authorized block
            }

            $carried = $afterByMarkdown[trim((string) $originalBlock['markdown'])] ?? null;

            $this->assertNotNull($carried, "block {$index} was not authorized to change but disappeared");
            $this->assertSame(
                $this->comparable($originalBlock),
                $this->comparable($carried),
                "block {$index} was not authorized to change",
            );
        }
    }

    public function test_untouched_blocks_keep_their_original_document_provenance(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);
        // Read it from a content block, not block 0 — that one is the structural H1 and carries no
        // provenance by design.
        $originalDocumentId = $this->blockContaining(
            $pages['article']->currentVersion->content_blocks_json,
            self::UNRELATED_SECTION,
        )['source_id'] ?? null;

        $this->service()->applyForRun($run);

        $after = $pages['article']->fresh()->currentVersion->content_blocks_json;
        $unrelated = $this->blockContaining($after, self::UNRELATED_SECTION);

        $this->assertNotNull($originalDocumentId);
        $this->assertSame($originalDocumentId, $unrelated['source_id'], 'provenance must not be reallocated to the patch document');
        $this->assertNotEmpty($unrelated['source_elements']);
    }

    public function test_a_replace_splits_the_block_into_single_document_provenance_atoms(): void
    {
        // The old behaviour merged the patch document's elements INTO the original block, leaving one
        // block citing two documents while its own source_id still named only the first. Now the
        // block is split: the untouched text keeps its document, the new substance gets its own.
        [$run, $pages, $documents] = $this->scenario([$this->replaceTargetA()], returnDocuments: true);
        $original = $this->blockContaining($pages['article']->currentVersion->content_blocks_json, self::OLD_A);
        $originalKeys = array_column($original['source_elements'], 'source_element_key');

        $this->service()->applyForRun($run);

        $blocks = $pages['article']->fresh()->currentVersion->content_blocks_json;
        $replacement = $this->blockContaining($blocks, self::NEW_A);
        $survivor = $this->blockContaining($blocks, self::UNRELATED_IN_SECTION);

        $this->assertSame(
            [$documents['patch']->id],
            array_values(array_unique(array_column($replacement['source_elements'], 'source_id'))),
            'the new substance cites the patch document and nothing else',
        );
        $this->assertSame($documents['patch']->id, $replacement['source_id']);
        $this->assertContains('paragraph-1', array_column($replacement['source_elements'], 'source_element_key'));

        $this->assertSame(
            [$documents['old']->id],
            array_values(array_unique(array_column($survivor['source_elements'], 'source_id'))),
            'the untouched neighbouring sentence still belongs to the document that wrote it',
        );
        $this->assertSame($documents['old']->id, $survivor['source_id']);
        $this->assertSame(
            $originalKeys,
            array_column($survivor['source_elements'], 'source_element_key'),
            'the surviving fragment inherits the original provenance exactly',
        );
    }

    public function test_multi_element_provenance_is_preserved_on_new_substance(): void
    {
        $target = $this->amendTargetC();
        $target['source_element_keys'] = ['paragraph-1', 'paragraph-2'];

        [$run, $pages] = $this->scenario([$target]);

        $this->service()->applyForRun($run);

        $inserted = $this->blockContaining(
            $pages['article']->fresh()->currentVersion->content_blocks_json,
            'Ny utvidende bestemmelse C2 gjelder fra neste periode.',
        );

        $this->assertSame(
            ['paragraph-1', 'paragraph-2'],
            array_column($inserted['source_elements'], 'source_element_key'),
        );
    }

    public function test_existing_wikilinks_outside_the_target_area_survive(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);

        $this->service()->applyForRun($run);

        $version = $pages['article']->fresh()->currentVersion;

        $this->assertStringContainsString('[[nabotema-alfa|Nabotema Alfa]]', $version->content_markdown, 'a link outside the target area survives');
        // Present in the blocks too, not only in the serialized markdown.
        $this->assertStringContainsString(
            '[[nabotema-alfa|Nabotema Alfa]]',
            $this->blockContaining($version->content_blocks_json, 'Nabotema Alfa')['markdown'],
        );
    }

    public function test_page_identity_is_never_changed_by_a_patch(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);
        $before = $pages['article']->replicate();

        $this->service()->applyForRun($run);

        $after = $pages['article']->fresh();

        $this->assertSame($pages['article']->id, $after->id);
        $this->assertSame($before->page_type, $after->page_type);
        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->slug, $after->slug);
    }

    // =========================================================================
    // Controlled failure — never a fallback rewrite
    // =========================================================================

    public function test_a_heading_that_does_not_exist_fails_without_writing(): void
    {
        $target = $this->replaceTargetA();
        $target['target_heading'] = 'Finnes ikke paa siden';

        $this->assertControlledFailure($target, 'is not a heading');
    }

    public function test_missing_superseded_substance_fails_without_writing(): void
    {
        $target = $this->replaceTargetA();
        $target['superseded_substance'] = 'Denne setningen finnes ikke noe sted paa siden.';

        $this->assertControlledFailure($target, 'is not present verbatim');
    }

    public function test_an_unknown_source_element_key_fails_without_writing(): void
    {
        $target = $this->replaceTargetA();
        $target['source_element_keys'] = ['paragraph-999'];

        $this->assertControlledFailure($target, 'source catalog');
    }

    public function test_superseded_substance_in_another_section_is_not_reachable(): void
    {
        // The value exists on the page, but NOT inside the section the target names. A patch must not
        // reach outside its own area to find it.
        $target = $this->replaceTargetA();
        $target['target_heading'] = 'Helt urelatert seksjon';
        $target['target_topic'] = 'Urelatert';

        $this->assertControlledFailure($target, 'is not present verbatim');
    }

    public function test_a_wikilink_to_a_nonexistent_page_fails_without_writing(): void
    {
        $target = $this->amendTargetC();
        $target['replacement_substance'] = 'Ny bestemmelse som lenker til [[finnes-ikke|Finnes Ikke]].';

        $this->assertControlledFailure($target, 'not a live page');
    }

    public function test_one_failing_target_does_not_prevent_another_page_from_being_patched(): void
    {
        $broken = $this->replaceTargetA();
        $broken['superseded_substance'] = 'Finnes ikke.';

        [$run, $pages] = $this->scenario([$broken, $this->replaceTargetOnSummary()]);

        $result = $this->service()->applyForRun($run);

        $this->assertCount(1, $result['failures']);
        $this->assertSame(1, $result['pages_patched']);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['article']->id)->count());
        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['summary']->id)->count());
    }

    // =========================================================================
    // Idempotency, concurrency and the no-full-generation guarantee
    // =========================================================================

    public function test_a_retry_after_success_writes_no_second_version(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);

        $this->service()->applyForRun($run);
        $this->service()->applyForRun($run->fresh());
        $this->service()->applyForRun($run->fresh());

        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['article']->id)->count());
    }

    public function test_the_patch_records_an_idempotency_pivot_that_is_not_pending_generation_work(): void
    {
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);

        $this->service()->applyForRun($run);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $pages['article']->id)
            ->firstOrFail();

        $this->assertSame(EnterpriseWikiIngestRunPage::ACTION_PATCHED, $pivot->action);
        $this->assertNotNull($pivot->generated_page_version_id, 'a null version id would make it look like pending generation work');
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
    }

    public function test_a_patch_target_is_still_refused_by_full_page_generation(): void
    {
        // The 8K-2 backstop stays: even with the patch path live, the destructive path must remain
        // closed for a patch-targeted page.
        [$run, $pages] = $this->scenario([$this->replaceTargetA()]);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $pages['article']->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        $this->expectException(EnterpriseWikiPatchTargetRegenerationBlockedException::class);

        app(EnterpriseWikiGenerateAppliedPagesService::class)->generatePageForRun($run->fresh(), $pages['article']);
    }

    public function test_the_engine_never_calls_full_page_generation(): void
    {
        // Structural proof rather than behavioural: the patch service has no page-content AI client
        // and no generation service in its dependency graph, so it cannot produce a whole page.
        $constructor = (new \ReflectionClass(EnterpriseWikiPatchApplicationService::class))->getConstructor();
        $dependencies = array_map(
            fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $constructor?->getParameters() ?? [],
        );

        foreach ($dependencies as $dependency) {
            $this->assertStringNotContainsString('WikiPageContentAiClient', $dependency);
            $this->assertStringNotContainsString('GenerateAppliedPages', $dependency);
        }
    }

    public function test_a_decision_without_patch_targets_is_a_no_op(): void
    {
        [$run] = $this->scenario([]);

        $result = $this->service()->applyForRun($run);

        $this->assertSame(
            ['pages_patched' => 0, 'pages_skipped' => 0, 'targets_applied' => 0, 'failures' => []],
            $result,
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiPatchApplicationService
    {
        return app(EnterpriseWikiPatchApplicationService::class);
    }

    /** @param array<string, mixed> $target */
    private function assertControlledFailure(array $target, string $expectedMessage): void
    {
        [$run, $pages] = $this->scenario([$target]);
        $before = $pages['article']->currentVersion;

        $result = $this->service()->applyForRun($run);

        $this->assertCount(1, $result['failures'], 'the patch must fail, not succeed');
        $this->assertStringContainsString($expectedMessage, $result['failures'][0]);
        $this->assertSame(0, $result['pages_patched']);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pages['article']->id)->count(),
            'a failed patch writes no version',
        );
        $this->assertSame($before->id, $pages['article']->fresh()->currentVersion->id);
        $this->assertSame($before->content_markdown, $pages['article']->fresh()->currentVersion->content_markdown);
    }

    /** @param list<array<string, mixed>> $blocks @return array<string, mixed> */
    private function blockContaining(array $blocks, string $needle): array
    {
        foreach ($blocks as $block) {
            if (str_contains((string) ($block['markdown'] ?? ''), $needle)) {
                return $block;
            }
        }

        $this->fail("no block contains [{$needle}]");
    }

    /** @param array<string, mixed> $block */
    private function comparable(array $block): string
    {
        unset($block['block_key'], $block['position'], $block['_patch_inserted']);
        ksort($block);

        return (string) json_encode($block, JSON_UNESCAPED_UNICODE);
    }

    /**
     * An existing article/summary/concept with real block provenance from an OLD document, plus a
     * patch document that supersedes two of its values.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return array{0: EnterpriseWikiIngestRun, 1: array<string, EnterpriseWikiPage>, 2?: array<string, EnterpriseWikiDocument>}
     */
    private function scenario(array $targets, bool $returnDocuments = false): array
    {
        $customer = $this->createCustomer();
        $oldDocument = $this->createDocument($customer, 'gammel-prosedyre.docx', 'Gammelt grunnlag. '.self::OLD_A.'. '.self::OLD_B.'.');
        $patchDocument = $this->createDocument($customer, 'endringsnotat.docx', $this->patchDocumentText());

        $this->createPage($customer, 'Nabotema Alfa', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'nabotema-alfa');

        $article = $this->createPage($customer, 'Styrende prosedyre', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersionWithBlocks($article, $oldDocument, [
            '# Styrende prosedyre',
            'Innledning som ogsaa lenker til [[nabotema-alfa|Nabotema Alfa]].',
            '## Krav og terskler',
            'Her gjelder at '.self::OLD_A.'. '.self::UNRELATED_IN_SECTION,
            '## Frister',
            'For hendelser gjelder at '.self::OLD_B.'.',
            '## Helt urelatert seksjon',
            self::UNRELATED_SECTION,
        ]);

        $summary = $this->createPage($customer, 'Sammendrag: Styrende prosedyre', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createVersionWithBlocks($summary, $oldDocument, [
            '# Sammendrag: Styrende prosedyre',
            '## Krav og terskler',
            'Kort: '.self::OLD_A.'.',
        ]);

        $concept = $this->createPage($customer, 'Generelt hovedtema', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersionWithBlocks($concept, $oldDocument, [
            '# Generelt hovedtema',
            '## Begreper',
            'Generell beskrivelse uten tallfestede krav.',
        ]);

        $pages = compact('article', 'summary', 'concept');
        $resolved = [];

        foreach ($targets as $target) {
            $resolved[] = $this->bindTarget($target, $pages);
        }

        $run = $this->createRun($customer, $patchDocument, $resolved);

        return $returnDocuments
            ? [$run, $pages, ['old' => $oldDocument, 'patch' => $patchDocument]]
            : [$run, $pages];
    }

    /**
     * One page stating the same superseded value under two DIFFERENT headings — the run-27 shape.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: array<string, EnterpriseWikiPage>}
     */
    private function duplicateHeadingScenario(): array
    {
        $customer = $this->createCustomer();
        $oldDocument = $this->createDocument($customer, 'gammel-prosedyre.docx', 'Gammelt grunnlag. '.self::OLD_A.'.');
        $patchDocument = $this->createDocument($customer, 'endringsnotat.docx', $this->patchDocumentText());

        $article = $this->createPage($customer, 'Styrende prosedyre', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersionWithBlocks($article, $oldDocument, [
            '# Styrende prosedyre',
            '## Krav og terskler',
            'Her gjelder at '.self::OLD_A.'. '.self::UNRELATED_IN_SECTION,
            '## Krav og terskler for tjenesten',
            'For tjenesten gjelder at '.self::OLD_A.'.',
            '## Helt urelatert seksjon',
            self::UNRELATED_SECTION,
        ]);

        $first = $this->replaceTargetA();
        $first['target_page_id'] = $article->id;
        $first['target_page_type'] = EnterpriseWikiPage::PAGE_TYPE_ARTICLE;
        $first['target_page_title'] = $article->title;

        $second = $first;
        $second['target_heading'] = 'Krav og terskler for tjenesten';

        return [$this->createRun($customer, $patchDocument, [$first, $second]), ['article' => $article]];
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage} */
    private function sameBlockReplacementScenario(): array
    {
        $customer = $this->createCustomer();
        $oldDocument = $this->createDocument($customer, 'gammel-prosedyre.docx', 'Gammelt grunnlag. '.self::OLD_A.'. '.self::OLD_B.'.');
        $patchDocument = $this->createDocument($customer, 'endringsnotat.docx', $this->patchDocumentText());
        $page = $this->createPage($customer, 'Samlet prosedyre', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $this->createVersionWithBlocks($page, $oldDocument, [
            '# Samlet prosedyre',
            '## Krav og terskler',
            'Her gjelder '.self::OLD_A.'. '.self::UNRELATED_IN_SECTION.' I tillegg gjelder '.self::OLD_B.'.',
        ]);

        $first = $this->replaceTargetA();
        $first['target_page_id'] = $page->id;
        $first['target_page_title'] = $page->title;
        $first['target_page_type'] = EnterpriseWikiPage::PAGE_TYPE_ARTICLE;

        $second = $this->replaceTargetB();
        $second['target_page_id'] = $page->id;
        $second['target_page_title'] = $page->title;
        $second['target_page_type'] = EnterpriseWikiPage::PAGE_TYPE_ARTICLE;
        $second['target_heading'] = 'Krav og terskler';

        return [$this->createRun($customer, $patchDocument, [$first, $second]), $page];
    }

    /**
     * A summary with NO sub-sections — one H1 and a body. The shape run 28 could not patch at all.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage}
     */
    private function flatPageScenario(array $targets): array
    {
        $customer = $this->createCustomer();
        $oldDocument = $this->createDocument($customer, 'gammel.docx', 'Gammelt grunnlag. '.self::OLD_A.'.');
        $patchDocument = $this->createDocument($customer, 'endringsnotat.docx', $this->patchDocumentText());

        $this->createPage($customer, 'Nabotema Alfa', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'nabotema-alfa');

        $page = $this->createPage($customer, 'Sammendrag: Styrende prosedyre', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createVersionWithBlocks($page, $oldDocument, [
            '# Sammendrag: Styrende prosedyre',
            'Kort: her gjelder at '.self::OLD_A.'.',
            self::UNRELATED_SECTION.' Se ogsaa [[nabotema-alfa|Nabotema Alfa]].',
        ]);

        $bound = [];

        foreach ($targets as $target) {
            $target['target_page_id'] = $page->id;
            $target['target_page_type'] = $page->page_type;
            $target['target_page_title'] = $page->title;
            unset($target['_page']);
            $bound[] = $target;
        }

        return [$this->createRun($customer, $patchDocument, $bound), $page];
    }

    /** @return array<string, mixed> */
    private function flatReplaceTarget(): array
    {
        return array_merge($this->replaceTargetA(), [
            'target_topic' => 'Terskelverdi i sammendraget',
            'target_heading' => null,
            'superseded_substance' => 'Kort: her gjelder at '.self::OLD_A.'.',
            'replacement_substance' => 'Kort: her gjelder at '.self::NEW_A.'.',
        ]);
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage} */
    private function singlePageScenario(string $pageType): array
    {
        $customer = $this->createCustomer();
        $oldDocument = $this->createDocument($customer, 'gammel.docx', 'Gammelt grunnlag. '.self::OLD_A.'.');
        $patchDocument = $this->createDocument($customer, 'endringsnotat.docx', $this->patchDocumentText());

        $page = $this->createPage($customer, 'Side av type '.$pageType, $pageType);
        $this->createVersionWithBlocks($page, $oldDocument, [
            '# Side av type '.$pageType,
            '## Krav og terskler',
            'Her gjelder at '.self::OLD_A.'. '.self::UNRELATED_IN_SECTION,
        ]);

        $target = $this->replaceTargetA();
        $target['target_page_id'] = $page->id;
        $target['target_page_type'] = $pageType;
        $target['target_page_title'] = $page->title;

        return [$this->createRun($customer, $patchDocument, [$target]), $page];
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, EnterpriseWikiPage>  $pages
     * @return array<string, mixed>
     */
    private function bindTarget(array $target, array $pages): array
    {
        $key = $target['_page'] ?? 'article';
        unset($target['_page']);

        $page = $pages[$key];
        $target['target_page_id'] = $page->id;
        $target['target_page_type'] = $page->page_type;
        $target['target_page_title'] = $page->title;

        return $target;
    }

    private function patchDocumentText(): string
    {
        return 'Endringsnotat. Kravet endres slik at '.self::NEW_A.', som erstatter at '.self::OLD_A.'. '
            .'Videre endres fristen slik at '.self::NEW_B.', som erstatter at '.self::OLD_B.'. '
            .'Ny utvidende bestemmelse C2 gjelder fra neste periode.';
    }

    /** @return array<string, mixed> */
    private function replaceTargetA(): array
    {
        return [
            '_page' => 'article',
            'target_page_id' => 0,
            'target_page_title' => '',
            'target_page_type' => '',
            'target_topic' => 'Terskelverdi',
            'target_heading' => 'Krav og terskler',
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => self::OLD_A,
            'replacement_substance' => self::NEW_A,
            'source_element_keys' => ['paragraph-1'],
            'preserve_topics' => ['Klassifisering av hendelser'],
            'reason' => 'Kilden erstatter uttrykkelig terskelverdien.',
        ];
    }

    /** @return array<string, mixed> */
    private function replaceTargetB(): array
    {
        return array_merge($this->replaceTargetA(), [
            'target_topic' => 'Frist',
            'target_heading' => 'Frister',
            'superseded_substance' => self::OLD_B,
            'replacement_substance' => self::NEW_B,
            'source_element_keys' => ['paragraph-2'],
            'preserve_topics' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function replaceTargetOnSummary(): array
    {
        return array_merge($this->replaceTargetA(), ['_page' => 'summary']);
    }

    /** @return array<string, mixed> */
    private function amendTargetC(): array
    {
        return array_merge($this->replaceTargetA(), [
            'target_topic' => 'Ny bestemmelse',
            'target_heading' => 'Krav og terskler',
            'relationship' => 'topic_extended',
            'operation' => 'amend',
            'superseded_substance' => null,
            'replacement_substance' => 'Ny utvidende bestemmelse C2 gjelder fra neste periode.',
            'source_element_keys' => ['paragraph-1'],
        ]);
    }

    /** @return array<string, mixed> */
    private function preserveTarget(): array
    {
        return array_merge($this->replaceTargetA(), [
            '_page' => 'concept',
            'target_topic' => 'Begreper',
            'target_heading' => 'Begreper',
            'relationship' => 'reference_only',
            'operation' => 'preserve',
            'superseded_substance' => null,
            'replacement_substance' => null,
            'source_element_keys' => [],
            'preserve_topics' => [],
        ]);
    }

    /** @param list<array<string, mixed>> $targets */
    private function createRun(Customer $customer, EnterpriseWikiDocument $document, array $targets): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => 'manual',
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_json' => [
                'source_article' => null,
                'source_summary' => null,
                'concept_candidates' => [],
                'concept_pages' => [],
                'entity_pages' => [],
                'patch_targets' => $targets,
                'no_action_reason' => null,
                'warnings' => [],
            ],
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * A version whose blocks carry real provenance to $document — one block per markdown part, the
     * same shape ordinary generation produces.
     *
     * @param  list<string>  $parts
     */
    private function createVersionWithBlocks(EnterpriseWikiPage $page, EnterpriseWikiDocument $document, array $parts): EnterpriseWikiPageVersion
    {
        $blocks = [];

        foreach ($parts as $index => $markdown) {
            $isHeading = str_starts_with($markdown, '#');

            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'position' => $index,
                'markdown' => $markdown,
                'content_origin' => $isHeading ? 'structural' : 'source_based',
                'source_type' => $isHeading ? null : 'enterprise_wiki_document',
                'source_id' => $isHeading ? null : $document->id,
                'source_label' => $isHeading ? null : $document->original_filename,
                'source_hash' => $isHeading ? null : $document->file_hash_sha256,
                'document_version_hash' => $isHeading ? null : $document->file_hash_sha256,
                'source_element_key' => $isHeading ? null : 'paragraph-'.$index,
                'source_element_type' => $isHeading ? null : 'paragraph',
                'source_row_key' => null,
                'source_excerpt' => $isHeading ? null : $markdown,
                'page_reference' => $isHeading ? null : 'Seksjon',
                'source_elements' => $isHeading ? [] : [[
                    'source_type' => 'enterprise_wiki_document',
                    'source_id' => $document->id,
                    'source_label' => $document->original_filename,
                    'source_hash' => $document->file_hash_sha256,
                    'document_version_hash' => $document->file_hash_sha256,
                    'source_element_key' => 'paragraph-'.$index,
                    'source_element_type' => 'paragraph',
                    'source_row_key' => null,
                    'source_excerpt' => $markdown,
                    'page_reference' => 'Seksjon',
                ]],
                'best_practice_reason' => null,
                'link_intents' => [],
            ];
        }

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", $parts),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function createCustomer(string $name = 'Patch Apply AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    /**
     * A document backed by a REAL minimal .docx on the local disk, so
     * EnterpriseWikiDocumentSourceElementService yields a genuine addressable catalog
     * (paragraph-0, paragraph-1, …). Without that, the catalog would be empty and the patch service's
     * unstructured-document fallback would take over, which would quietly make the provenance and
     * unknown-key assertions meaningless. Same docx-building approach as tests/Support/Wiki*E2EFixture.
     */
    private function createDocument(Customer $customer, string $filename, string $text): EnterpriseWikiDocument
    {
        $paragraphs = array_map(
            static fn (string $line): string => '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>',
            array_values(array_filter(array_map('trim', explode('. ', $text)))),
        );

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .implode('', $paragraphs)
            .'</w:body></w:document>';

        $tmpPath = tempnam(sys_get_temp_dir(), 'patch-test-').'.docx';
        $zip = new \ZipArchive;
        $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $bytes = (string) file_get_contents($tmpPath);
        unlink($tmpPath);

        $filePath = 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx';
        Storage::disk('local')->put($filePath, $bytes);

        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => $filePath,
            'file_hash_sha256' => hash('sha256', $filename.Str::random(16)),
            'extracted_text' => $text,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $title, string $pageType, ?string $slug = null): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug ?? Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }
}
