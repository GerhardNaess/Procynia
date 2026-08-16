<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiWithdrawalNotRepresentableException;
use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a source document withdraws its substance from the ACTIVE Wiki.
 *
 * Deletion used to remove the document, its runs and the pages only it produced — and stop. Two
 * things survived, and run 63 is what that looks like from the outside: a SHARED page kept the
 * deleted document's paragraphs (only its source references were cleaned), and a page linking to a
 * deleted page kept `[[slug|anchor]]` in its markdown while the graph edge cascaded away. The Wiki
 * asserted knowledge from a document that no longer existed, and pointed at pages that no longer
 * existed — and a later run inherited the broken links and failed on them.
 *
 * Both are now closed deterministically, because provenance is atomic: withdrawal is a filter on
 * source_id and an exact-slug rewrite of link markup. No AI, no heuristics.
 *
 * V1 deliberately does not repair. A page that cannot be represented safely after filtering fails
 * the whole deletion closed and is named, so we can measure how often that happens before deciding
 * whether bounded regeneration is worth building.
 */
class EnterpriseWikiDocumentWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Sole-source pages: unchanged behaviour
    // =========================================================================

    public function test_a_sole_source_page_is_still_deleted_with_its_versions_and_claims(): void
    {
        $world = $this->world();
        $page = $world['sole_source_page'];
        $versionId = $this->currentVersion($page)->id;

        $result = $this->deleteDocument($world['document_b']);

        $this->assertFalse($result['blocked']);
        $this->assertSame(1, $result['sole_source_pages_deleted']);
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('enterprise_wiki_page_versions', ['id' => $versionId]);
        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $world['document_b']->id]);
    }

    // =========================================================================
    // Shared pages: the document's blocks leave, everything else stays
    // =========================================================================

    public function test_a_shared_page_keeps_the_other_documents_blocks(): void
    {
        $world = $this->world();
        $shared = $world['shared_page'];

        $this->deleteDocument($world['document_b']);

        $blocks = (array) $this->currentVersion($shared)->content_blocks_json;
        $markdowns = array_column($blocks, 'markdown');

        $this->assertContains('## Krav', $markdowns, 'the heading survives');
        $this->assertContains('Substans fra dokument A.', $markdowns);
        $this->assertContains('Anbefaling fra Procynia.', $markdowns, 'best practice is not document substance');
        $this->assertNotContains('Substans fra dokument B.', $markdowns);
    }

    public function test_only_the_deleted_documents_blocks_are_removed(): void
    {
        $world = $this->world();
        $shared = $world['shared_page'];
        $before = count((array) $this->currentVersion($shared)->content_blocks_json);

        $result = $this->deleteDocument($world['document_b']);

        $after = count((array) $this->currentVersion($shared)->content_blocks_json);

        $this->assertSame($before - 1, $after);
        $this->assertSame(1, $result['blocks_withdrawn']);
        $this->assertSame(1, $result['shared_pages_kept']);
    }

    public function test_an_a_b_a_split_page_loses_only_the_b_segment(): void
    {
        // The shape a sub-block replace leaves behind: prefix (A), correction (B), suffix (A).
        $world = $this->world(withSplitPage: true);
        $split = $world['split_page'];

        $this->deleteDocument($world['document_b']);

        $version = $this->currentVersion($split);
        $markdowns = array_column((array) $version->content_blocks_json, 'markdown');

        $this->assertSame(
            ['Innledningen gjelder generelt. Her gjelder', 'for alle hendelser. Klassifiseringen er uendret.'],
            $markdowns,
            'the A fragments survive verbatim; only the B correction is withdrawn',
        );
        $this->assertSame(
            "Innledningen gjelder generelt. Her gjelder\n\nfor alle hendelser. Klassifiseringen er uendret.",
            $version->content_markdown,
            'markdown and blocks stay consistent',
        );
    }

    public function test_no_surviving_block_carries_the_deleted_document(): void
    {
        $world = $this->world(withSplitPage: true);

        $this->deleteDocument($world['document_b']);

        foreach (EnterpriseWikiPageVersion::query()->where('is_current', true)->get() as $version) {
            foreach ((array) $version->content_blocks_json as $block) {
                $this->assertNotSame(
                    $world['document_b_id'],
                    $block['source_id'] ?? null,
                    "page {$version->enterprise_wiki_page_id} still carries a block from the deleted document",
                );
            }
        }
    }

    public function test_withdrawal_never_creates_multi_document_provenance(): void
    {
        $world = $this->world(withSplitPage: true);

        $this->deleteDocument($world['document_b']);

        foreach (EnterpriseWikiPageVersion::query()->where('is_current', true)->get() as $version) {
            foreach ((array) $version->content_blocks_json as $block) {
                if (($block['content_origin'] ?? null) !== EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
                    continue;
                }

                $this->assertCount(
                    1,
                    array_unique(array_column((array) ($block['source_elements'] ?? []), 'source_id')),
                    'a surviving block still cites exactly one document',
                );
            }
        }
    }

    // =========================================================================
    // Incoming links to pages that disappear
    // =========================================================================

    public function test_an_incoming_wikilink_becomes_plain_anchor_text(): void
    {
        $world = $this->world();
        $linking = $world['linking_page'];

        $result = $this->deleteDocument($world['document_b']);

        $version = $this->currentVersion($linking);

        $this->assertStringNotContainsString('[[', $version->content_markdown, 'no dead wikilink survives');
        $this->assertStringContainsString(
            'Se hendelseshåndtering for detaljer',
            $version->content_markdown,
            'the visible words stay exactly as they read',
        );
        $this->assertSame(1, $result['links_dematerialized']);
    }

    public function test_the_structured_link_intent_is_dropped_with_its_link(): void
    {
        $world = $this->world();

        $this->deleteDocument($world['document_b']);

        $blocks = (array) $this->currentVersion($world['linking_page'])->content_blocks_json;

        $this->assertSame([], $blocks[0]['link_intents'], 'markdown and structured metadata stay consistent');
    }

    public function test_a_link_to_a_surviving_page_in_the_same_block_is_untouched(): void
    {
        $world = $this->world(withSecondLink: true);

        $this->deleteDocument($world['document_b']);

        $version = $this->currentVersion($world['linking_page']);

        $this->assertStringContainsString('[[beholdt-side|beholdt side]]', $version->content_markdown);
        $this->assertStringNotContainsString('hendelseshandtering-drift', $version->content_markdown);
        $this->assertSame(
            [$world['kept_page_id']],
            array_column((array) $this->currentVersion($world['linking_page'])->content_blocks_json[0]['link_intents'], 'target_page_id'),
        );
    }

    public function test_norwegian_characters_in_the_anchor_survive_exactly(): void
    {
        $world = $this->world();

        $this->deleteDocument($world['document_b']);

        $this->assertStringContainsString(
            'Se hendelseshåndtering for detaljer',
            $this->currentVersion($world['linking_page'])->content_markdown,
        );
    }

    public function test_a_run_63_style_deletion_leaves_no_broken_wikilink_behind(): void
    {
        // Run 63: the target pages were deleted with an earlier document, the graph edges cascaded
        // away, and the prose kept pointing at slugs that no longer resolved — until a later run
        // inherited them and failed. After withdrawal there is nothing left to inherit.
        $world = $this->world();
        $doomedSlug = $world['sole_source_page']->slug;

        $this->deleteDocument($world['document_b']);

        foreach (EnterpriseWikiPageVersion::query()->where('is_current', true)->get() as $version) {
            $this->assertStringNotContainsString('[['.$doomedSlug, (string) $version->content_markdown);
        }

        $this->assertDatabaseMissing('enterprise_wiki_page_links', ['to_page_id' => $world['sole_source_page']->id]);
    }

    // =========================================================================
    // Claims, references, facts, history
    // =========================================================================

    public function test_source_references_and_canonical_facts_for_the_document_are_gone(): void
    {
        $world = $this->world();

        $this->deleteDocument($world['document_b']);

        $this->assertDatabaseMissing('enterprise_wiki_source_references', [
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $world['document_b_id'],
        ]);
        $this->assertDatabaseMissing('enterprise_wiki_canonical_facts', ['source_id' => $world['document_b_id']]);
    }

    public function test_a_shared_pages_claim_from_another_document_survives(): void
    {
        $world = $this->world();

        $this->deleteDocument($world['document_b']);

        $this->assertDatabaseHas('enterprise_wiki_claims', ['id' => $world['claim_a_id']]);
        $this->assertDatabaseHas('enterprise_wiki_source_references', [
            'enterprise_wiki_claim_id' => $world['claim_a_id'],
            'source_id' => $world['document_a_id'],
        ]);
    }

    public function test_historical_versions_are_kept_as_audit_history(): void
    {
        $world = $this->world();
        $shared = $world['shared_page'];
        $originalVersionId = $this->currentVersion($shared)->id;

        $this->deleteDocument($world['document_b']);

        $historical = EnterpriseWikiPageVersion::query()->find($originalVersionId);

        $this->assertNotNull($historical, 'the superseded version is never rewritten or removed');
        $this->assertFalse((bool) $historical->is_current);
        $this->assertStringContainsString(
            'Substans fra dokument B.',
            $historical->content_markdown,
            'history still records what the page said — the guarantee is about the ACTIVE Wiki',
        );
    }

    // =========================================================================
    // Fail closed
    // =========================================================================

    public function test_a_shared_page_left_without_substance_is_deleted_with_the_document(): void
    {
        // Page 206's shape: an earlier document created the page, a later one regenerated it, and now
        // that later document is being withdrawn. Current state is what decides — the page is held up
        // by this document alone today, so it goes with it. The deletion is never blocked.
        $world = $this->world(sharedPageOnlyHasDocumentB: true);
        $shared = $world['shared_page'];

        $result = $this->deleteDocument($world['document_b']);

        $this->assertFalse($result['blocked']);
        $this->assertSame(1, $result['pages_deleted_without_substance']);
        $this->assertSame(0, $result['shared_pages_kept']);
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $shared->id]);
        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $world['document_b_id']]);
    }

    public function test_an_earlier_version_from_a_surviving_document_is_never_restored(): void
    {
        // The page's history contains a version built from document A, which still exists. It is NOT
        // brought back: withdrawal decides on current state and never revives old versions — that
        // version's links and metadata belong to a Wiki that has moved on.
        $world = $this->world(sharedPageOnlyHasDocumentB: true, withEarlierVersionFromA: true);

        $this->deleteDocument($world['document_b']);

        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $world['shared_page']->id]);
        $this->assertSame(
            0,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $world['shared_page']->id)->count(),
            'the page and its history go together; nothing is resurrected as current',
        );
    }

    public function test_links_into_an_auto_deleted_page_are_dematerialized_like_any_other(): void
    {
        $world = $this->world(sharedPageOnlyHasDocumentB: true, withLinkToSharedPage: true);
        $sharedSlug = $world['shared_page']->slug;

        $result = $this->deleteDocument($world['document_b']);

        $version = $this->currentVersion($world['linking_page']);

        $this->assertStringNotContainsString('[['.$sharedSlug, $version->content_markdown);
        $this->assertSame(
            'Se hendelseshåndtering og kravsiden for detaljer.',
            $version->content_markdown,
            'both doomed links become their own visible words; nothing else moves',
        );
        $this->assertDatabaseMissing('enterprise_wiki_page_links', ['to_page_id' => $world['shared_page']->id]);
        $this->assertGreaterThanOrEqual(2, $result['links_dematerialized'], 'both doomed targets are cleaned');
    }

    public function test_other_current_pages_are_untouched_by_the_auto_deletion(): void
    {
        $world = $this->world(sharedPageOnlyHasDocumentB: true);
        $keptBefore = $this->currentVersion(EnterpriseWikiPage::query()->findOrFail($world['kept_page_id']))->content_markdown;

        $this->deleteDocument($world['document_b']);

        $this->assertSame(
            $keptBefore,
            $this->currentVersion(EnterpriseWikiPage::query()->findOrFail($world['kept_page_id']))->content_markdown,
        );
    }

    public function test_a_page_keeping_only_a_best_practice_clause_survives(): void
    {
        // Best practice carries no document provenance — it is Procynia's own contribution, routed to
        // human approval. A page still holding one is still saying something of its own.
        $world = $this->world(sharedPageKeepsOnlyBestPractice: true);

        $result = $this->deleteDocument($world['document_b']);

        $this->assertSame(0, $result['pages_deleted_without_substance']);
        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $world['shared_page']->id]);
        $this->assertSame(
            ['## Krav', 'Anbefaling fra Procynia.'],
            array_column((array) $this->currentVersion($world['shared_page'])->content_blocks_json, 'markdown'),
        );
    }

    public function test_the_integrity_check_catches_an_artificial_dangling_reference(): void
    {
        $world = $this->world();

        // A source reference the ordinary cleanup cannot see: it cites the document through a claim
        // on a page no run of this document ever touched. The verifier is the backstop for exactly
        // this — something the deterministic steps did not know to clean.
        $orphanPage = $this->page($world['customer'], 'Uavhengig side', 'uavhengig-side');
        $orphanVersion = $this->version($orphanPage, [$this->block('Tekst.', 'source_based', $world['document_a_id'])]);
        $claim = $this->claim($orphanPage, $orphanVersion, 'Tekst.');
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $world['document_b_id'],
            'source_label' => 'dokument-b.docx',
        ]);

        // The ordinary deletion DOES remove references by (source_type, source_id), so to prove the
        // verifier itself works we assert it on the state it is given.
        $this->assertDatabaseHas('enterprise_wiki_source_references', ['source_id' => $world['document_b_id']]);

        $this->expectException(EnterpriseWikiWithdrawalNotRepresentableException::class);
        $this->expectExceptionMessage('still cites document');

        app(EnterpriseWikiDocumentWithdrawalService::class)
            ->assertActiveWikiIsClean($world['document_b_id'], (int) $world['customer']->id, collect(), []);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string, mixed> */
    private function deleteDocument(EnterpriseWikiDocument $document): array
    {
        return app(EnterpriseWikiDocumentDeletionService::class)->delete($document, $this->actor($document));
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    /**
     * A customer with two documents: A survives, B is the one being deleted.
     *
     * @return array<string, mixed>
     */
    private function world(
        bool $withSplitPage = false,
        bool $withSecondLink = false,
        bool $sharedPageOnlyHasDocumentB = false,
        bool $withEarlierVersionFromA = false,
        bool $withLinkToSharedPage = false,
        bool $sharedPageKeepsOnlyBestPractice = false,
    ): array {
        $customer = $this->customer();
        $documentA = $this->document($customer, 'dokument-a.docx');
        $documentB = $this->document($customer, 'dokument-b.docx');
        $runA = $this->ingestRun($customer, $documentA);
        $runB = $this->ingestRun($customer, $documentB);

        // Shared: touched by both runs, so deletion must keep it and filter it.
        $shared = $this->page($customer, 'Krav', 'krav');
        $sharedBlocks = match (true) {
            $sharedPageOnlyHasDocumentB => [
                $this->block('## Krav', 'structural', null),
                $this->block('Substans fra dokument B.', 'source_based', $documentB->id),
            ],
            $sharedPageKeepsOnlyBestPractice => [
                $this->block('## Krav', 'structural', null),
                $this->block('Substans fra dokument B.', 'source_based', $documentB->id),
                $this->block('Anbefaling fra Procynia.', 'best_practice', null),
            ],
            default => [
                $this->block('## Krav', 'structural', null),
                $this->block('Substans fra dokument A.', 'source_based', $documentA->id),
                $this->block('Substans fra dokument B.', 'source_based', $documentB->id),
                $this->block('Anbefaling fra Procynia.', 'best_practice', null),
            ],
        };

        if ($withEarlierVersionFromA) {
            // History: the page once carried document A's substance, before B regenerated it.
            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $shared->id,
                'version_number' => 0,
                'is_current' => false,
                'content_markdown' => 'Eldre substans fra dokument A.',
                'content_blocks_json' => [$this->block('Eldre substans fra dokument A.', 'source_based', $documentA->id)],
                'generated_by_model' => 'gpt-5',
            ]);
        }

        $sharedVersion = $this->version($shared, $sharedBlocks);
        $this->pivot($runA, $shared);
        $this->pivot($runB, $shared);

        $claimA = $this->claim($shared, $sharedVersion, 'Substans fra dokument A.');
        $this->reference($claimA, $documentA);
        $claimB = $this->claim($shared, $sharedVersion, 'Substans fra dokument B.');
        $this->reference($claimB, $documentB);

        EnterpriseWikiCanonicalFact::query()->create([
            'customer_id' => $customer->id,
            'content_origin' => 'source_based',
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $documentB->id,
            'source_element_keys' => ['paragraph-0'],
            'source_element_keys_hash' => hash('sha256', 'paragraph-0'),
            'normalized_fingerprint' => hash('sha256', 'substans-b'),
            'canonical_text' => 'Substans fra dokument B.',
            'verification_status' => 'verified',
        ]);

        // Sole-source: only run B touched it, so it disappears with the document.
        $soleSource = $this->page($customer, 'Hendelseshåndtering (drift)', 'hendelseshandtering-drift');
        $this->version($soleSource, [$this->block('Prosessen beskrives her.', 'source_based', $documentB->id)]);
        $this->pivot($runB, $soleSource);

        $kept = $this->page($customer, 'Beholdt side', 'beholdt-side');
        $this->version($kept, [$this->block('Denne siden overlever.', 'source_based', $documentA->id)]);
        $this->pivot($runA, $kept);

        // Linking: survives, but points at the sole-source page.
        $linkMarkdown = match (true) {
            $withSecondLink => 'Se [[hendelseshandtering-drift|hendelseshåndtering]] for detaljer og [[beholdt-side|beholdt side]].',
            $withLinkToSharedPage => 'Se [[hendelseshandtering-drift|hendelseshåndtering]] og [[krav|kravsiden]] for detaljer.',
            default => 'Se [[hendelseshandtering-drift|hendelseshåndtering]] for detaljer.',
        };
        $linkIntents = $withSecondLink
            ? [
                ['intent_id' => 'l1', 'target_page_id' => $soleSource->id, 'anchor_text' => 'hendelseshåndtering', 'reason' => 'Peker til prosessen.'],
                ['intent_id' => 'l2', 'target_page_id' => $kept->id, 'anchor_text' => 'beholdt side', 'reason' => 'Peker til siden som overlever.'],
            ]
            : [['intent_id' => 'l1', 'target_page_id' => $soleSource->id, 'anchor_text' => 'hendelseshåndtering', 'reason' => 'Peker til prosessen.']];

        $linking = $this->page($customer, 'Oversikt', 'oversikt');
        $linkBlock = $this->block($linkMarkdown, 'source_based', $documentA->id);
        $linkBlock['link_intents'] = $linkIntents;
        $linkingVersion = $this->version($linking, [$linkBlock]);
        $this->pivot($runA, $linking);

        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $linking->id,
            'to_page_id' => $soleSource->id,
            'from_page_version_id' => $linkingVersion->id,
            'link_type' => 'wikilink',
            'source' => 'ai_generated',
            'confidence' => 'certain',
        ]);

        $splitPage = null;

        if ($withSplitPage) {
            $splitPage = $this->page($customer, 'Frister', 'frister');
            $this->version($splitPage, [
                $this->block('Innledningen gjelder generelt. Her gjelder', 'source_based', $documentA->id),
                $this->block('frister på 15 minutter', 'source_based', $documentB->id),
                $this->block('for alle hendelser. Klassifiseringen er uendret.', 'source_based', $documentA->id),
            ]);
            $this->pivot($runA, $splitPage);
            $this->pivot($runB, $splitPage);
        }

        return [
            'customer' => $customer,
            'document_a' => $documentA,
            'document_b' => $documentB,
            'document_a_id' => (int) $documentA->id,
            'document_b_id' => (int) $documentB->id,
            'shared_page' => $shared,
            'sole_source_page' => $soleSource,
            'linking_page' => $linking,
            'kept_page_id' => (int) $kept->id,
            'split_page' => $splitPage,
            'claim_a_id' => (int) $claimA->id,
            'claim_b_id' => (int) $claimB->id,
        ];
    }

    /** @return array<string, mixed> */
    private function block(string $markdown, string $origin, ?int $documentId): array
    {
        $block = [
            'block_key' => 'block-'.substr(md5($markdown), 0, 8),
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => $origin,
            'source_elements' => [],
            'best_practice_reason' => $origin === 'best_practice' ? 'Kilden mangler dette.' : null,
            'link_intents' => [],
        ];

        if ($documentId !== null) {
            $block['source_type'] = EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT;
            $block['source_id'] = $documentId;
            $block['source_element_key'] = 'paragraph-0';
            $block['source_elements'] = [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $documentId,
                'source_label' => "dokument-{$documentId}.docx",
                'source_element_key' => 'paragraph-0',
                'source_element_type' => 'paragraph',
                'source_excerpt' => $markdown,
            ]];
        }

        return $block;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function version(EnterpriseWikiPage $page, array $blocks): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function claim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }

    private function reference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): void
    {
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_element_key' => 'paragraph-0',
        ]);
    }

    private function pivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }

    private function page(Customer $customer, string $title, string $slug): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function ingestRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);
    }

    private function document(Customer $customer, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', $filename.Str::random(8)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Withdrawal AS',
            'slug' => 'withdrawal-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function actor(EnterpriseWikiDocument $document): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@withdrawal-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $document->customer_id,
            'is_active' => true,
        ]);
    }
}
