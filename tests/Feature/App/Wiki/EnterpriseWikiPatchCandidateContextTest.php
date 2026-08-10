<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 8K-1 — patch candidate discovery and existing-content context.
 *
 * The failure this guards against (run 25): an authoritative change document explicitly revised
 * requirements the Wiki already recorded, but produced only new pages. The old values stayed
 * current, so the Wiki ended up answering the same question two different ways depending on which
 * page you opened.
 *
 * The root cause was not the decision — the maintainer read the document as a change. It was that
 * the Wiki index carries a 200-character excerpt per page, and every superseded value sat well past
 * that. The maintainer knew the pages existed; it could not see what they said.
 *
 * 8K-1 is read-only: it makes the old requirements visible in the maintainer input. Whether the
 * decision then patches anything is 8K-2/8K-3, and is deliberately NOT asserted here.
 *
 * The fixture is domain-free — generic titles and invented threshold values — so it exercises the
 * mechanism rather than any customer's subject matter.
 */
class EnterpriseWikiPatchCandidateContextTest extends TestCase
{
    use RefreshDatabase;

    /** Old values deliberately placed far past the index excerpt's 200-character cut. */
    private const OLD_VALUE_A = 'terskelverdi 99,5 prosent';

    private const OLD_VALUE_B = 'frist paa 30 minutter';

    private const PADDING = 'Denne innledende teksten finnes utelukkende for aa skyve de konkrete kravene forbi utdraget som Wiki-indeksen viser, slik at de ikke kan oppdages fra metadata alene. Den beskriver bakgrunn, formaal og omfang i generelle vendinger uten aa tallfeste noe som helst, og fortsetter tilstrekkelig lenge til at grensen passeres med god margin.';

    // =========================================================================
    // Candidate discovery
    // =========================================================================

    public function test_discovers_an_existing_page_named_in_the_new_document(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $candidates = $this->service()->findForDocument($document);
        $titles = array_column($candidates, 'title');

        $this->assertContains('Plattform Alfa', $titles, 'a page named in the document must be a candidate');
    }

    public function test_reaches_a_procedure_page_one_wikilink_hop_away(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        // The change document never names this page, but it is linked to one it does name —
        // exactly the shape that made run 25's superseded values invisible.
        $titles = array_column($this->service()->findForDocument($document), 'title');

        $this->assertContains('Styrende prosedyre for Alfa', $titles);
    }

