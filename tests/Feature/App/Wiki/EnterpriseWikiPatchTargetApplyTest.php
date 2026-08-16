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
use App\Services\EnterpriseWiki\EnterpriseWikiCanonicalOwnershipValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchSectionResolver;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 8K-2 — patch targets against the real database, the apply layer, and the destructive-update
 * guard.
 *
 * What the run observed after 8K-1 actually did, and what these tests lock down:
 *
 *  - `action=update` on an existing page created a generation pivot, which dispatched ordinary page
 *    generation, which writes a page from the NEW source document alone. The target page lost its own
 *    definition, an entire unrelated section, all of its original provenance and two wikilinks — and
 *    QA passed it. Nothing in the pipeline treated that as an error.
 *  - `syncReusedPage()` writes `page_type` to the slot's type, so addressing an existing article
 *    through a typed slot silently retyped it.
 *
 * 8K-2 does not patch anything. It makes the intent machine-readable and makes the destructive path
 * unreachable for a page the decision patches. That second half is what most of this file asserts:
 * no version written, no pivot created, no page identity changed.
 *
 * Fixture is domain-free: generic titles, invented substance values A/B/C/D.
 */
class EnterpriseWikiPatchTargetApplyTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_A = 'terskelverdien er 99 enheter';

    private const NEW_A = 'terskelverdien er 120 enheter';

    private const OLD_B = 'fristen er 30 minutter';

    private const NEW_B = 'fristen er 15 minutter';

    private const UNRELATED = 'Denne siden beskriver ogsaa formaal og omfang, som dette dokumentet ikke berorer i det hele tatt.';

    /** Stated verbatim on every fixture page, and named verbatim by target()'s superseded_substance. */
    private const SUPERSEDED_SENTENCE = 'Siden oppgir at terskelverdien er 99 enheter.';

    /** The second shared sentence, for targets about the deadline rather than the threshold. */
    private const SUPERSEDED_SENTENCE_B = 'Siden oppgir at fristen er 30 minutter.';

    // =========================================================================
    // Target resolution — the database is the only authority
    // =========================================================================

    public function test_an_arbitrary_existing_article_can_be_targeted(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($pages['article'], 'article'),
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame($pages['article']->id, $result['resolved'][0]['target_page_id']);
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $result['resolved'][0]['page_type']);
    }

    public function test_an_arbitrary_existing_summary_can_be_targeted(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($pages['summary'], 'summary'),
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_SUMMARY, $result['resolved'][0]['page_type']);
    }

    public function test_concept_and_entity_pages_can_be_targeted_without_retyping(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($pages['concept'], 'concept'),
            $this->target($pages['entity'], 'entity'),
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY],
            array_column($result['resolved'], 'page_type'),
        );
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_CONCEPT, $pages['concept']->fresh()->page_type);
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ENTITY, $pages['entity']->fresh()->page_type);
    }

    public function test_cross_customer_target_is_rejected(): void
    {
        [$customer] = $this->existingWiki();
        $otherCustomer = $this->createCustomer('Annen Kunde AS');
        $foreignPage = $this->createPage($otherCustomer, 'Fremmed Side', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($foreignPage, "# Fremmed Side\n\nInnhold.");

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($foreignPage, 'article'),
        ]));

        $this->assertSame([], $result['resolved'], 'a foreign page must never resolve');
        $this->assertStringContainsString('belongs to another customer', implode(' | ', $result['errors']));
    }

    public function test_nonexistent_page_id_is_rejected(): void
    {
        [$customer] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($this->createPage($customer, 'Midlertidig', EnterpriseWikiPage::PAGE_TYPE_ARTICLE), 'article'), [
                'target_page_id' => 987654,
            ]),
        ]));

        $this->assertStringContainsString('does not exist', implode(' | ', $result['errors']));
    }

    public function test_unavailable_page_is_rejected_per_existing_status_rules(): void
    {
        [$customer] = $this->existingWiki();

        foreach ([
            EnterpriseWikiPage::STATUS_ARCHIVED,
            EnterpriseWikiPage::STATUS_SUPERSEDED,
            EnterpriseWikiPage::STATUS_REJECTED,
        ] as $status) {
            $page = $this->createPage($customer, 'Utgaatt '.$status, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
            $page->update(['status' => $status]);
            $this->createVersion($page, "# Utgaatt\n\nInnhold.");

            $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
                $this->target($page, 'article'),
            ]));

            $this->assertStringContainsString(
                'is not live Wiki knowledge',
                implode(' | ', $result['errors']),
                "status [{$status}] must not be patchable",
            );
        }
    }

    public function test_page_without_a_current_version_is_rejected(): void
    {
        [$customer] = $this->existingWiki();
        $page = $this->createPage($customer, 'Uten Versjon', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($page, 'concept'),
        ]));

        $this->assertStringContainsString('has no current version', implode(' | ', $result['errors']));
    }

    public function test_stated_page_type_is_verified_against_the_database(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        // The model claims the article is an entity. The row wins, and the claim is an error.
        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($pages['article'], 'entity'),
        ]));

        $this->assertStringContainsString('does not match the stored page type', implode(' | ', $result['errors']));
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $pages['article']->fresh()->page_type);
    }

    public function test_target_heading_must_exist_on_the_current_version(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $valid = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($pages['article'], 'article'), ['target_heading' => 'Krav og terskler']),
        ]));
        $invalid = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($pages['article'], 'article'), ['target_heading' => 'Finnes Ikke']),
        ]));

        $this->assertSame([], $valid['errors'], 'an existing heading is a valid anchor');
        $invalidError = implode(' | ', $invalid['errors']);

        $this->assertStringContainsString('issue_code=invalid_target_heading', $invalidError);
        $this->assertStringContainsString('page_has_subsections=true', $invalidError);
        $this->assertStringContainsString('valid_target_headings=[', $invalidError);
        $this->assertStringContainsString('is not a heading on the current version', $invalidError);
    }

    public function test_a_flat_page_accepts_a_target_with_no_heading(): void
    {
        // The Wiki has no stable section identifier, so a descriptor-only target has to stay
        // expressible — and on a page with no sub-sections it is the only honest form. Run 28 showed
        // a real summary losing all four of its targets to the older rule.
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            $this->target($pages['summary'], 'summary'),
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['resolved'][0]['target_heading']);
        $this->assertSame('Terskelverdi', $result['resolved'][0]['target_topic']);
    }

    public function test_a_sectioned_page_rejects_a_target_with_no_heading(): void
    {
        // The other half of the rule: once a page HAS sub-sections, "somewhere on this page" is not a
        // bounded area, and the flat-page fallback must not fire. Rejecting it at decision time is what
        // gives the bounded repair pass a chance to name the heading.
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($pages['article'], 'article'), ['target_heading' => null]),
        ]));

        $joined = implode(' | ', $result['errors']);

        $this->assertStringContainsString('does not identify a section', $joined);
        $this->assertStringContainsString('which does have sub-sections', $joined);
    }

    public function test_a_superseded_substance_that_is_not_present_is_rejected_at_decision_time(): void
    {
        // THE RUN-28 REGRESSION. The maintainer quoted a clause as if it were a whole sentence:
        // the page states "... innen 30 minutter, driftsleder skal varsle ...", the decision wrote
        // "... innen 30 minutter." Before this check the decision validated, was persisted, and only
        // failed later inside the patch engine — after the bounded repair pass could have fixed it.
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($pages['article'], 'article'), [
                'superseded_substance' => 'Siden oppgir at terskelverdien er 99 enheter og noe mer som ikke staar der.',
            ]),
        ]));

        $joined = implode(' | ', $result['errors']);

        $this->assertStringContainsString('is not present verbatim', $joined);
        $this->assertStringContainsString('under heading [Krav og terskler]', $joined);
        $this->assertStringContainsString('The relevant target area currently states', $joined, 'the repair pass needs the real wording');
        $this->assertStringContainsString(self::SUPERSEDED_SENTENCE, $joined, 'the context must show the actual text');
        $this->assertStringContainsString('copying an EXACT substring', $joined);
        $this->assertStringContainsString('does not have to be a whole sentence', $joined, 'a shorter exact substring must be allowed');
        $this->assertStringContainsString('a different page or a different target', $joined, 'cross-target contamination must be forbidden');
        $this->assertStringContainsString('do not move', $joined, 'the issue must forbid escaping into warnings');
    }

    public function test_a_large_target_area_is_windowed_around_the_relevant_text(): void
    {
        // Beyond the whole-area limit the context must still centre on the part the maintainer is
        // trying to correct — showing the opening of a long page is exactly what failed in run 29.
        $customer = $this->createCustomer();
        $filler = str_repeat('Denne innledende teksten beskriver bakgrunn og omfang uten aa tallfeste noe som helst. ', 40);
        $needle = 'Terskelverdien er 99 enheter, maalt paa tvers av hele tjenesten.';

        $page = $this->createPage($customer, 'Lang Prosedyre', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($page, "# Lang Prosedyre\n\n## Krav og terskler\n\n".$filler."\n\n".$needle."\n\n".$filler);

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($page, 'article'), [
                'target_heading' => 'Krav og terskler',
                // A near-miss quote of the real sentence — enough to anchor the window on it.
                'superseded_substance' => 'Terskelverdien er 99 enheter.',
            ]),
        ]));

        $joined = implode(' | ', $result['errors']);

        $this->assertStringContainsString('is not present verbatim', $joined);
        $this->assertStringContainsString($needle, $joined, 'the window must be centred on the relevant text, not the page opening');
        $this->assertStringContainsString('...', $joined, 'a windowed context is marked as truncated');
    }

    public function test_a_large_area_without_any_anchor_still_produces_bounded_context(): void
    {
        // Nothing in the given substance occurs in the area: the fallback must stay deterministic and
        // bounded rather than dumping the whole page.
        $customer = $this->createCustomer();
        $filler = str_repeat('Denne teksten beskriver bakgrunn og omfang uten tallfesting. ', 60);

        $page = $this->createPage($customer, 'Lang Prosedyre', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($page, "# Lang Prosedyre\n\n## Krav og terskler\n\n".$filler);

        $decision = $this->decisionTargeting([
            array_merge($this->target($page, 'article'), [
                'target_heading' => 'Krav og terskler',
                'superseded_substance' => 'Zzzzq Yyyyw Xxxxr.',
            ]),
        ]);

        $first = implode(' | ', $this->resolver()->resolveForCustomer($customer->id, $decision)['errors']);
        $second = implode(' | ', $this->resolver()->resolveForCustomer($customer->id, $decision)['errors']);

        $this->assertStringContainsString('is not present verbatim', $first);
        $this->assertSame($first, $second, 'the fallback must be deterministic');
        $this->assertLessThan(2600, mb_strlen($first), 'the context must stay bounded');
    }

    public function test_substance_verification_only_applies_to_replace(): void
    {
        // amend adds; preserve mutates nothing. Neither claims existing substance, so neither is
        // checked — the scope stays exactly where run 28 showed it was needed.
        [$customer, , $pages] = $this->existingWiki();

        $result = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([
            array_merge($this->target($pages['article'], 'article'), [
                'operation' => 'amend',
                'relationship' => 'topic_extended',
                'superseded_substance' => null,
                'replacement_substance' => 'Ny presisering.',
            ]),
            array_merge($this->target($pages['entity'], 'entity'), [
                'operation' => 'preserve',
                'relationship' => 'reference_only',
                'superseded_substance' => null,
                'replacement_substance' => null,
                'source_element_keys' => [],
            ]),
        ]));

        $this->assertSame([], $result['errors']);
    }

    public function test_decision_validation_and_the_patch_engine_agree_on_the_area(): void
    {
        // The alignment run 28 was missing: whatever the resolver accepts here, the engine must be
        // able to locate. A target the validator passes must not die inside the patch.
        [$customer, , $pages] = $this->existingWiki();

        foreach ([$pages['article'], $pages['summary'], $pages['concept'], $pages['entity']] as $page) {
            $target = $this->target($page, $page->page_type);

            $errors = $this->resolver()->resolveForCustomer($customer->id, $this->decisionTargeting([$target]))['errors'];

            $this->assertSame([], $errors, "validation accepted nothing for [{$page->page_type}]");

            // The engine's own resolver must find the same area without throwing.
            $blocks = [];

            foreach (preg_split("/\n{2,}/", trim((string) $page->currentVersion->content_markdown)) ?: [] as $part) {
                $blocks[] = ['markdown' => trim((string) $part)];
            }

            $area = app(EnterpriseWikiPatchSectionResolver::class)->resolve(
                $blocks,
                $target['target_heading'],
                $target['target_topic'],
                'engine',
            );

            $this->assertLessThanOrEqual($area['end_index'], $area['start_index']);
        }
    }

    // =========================================================================
    // Apply — a patch target gets no pivot, so generation can never reach it
    // =========================================================================

    public function test_apply_creates_no_pivot_row_for_a_patch_target(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $run = $this->runWithDecision($customer, $document, $this->decisionTargeting([
            $this->target($pages['article'], 'article'),
            $this->target($pages['summary'], 'summary'),
        ]));

        $result = $this->applyService()->apply($run);

        $this->assertSame(2, $result['patch_targets_deferred']);

        foreach ([$pages['article'], $pages['summary']] as $page) {
            $this->assertSame(
                0,
                EnterpriseWikiIngestRunPage::query()
                    ->where('enterprise_wiki_ingest_run_id', $run->id)
                    ->where('enterprise_wiki_page_id', $page->id)
                    ->count(),
                'a patch target must not get a generation pivot — that absence is the safety mechanism',
            );
        }
    }

    public function test_apply_leaves_the_target_page_content_and_identity_untouched(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $article = $pages['article'];
        $before = $article->replicate();
        $versionsBefore = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count();

        $run = $this->runWithDecision($customer, $document, $this->decisionTargeting([$this->target($article, 'article')]));
        $this->applyService()->apply($run);

        $after = $article->fresh();

        $this->assertSame($before->page_type, $after->page_type, 'page_type must survive targeting');
        $this->assertSame($before->title, $after->title, 'title must survive targeting');
        $this->assertSame($before->slug, $after->slug, 'slug must survive targeting');
        $this->assertSame(
            $versionsBefore,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
            '8K-2 writes no page version — patch application is 8K-3',
        );
        $this->assertStringContainsString(self::OLD_A, $after->currentVersion->content_markdown ?? '');
    }

    public function test_apply_still_creates_the_document_article_and_summary(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $run = $this->runWithDecision($customer, $document, $this->decisionTargeting([$this->target($pages['article'], 'article')]));

        $result = $this->applyService()->apply($run);

        $this->assertSame(2, $result['created'], 'the document still legitimately gets its own article and summary');
        $this->assertSame(1, $result['patch_targets_deferred']);
    }

    public function test_apply_refuses_a_decision_that_both_plans_and_patches_one_page(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $decision = $this->decisionTargeting([$this->target($pages['entity'], 'entity')]);
        $decision['entity_pages'] = [$this->pageEntry([
            'action' => 'update',
            'page_id' => $pages['entity']->id,
            'title' => $pages['entity']->title,
            'proposed_slug' => $pages['entity']->slug,
        ])];
        $run = $this->runWithDecision($customer, $document, $decision);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/never both/');

        $this->applyService()->apply($run);
    }

    public function test_update_through_a_mismatched_typed_slot_is_refused_instead_of_retyping(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $article = $pages['article'];
        $decision = $this->decisionTargeting([]);
        // An existing ARTICLE addressed by explicit page_id through the concept slot. Before 8K-2 this
        // silently rewrote the page's type to "concept".
        $decision['concept_pages'] = [$this->pageEntry([
            'action' => 'update',
            'page_id' => $article->id,
            'title' => $article->title,
            'proposed_slug' => $article->slug,
        ])];
        $run = $this->runWithDecision($customer, $document, $decision);

        try {
            $this->applyService()->apply($run);
            $this->fail('a mismatched typed slot must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('type is never changed by the slot', $e->getMessage());
        }

        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $article->fresh()->page_type);
    }

    public function test_slug_resolution_still_repairs_a_misclassified_page_type(): void
    {
        // The pre-existing repair path (a partially-completed earlier run) is deliberately kept: it
        // resolves by canonical slug, not by an explicit page_id, so it is not the retyping hazard.
        [$customer, $document] = $this->existingWiki();
        $mistyped = $this->createPage($customer, 'Feilklassifisert', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'feilklassifisert-ab1c2d');

        $decision = $this->decisionTargeting([]);
        $decision['source_article'] = $this->pageEntry([
            'action' => 'update',
            'title' => 'Feilklassifisert',
            'proposed_slug' => 'feilklassifisert-ab1c2d',
        ], source: true);
        $run = $this->runWithDecision($customer, $document, $decision);

        $this->applyService()->apply($run);

        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $mistyped->fresh()->page_type);
    }

    public function test_decision_without_patch_targets_applies_exactly_as_before(): void
    {
        [$customer, $document] = $this->existingWiki();
        $decision = $this->decisionTargeting([]);
        unset($decision['patch_targets']);
        $run = $this->runWithDecision($customer, $document, $decision);

        $result = $this->applyService()->apply($run);

        $this->assertSame(['created' => 2, 'updated' => 0, 'patch_targets_deferred' => 0], $result);
    }

    // =========================================================================
    // The destructive-update guard — the safety gate until 8K-3
    // =========================================================================

    public function test_generation_is_blocked_for_a_patch_target_and_writes_no_version(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $article = $pages['article'];
        $run = $this->runWithDecision($customer, $document, $this->decisionTargeting([$this->target($article, 'article')]));

        // Force the exact state 8K-2 makes structurally impossible: a pivot row for a patch-targeted
        // page. This is the regression gate — reaching generatePageForRun() must never regenerate the
        // page from the source document alone.
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        $markdownBefore = $article->currentVersion->content_markdown;
        $versionsBefore = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count();

        try {
            app(EnterpriseWikiGenerateAppliedPagesService::class)->generatePageForRun($run->fresh(), $article);
            $this->fail('full-page regeneration of a patch target must be blocked');
        } catch (EnterpriseWikiPatchTargetRegenerationBlockedException $e) {
            $this->assertSame($article->id, $e->pageId);
            $this->assertStringContainsString('must not be regenerated from source', $e->getMessage());
        }

        $article->refresh();

        $this->assertSame(
            $versionsBefore,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
            'no new current version may be written by the old full-generation path',
        );
        $this->assertSame($markdownBefore, $article->currentVersion->content_markdown, 'existing content must not be overwritten');
        $this->assertStringContainsString(self::UNRELATED, $article->currentVersion->content_markdown, 'unrelated substance must survive');
    }

    public function test_blocked_generation_never_claims_the_pivot_or_calls_ai(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $article = $pages['article'];
        $run = $this->runWithDecision($customer, $document, $this->decisionTargeting([$this->target($article, 'article')]));
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        $pivot = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        try {
            app(EnterpriseWikiGenerateAppliedPagesService::class)->generatePageForRun($run->fresh(), $article);
        } catch (EnterpriseWikiPatchTargetRegenerationBlockedException) {
            // Expected — the assertion below is that it stopped before doing anything at all.
        }

        $pivot->refresh();

        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING, $pivot->generation_status);
        $this->assertNull($pivot->generation_claim_token, 'the guard must run before the lease is claimed');
        $this->assertNull($pivot->generated_page_version_id);
    }

    public function test_a_page_that_is_not_a_patch_target_is_unaffected_by_the_guard(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();
        $run = $this->runWithDecision($customer, $document, $this->decisionTargeting([$this->target($pages['article'], 'article')]));

        // The untouched concept page is not targeted; the guard must not fire for it. Generation
        // itself needs AI, so assert on the guard's own decision rather than a full generation run.
        $targetIds = EnterpriseWikiPatchTargetResolver::targetPageIds((array) $run->maintainer_decision_json);

        $this->assertContains($pages['article']->id, $targetIds);
        $this->assertNotContains($pages['untouched']->id, $targetIds);
    }

    // =========================================================================
    // The run-26-shaped regression fixture, end to end through the contract
    // =========================================================================

    public function test_a_change_document_can_express_every_target_it_needs(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();

        // Old value A sits current on the article, the summary and the entity page.
        // Old value B sits current on the article, the summary and the concept page.
        // C is a new sub-topic that belongs on an existing page. D is genuinely independent.
        $decision = $this->decisionTargeting([
            $this->target($pages['article'], 'article', topic: 'Terskelverdi'),
            $this->target($pages['summary'], 'summary', topic: 'Terskelverdi'),
            $this->target($pages['entity'], 'entity', topic: 'Terskelverdi'),
            $this->target($pages['article'], 'article', topic: 'Frist', superseded: self::SUPERSEDED_SENTENCE_B, newValue: self::NEW_B, key: 'paragraph-4'),
            $this->target($pages['summary'], 'summary', topic: 'Frist', superseded: self::SUPERSEDED_SENTENCE_B, newValue: self::NEW_B, key: 'paragraph-4'),
            $this->target($pages['concept'], 'concept', topic: 'Frist', superseded: self::SUPERSEDED_SENTENCE_B, newValue: self::NEW_B, key: 'paragraph-4'),
            // C — a sub-topic of an existing topic: amended onto the owner, never a new page.
            array_merge($this->target($pages['concept'], 'concept', topic: 'Undertema C'), [
                'relationship' => 'topic_specialized',
                'operation' => 'amend',
                'superseded_substance' => null,
                'replacement_substance' => 'Undertema C presiserer hvordan hovedtemaet gjelder i et saerskilt tilfelle.',
                'source_element_keys' => ['paragraph-5'],
                'preserve_topics' => ['Definisjonen av hovedtemaet'],
            ]),
            // The untouched neighbour: examined, deliberately left alone.
            array_merge($this->target($pages['untouched'], 'concept', topic: 'Ikke beroert'), [
                'relationship' => 'reference_only',
                'operation' => 'preserve',
                'superseded_substance' => null,
                'replacement_substance' => null,
                'source_element_keys' => [],
            ]),
        ]);

        // D — genuinely new and self-standing, so create remains allowed.
        $decision['concept_candidates'] = [$this->candidateEntry([
            'name' => 'Selvstendig Tema D',
            'decision' => 'create',
            'relationship' => 'independent_new_topic',
            'existing_owner_page_id' => null,
        ])];
        $decision['concept_pages'] = [$this->pageEntry([
            'title' => 'Selvstendig Tema D',
            'proposed_slug' => 'selvstendig-tema-d',
        ])];
        $decision['warnings'] = [];

        $ownershipIssues = app(EnterpriseWikiCanonicalOwnershipValidator::class)->findIssues(
            $decision,
            $this->indexContextFor($pages),
            ['paragraph-3', 'paragraph-4', 'paragraph-5'],
        );
        $resolution = $this->resolver()->resolveForCustomer($customer->id, $decision);

        $this->assertSame([], $ownershipIssues, 'the whole run-26-shaped decision must be expressible');
        $this->assertSame([], $resolution['errors']);
        $this->assertCount(8, $resolution['resolved']);

        // Every existing owner of the superseded substance is named — not just one of them.
        $this->assertSame(
            [$pages['article']->id, $pages['summary']->id, $pages['entity']->id, $pages['concept']->id, $pages['untouched']->id],
            array_values(array_unique(array_column($resolution['resolved'], 'target_page_id'))),
        );

        // Both changed requirements carry old value, new value and authorising source elements.
        $replaces = array_values(array_filter($decision['patch_targets'], fn (array $t): bool => $t['operation'] === 'replace'));
        $this->assertCount(6, $replaces);

        foreach ($replaces as $target) {
            $this->assertNotEmpty($target['superseded_substance']);
            $this->assertNotEmpty($target['replacement_substance']);
            $this->assertNotEmpty($target['source_element_keys']);
        }

        // And applying it writes no content anywhere: 8K-2 records intent only.
        $run = $this->runWithDecision($customer, $document, $decision);
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        $result = $this->applyService()->apply($run);

        $this->assertSame(8, $result['patch_targets_deferred']);
        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count(), '8K-2 writes no page version');
        $this->assertStringContainsString(self::OLD_A, $pages['article']->fresh()->currentVersion->content_markdown);
        $this->assertStringContainsString(self::OLD_B, $pages['article']->fresh()->currentVersion->content_markdown);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function resolver(): EnterpriseWikiPatchTargetResolver
    {
        return app(EnterpriseWikiPatchTargetResolver::class);
    }

    private function applyService(): EnterpriseWikiMaintainerDecisionApplyService
    {
        return app(EnterpriseWikiMaintainerDecisionApplyService::class);
    }

    /**
     * A small existing Wiki holding the superseded substance in several places at once — the shape
     * the observed run actually had.
     *
     * @return array{0: Customer, 1: EnterpriseWikiDocument, 2: array<string, EnterpriseWikiPage>}
     */
    private function existingWiki(): array
    {
        $customer = $this->createCustomer();

        // Every page states SUPERSEDED_SENTENCE verbatim, because target() names exactly that as the
        // substance it supersedes. Since Fase 8K-3's correction, EnterpriseWikiPatchTargetResolver
        // verifies that at decision time, so a fixture whose targets quote text the page does not
        // contain would (correctly) be rejected.
        $article = $this->createPage($customer, 'Styrende Prosedyre', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($article, implode("\n\n", [
            '# Styrende Prosedyre',
            self::UNRELATED,
            '## Krav og terskler',
            self::SUPERSEDED_SENTENCE.' '.self::SUPERSEDED_SENTENCE_B,
        ]));

        $summary = $this->createPage($customer, 'Sammendrag: Styrende Prosedyre', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createVersion($summary, "# Sammendrag: Styrende Prosedyre\n\nKort: ".self::SUPERSEDED_SENTENCE.' '.self::SUPERSEDED_SENTENCE_B);

        $concept = $this->createPage($customer, 'Generelt Hovedtema', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($concept, "# Generelt Hovedtema\n\n".self::SUPERSEDED_SENTENCE.' '.self::SUPERSEDED_SENTENCE_B);

        $entity = $this->createPage($customer, 'Plattform Alfa', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createVersion($entity, "# Plattform Alfa\n\n".self::SUPERSEDED_SENTENCE.' '.self::SUPERSEDED_SENTENCE_B);

        $untouched = $this->createPage($customer, 'Nabotema Uten Endring', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($untouched, "# Nabotema Uten Endring\n\nHelt urelatert innhold som ingen endring beroerer.");

        $document = $this->createDocument($customer);

        return [$customer, $document, compact('article', 'summary', 'concept', 'entity', 'untouched')];
    }

    /** @param array<string, EnterpriseWikiPage> $pages @return list<array<string, mixed>> */
    private function indexContextFor(array $pages): array
    {
        return array_values(array_map(fn (EnterpriseWikiPage $page): array => [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'page_type' => $page->page_type,
        ], $pages));
    }

    /** @param list<array<string, mixed>> $targets @return array<string, mixed> */
    private function decisionTargeting(array $targets): array
    {
        return [
            'source_article' => $this->pageEntry(['title' => 'Endringsnotat', 'proposed_slug' => 'endringsnotat-ab1c2d'], source: true),
            'source_summary' => $this->pageEntry(['title' => 'Sammendrag: Endringsnotat', 'proposed_slug' => 'sammendrag-endringsnotat-ab1c2d'], source: true),
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'patch_targets' => $targets,
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    /** @return array<string, mixed> */
    /**
     * The article fixture has a `## Krav og terskler` section, so a target on it must name that
     * heading — a descriptor-only target on a SECTIONED page is not locatable and is rejected at
     * decision time since 8K-3. The flat fixture pages (summary/concept/entity) legitimately pass
     * null and resolve against the page body.
     */
    private function target(
        EnterpriseWikiPage $page,
        string $statedType,
        string $topic = 'Terskelverdi',
        string $superseded = self::SUPERSEDED_SENTENCE,
        string $newValue = self::NEW_A,
        string $key = 'paragraph-3',
    ): array {
        return [
            'target_page_id' => $page->id,
            'target_page_title' => $page->title,
            'target_page_type' => $statedType,
            'target_topic' => $topic,
            'target_heading' => $page->page_type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE ? 'Krav og terskler' : null,
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => $superseded,
            'replacement_substance' => 'Kilden fastsetter at '.$newValue.'.',
            'source_element_keys' => [$key],
            'preserve_topics' => [],
            'reason' => 'Kilden erstatter uttrykkelig den gamle verdien.',
        ];
    }

    /** @return array<string, mixed> */
    private function pageEntry(array $overrides = [], bool $source = false): array
    {
        $entry = array_merge([
            'action' => 'create',
            'page_id' => null,
            'title' => 'Nytt Tema',
            'proposed_slug' => 'nytt-tema',
            'reason' => 'x',
            'owned_topics' => ['Temaet selv'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ], $overrides);

        if ($source) {
            unset($entry['page_id']);
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private function candidateEntry(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nytt Tema',
            'concept_type' => 'praksis',
            'independent_reason' => 'Egen definert praksis.',
            'mentioned_context' => 'seksjon 4',
            'existing_page_title' => null,
            'decision' => 'create',
            'justification' => 'Kilden beskriver praksisen.',
            'owning_page_title' => null,
            'necessary_for_article' => true,
            'has_separate_source_evidence' => true,
            'has_reuse_value' => true,
            'relationship' => 'independent_new_topic',
            'existing_owner_page_id' => null,
        ], $overrides);
    }

    private function runWithDecision(Customer $customer, EnterpriseWikiDocument $document, array $decision): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => 'manual',
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createCustomer(string $name = 'Patch Target AS'): Customer
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'endringsnotat.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Endringsnotat. Kilden fastsetter at '.self::NEW_A.', og at '.self::NEW_B.'.',
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

    private function createVersion(EnterpriseWikiPage $page, string $markdown): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);
    }
}