    public function test_pages_belonging_to_another_customer_are_never_candidates(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $other = $this->createCustomer('Annen Kunde AS');
        $foreign = $this->createPage($other, 'Plattform Alfa', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createVersion($foreign, "# Plattform Alfa\n\nHemmelig innhold fra en annen kunde.");

        $candidates = $this->service()->findForDocument($document);

        foreach ($candidates as $candidate) {
            $this->assertNotSame($foreign->id, $candidate['page_id'], 'cross-customer leakage');
        }

        $rendered = EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock($candidates);
        $this->assertStringNotContainsString('Hemmelig innhold', $rendered);
    }

    public function test_archived_superseded_and_rejected_pages_are_excluded(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        foreach ([
            EnterpriseWikiPage::STATUS_ARCHIVED,
            EnterpriseWikiPage::STATUS_SUPERSEDED,
            EnterpriseWikiPage::STATUS_REJECTED,
        ] as $index => $status) {
            $page = $this->createPage($customer, "Plattform Alfa arkiv {$index}", EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
            $page->update(['status' => $status]);
            $this->createVersion($page, "# Arkiv\n\nPlattform Alfa nevnes her.");
        }

        foreach ($this->service()->findForDocument($document) as $candidate) {
            $this->assertStringNotContainsString('arkiv', $candidate['title']);
        }
    }

    public function test_explicitly_excluded_pages_are_never_offered(): void
    {
        [$customer, $document, $pages] = $this->changeDocumentScenario();

        $candidates = $this->service()->findForDocument($document, [$pages['entity']->id]);

        $this->assertNotContains($pages['entity']->id, array_column($candidates, 'page_id'));
    }

    public function test_candidate_count_respects_the_cap(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        // Far more linked pages than the cap allows.
        $named = EnterpriseWikiPage::query()->where('title', 'Plattform Alfa')->firstOrFail();

        for ($i = 0; $i < 8; $i++) {
            $extra = $this->createPage($customer, "Tilknyttet side {$i}", EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
            $this->createVersion($extra, "# Tilknyttet {$i}\n\nInnhold.");
            $this->link($customer, $named, $extra);
        }

        $this->assertLessThanOrEqual(
            EnterpriseWikiPatchCandidateService::MAX_CANDIDATES,
            count($this->service()->findForDocument($document)),
        );
    }

    public function test_ordering_is_deterministic_and_byte_identical_across_calls(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $first = $this->service()->findForDocument($document);
        $second = $this->service()->findForDocument($document);

        $this->assertSame(array_column($first, 'page_id'), array_column($second, 'page_id'));
        $this->assertSame(
            EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock($first),
            EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock($second),
        );
    }

    public function test_directly_named_pages_outrank_linked_neighbours(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $candidates = $this->service()->findForDocument($document);

        $this->assertSame('Plattform Alfa', $candidates[0]['title'], 'a named page must come first');
        $this->assertGreaterThan(0, $candidates[0]['mention_count']);
    }

    // =========================================================================
    // Current content
    // =========================================================================

    public function test_the_current_version_is_used_and_historical_versions_are_not_sent(): void
    {
        [$customer, $document, $pages] = $this->changeDocumentScenario();

        // Supersede the entity page: the old version must never surface.
        app(EnterpriseWikiPageVersionWriter::class)
            ->writeNewCurrentVersion($pages['entity'], [
                'content_markdown' => "# Plattform Alfa\n\n".self::PADDING."\n\nOppdatert innhold uten historisk verdi.",
                'generated_by_model' => 'test',
            ]);

        $candidates = $this->service()->findForDocument($document);
        $entity = collect($candidates)->firstWhere('page_id', $pages['entity']->id);

        $this->assertNotNull($entity);
        $this->assertStringContainsString('Oppdatert innhold', $entity['content']);
        $this->assertStringNotContainsString(self::OLD_VALUE_A, $entity['content'], 'historical version leaked');

        $current = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pages['entity']->id)
            ->where('is_current', true)
            ->firstOrFail();

        $this->assertSame($current->id, $entity['page_version_id']);
    }

    public function test_content_is_truncated_deterministically_on_block_boundaries(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $named = EnterpriseWikiPage::query()->where('title', 'Plattform Alfa')->firstOrFail();
        $blocks = [];
        $markdown = [];

        for ($i = 0; $i < 40; $i++) {
            $text = "Avsnitt {$i}: ".str_repeat('innhold ', 40);
            $blocks[] = ['block_key' => sprintf('block-%04d', $i + 1), 'markdown' => $text];
            $markdown[] = $text;
        }

        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $named->id)
            ->update(['content_markdown' => implode("\n\n", $markdown), 'content_blocks_json' => json_encode($blocks)]);

        $first = collect($this->service()->findForDocument($document))->firstWhere('page_id', $named->id);
        $second = collect($this->service()->findForDocument($document))->firstWhere('page_id', $named->id);

        $this->assertTrue($first['truncated']);
        $this->assertLessThanOrEqual(EnterpriseWikiPatchCandidateService::MAX_CONTENT_CHARS, mb_strlen($first['content']));
        $this->assertSame($first['content'], $second['content'], 'truncation must be deterministic');
        // Cut on a block boundary: the last rendered block is whole.
        $this->assertStringEndsWith('innhold ', rtrim($first['content'])."\x20");
    }

    // =========================================================================
    // Run-25 regression: the superseded values become visible
    // =========================================================================

    public function test_maintainer_input_exposes_the_old_values_the_change_document_supersedes(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $rendered = EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock(
            $this->service()->findForDocument($document),
        );

        // Both superseded requirements sit far past the index excerpt's 200-character cut and were
        // invisible to the maintainer before 8K-1.
        $this->assertStringContainsString(self::OLD_VALUE_A, $rendered, 'old value A must be visible');
        $this->assertStringContainsString(self::OLD_VALUE_B, $rendered, 'old value B must be visible');

        // Existing page identity travels with the content, so a later phase can act on it.
        $this->assertStringContainsString('[page ', $rendered);
        $this->assertStringContainsString('current version:', $rendered);
    }

    public function test_the_old_values_are_not_visible_from_the_wiki_index_alone(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        // Guards the premise of the test above: without 8K-1 this information genuinely is absent.
        $index = app(EnterpriseWikiIndexContextService::class)->buildForCustomer($customer->id);
        $indexJson = (string) json_encode($index, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::OLD_VALUE_A, $indexJson);
        $this->assertStringNotContainsString(self::OLD_VALUE_B, $indexJson);
    }

    // =========================================================================
    // Prompt contract
    // =========================================================================

    public function test_no_candidates_renders_nothing_and_leaves_the_prompt_unchanged(): void
    {
        $this->assertSame('', EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock([]));
    }

    public function test_a_document_naming_no_existing_page_produces_no_candidates(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'Et dokument som ikke nevner noen eksisterende side i det hele tatt.');

        $page = $this->createPage($customer, 'Helt Urelatert Emne', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($page, "# Helt Urelatert Emne\n\nInnhold.");

        $this->assertSame([], $this->service()->findForDocument($document));
    }

    public function test_an_empty_document_produces_no_candidates(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, '');

        $this->assertSame([], $this->service()->findForDocument($document));
    }

    public function test_the_block_states_that_a_candidate_is_not_a_verdict(): void
    {
        [$customer, $document] = $this->changeDocumentScenario();

        $rendered = EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock(
            $this->service()->findForDocument($document),
        );

        $this->assertStringContainsString('EXISTING PAGE CANDIDATES', $rendered);
        $this->assertStringContainsString('not a verdict', $rendered);
        $this->assertStringContainsString('CURRENT content', $rendered);
    }

    // =========================================================================
    // Fixture
    // =========================================================================

    /**
     * An existing Wiki holding two superseded requirements, plus a change document that revises
     * both. The document names only the entity page; the procedure page holding both old values is
     * reachable one wikilink hop away — the shape run 25 actually had.
     *
     * @return array{0: Customer, 1: EnterpriseWikiDocument, 2: array<string, EnterpriseWikiPage>}
     */
    // =========================================================================
    // Signal 3 — substance match (added after run 27)
    // =========================================================================

    /**
     * The run-27 gap, as a fixture. The Wiki states one superseded value on four pages; a fifth page
     * is a graph neighbour of the named page but states nothing the document changes. Before the
     * substance signal, that neighbour took a candidate slot ahead of two pages that genuinely held
     * the old values, so they were never offered to the maintainer and never got a patch target.
     *
     * Domain-free: invented values, generic titles, no subject matter.
     */
    public function test_pages_stating_the_changed_substance_outrank_an_unaffected_graph_neighbour(): void
    {
        [, $document, $pages] = $this->staleOwnerScenario();

        $ranked = array_column($this->service()->findForDocument($document), 'page_id');

        $this->assertSame($pages['entity']->id, $ranked[0], 'the named page that also matches substance ranks first');
        $this->assertContains($pages['article']->id, $ranked, 'a page holding both old values must be a candidate');
        $this->assertContains($pages['summary']->id, $ranked, 'the summary holding both old values must be a candidate');
        $this->assertNotContains(
            $pages['neighbour']->id,
            $ranked,
            'a graph neighbour stating nothing the document changes must not take a slot from a stale owner',
        );
    }

    public function test_substance_match_is_reported_per_candidate(): void
    {
        [, $document, $pages] = $this->staleOwnerScenario();

        $byId = [];

        foreach ($this->service()->findForDocument($document) as $candidate) {
            $byId[$candidate['page_id']] = $candidate;
        }

        $this->assertGreaterThan(0, $byId[$pages['article']->id]['substance_match_count']);
        $this->assertArrayHasKey('neighbour_degree', $byId[$pages['article']->id]);
    }

    public function test_a_page_holding_the_changed_substance_is_found_without_any_graph_edge(): void
    {
        // Substance match must stand on its own: an unlinked page that states the superseded value is
        // exactly the page a change note silently invalidates. Own minimal fixture, so the assertion
        // is about reachability and not about competing for a capped slot.
        $customer = $this->createCustomer();

        $orphan = $this->createPage($customer, 'Frittstaaende regelside', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($orphan, "# Frittstaaende regelside\n\nHer gjelder ".self::OLD_VALUE_A.' og ingenting annet.');

        $document = $this->createDocument(
            $customer,
            'Endringsnotat. Maalsatt verdi endres fra '.self::OLD_VALUE_A.' til terskelverdi 99,7 prosent.',
        );

        $candidates = $this->service()->findForDocument($document);

        $this->assertSame([$orphan->id], array_column($candidates, 'page_id'), 'no wikilink edge and no title mention is required');
        $this->assertGreaterThan(0, $candidates[0]['substance_match_count']);
        $this->assertSame(0, $candidates[0]['mention_count']);
        $this->assertSame(0, $candidates[0]['neighbour_degree']);
    }

    public function test_substance_match_never_crosses_customer_boundaries(): void
    {
        [, $document] = $this->staleOwnerScenario();
        $otherCustomer = $this->createCustomer('Annen Kunde AS');
        $foreign = $this->createPage($otherCustomer, 'Fremmed regelside', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        // Identical superseded substance, different customer.
        $this->createVersion($foreign, "# Fremmed regelside\n\nHer gjelder ".self::OLD_VALUE_A.' og '.self::OLD_VALUE_B.'.');

        $ranked = array_column($this->service()->findForDocument($document), 'page_id');

        $this->assertNotContains($foreign->id, $ranked);
    }

    public function test_substance_match_reads_only_the_current_version(): void
    {
        [$customer, $document] = $this->staleOwnerScenario();
        $page = $this->createPage($customer, 'Side med historikk', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        // Superseded value lives only in a HISTORICAL version; the current one says nothing relevant.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'content_markdown' => "# Side med historikk\n\nGammelt innhold med ".self::OLD_VALUE_A.'.',
            'generated_by_model' => 'gpt-5',
        ]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Side med historikk\n\nHelt urelatert gjeldende innhold.",
            'generated_by_model' => 'gpt-5',
        ]);

        $ranked = array_column($this->service()->findForDocument($document), 'page_id');

        $this->assertNotContains($page->id, $ranked, 'a superseded value in history must not make a page a candidate');
    }

    public function test_substance_match_respects_the_status_filter(): void
    {
        [$customer, $document] = $this->staleOwnerScenario();

        foreach ([
            EnterpriseWikiPage::STATUS_ARCHIVED,
            EnterpriseWikiPage::STATUS_SUPERSEDED,
            EnterpriseWikiPage::STATUS_REJECTED,
        ] as $status) {
            $page = $this->createPage($customer, 'Utgaatt '.$status, EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
            $page->update(['status' => $status]);
            $this->createVersion($page, "# Utgaatt\n\nHer gjelder ".self::OLD_VALUE_A.'.');

            $ranked = array_column($this->service()->findForDocument($document), 'page_id');

            $this->assertNotContains($page->id, $ranked, "status [{$status}] must never be a candidate");
        }
    }

    public function test_substance_ranking_is_deterministic_across_calls(): void
    {
        [, $document] = $this->staleOwnerScenario();

        $first = $this->service()->findForDocument($document);
        $second = $this->service()->findForDocument($document);

        $this->assertSame(array_column($first, 'page_id'), array_column($second, 'page_id'));
        $this->assertSame(
            EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock($first),
            EnterpriseWikiMaintainerDecisionAiClient::existingPageCandidatesBlock($second),
        );
    }

    public function test_a_shared_number_alone_is_not_a_substance_match(): void
    {
        // The measured reason bigrams were chosen over bare numeric tokens: incidental numbers are
        // shared everywhere, and a page sharing only those must not outrank a real stale owner.
        [$customer, $document] = $this->staleOwnerScenario();
        $incidental = $this->createPage($customer, 'Side med tilfeldige tall', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        // Reuses the document's numbers, but never next to the same word.
        $this->createVersion($incidental, "# Side med tilfeldige tall\n\nKapittel 99,5 og vedlegg 30 beskriver noe helt annet.");

        $byId = [];

        foreach ($this->service()->findForDocument($document, []) as $candidate) {
            $byId[$candidate['page_id']] = $candidate['substance_match_count'];
        }

        $this->assertArrayNotHasKey($incidental->id, $byId, 'a bare shared number must not earn a candidate slot');
    }

    public function test_a_document_that_never_restates_an_old_value_still_uses_the_other_signals(): void
    {
        // Documented limitation: the substance signal fires only when the change document restates
        // what it supersedes. When it does not, discovery degrades to naming + graph hop exactly as
        // before — additive, never subtractive.
        [, $document, $pages] = $this->changeDocumentScenario();

        $ranked = array_column($this->service()->findForDocument($document), 'page_id');

        $this->assertContains($pages['entity']->id, $ranked, 'the named page is still found');
        $this->assertContains($pages['procedure']->id, $ranked, 'the graph hop still works');
    }

    /**
     * Four existing pages hold superseded substance (the shape run 27 actually had), plus one graph
     * neighbour that holds none. The document restates both old values while announcing the new ones,
     * which is what an authoritative change note does.
     *
     * @return array{0: Customer, 1: EnterpriseWikiDocument, 2: array<string, EnterpriseWikiPage>}
     */
    private function staleOwnerScenario(): array
    {
        $customer = $this->createCustomer();

        $entity = $this->createPage($customer, 'Plattform Alfa', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createVersion($entity, "# Plattform Alfa\n\n".self::PADDING."\n\nMaalsatt verdi er ".self::OLD_VALUE_A.'.');

        $article = $this->createPage($customer, 'Styrende prosedyre for Alfa', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($article, "# Styrende prosedyre for Alfa\n\n".self::PADDING
            ."\n\nMaalsatt verdi er ".self::OLD_VALUE_A.' og hendelser bekreftes innen '.self::OLD_VALUE_B.'.');

        $summary = $this->createPage($customer, 'Sammendrag: Styrende prosedyre for Alfa', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createVersion($summary, "# Sammendrag: Styrende prosedyre for Alfa\n\nKort: "
            .self::OLD_VALUE_A.' og '.self::OLD_VALUE_B.'.');

        $roles = $this->createPage($customer, 'Roller og ansvar generelt', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($roles, "# Roller og ansvar generelt\n\nAnsvarlig bekrefter innen ".self::OLD_VALUE_B.'.');

        // Related to the named page, but states nothing this document changes.
        $neighbour = $this->createPage($customer, 'Nabotema uten berort substans', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($neighbour, "# Nabotema uten berort substans\n\nHelt urelatert beskrivelse uten tallfestede krav.");

        $this->link($customer, $entity, $article);
        $this->link($customer, $entity, $neighbour);
        $this->link($customer, $entity, $summary);
        $this->link($customer, $entity, $roles);

        // Restates both old values, exactly as an authoritative change note does.
        $document = $this->createDocument(
            $customer,
            'Endringsnotat. Kravene for Plattform Alfa skjerpes. '
            .'Maalsatt verdi endres fra '.self::OLD_VALUE_A.' til terskelverdi 99,7 prosent. '
            .'Hendelser skal bekreftes innen 15 minutter, som erstatter tidligere frist paa 30 minutter. '
            .'Oevrige bestemmelser viderefoeres uendret.',
        );

        return [$customer, $document, compact('entity', 'article', 'summary', 'roles', 'neighbour')];
    }

    private function changeDocumentScenario(): array
    {
        $customer = $this->createCustomer();

        $entity = $this->createPage($customer, 'Plattform Alfa', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createVersion($entity, "# Plattform Alfa\n\n".self::PADDING."\n\nMaalsatt maanedlig ".self::OLD_VALUE_A.' for plattformen.');

        $procedure = $this->createPage($customer, 'Styrende prosedyre for Alfa', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($procedure, "# Styrende prosedyre for Alfa\n\n".self::PADDING."\n\nHendelser skal bekreftes innen ".self::OLD_VALUE_B.'. Maalsatt tilgjengelighet er '.self::OLD_VALUE_A.'.');

        // The canonical wikilink relation the discovery hop travels along.
        $this->link($customer, $entity, $procedure);

        $document = $this->createDocument(
            $customer,
            'Endringsnotat. Kravene for Plattform Alfa skjerpes. '
            .'Maalsatt tilgjengelighet settes til 99,7 prosent, opp fra tidligere verdi. '
            .'Hendelser skal bekreftes innen 15 minutter, som erstatter tidligere frist. '
            .'Oevrige bestemmelser viderefoeres uendret.',
        );

        return [$customer, $document, ['entity' => $entity, 'procedure' => $procedure]];
    }

    private function service(): EnterpriseWikiPatchCandidateService
    {
        return app(EnterpriseWikiPatchCandidateService::class);
    }

    private function createCustomer(string $name = 'Patch Candidate AS'): Customer
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

    private function createDocument(Customer $customer, string $text): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'endringsnotat.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $text,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $title, string $pageType): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
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

    private function link(Customer $customer, EnterpriseWikiPage $from, EnterpriseWikiPage $to): void
    {
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }
}
