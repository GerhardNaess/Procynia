<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Deleting a Wiki page from the Wiki — the canonical way knowledge leaves it.
 *
 * Until now a page could only be removed sideways, by deleting the source document behind it, which
 * takes every page that document alone produced. That is a document decision. This is the page's
 * own, for a page somebody is reading and has decided does not belong.
 *
 * What these tests pin is the boundary, because the boundary is the whole design: the page and the
 * data that is only ever the page's go, and nothing that was knowledge in its own right before the
 * deletion stops being knowledge after it. The source document stays, the ingest run stays, the
 * concepts and entities the page linked to stay, and the Kvalitet-prosess that was the source of it
 * stays with its provenance intact.
 */
class EnterpriseWikiPageDeletionTest extends TestCase
{
    use GrantsWikiPermissions;
    use RefreshDatabase;

    // =========================================================================
    // What the deletion takes
    // =========================================================================

    public function test_deleting_a_page_removes_the_page_and_everything_that_is_only_its_own(): void
    {
        $world = $this->world();
        $page = $world['page'];
        $versionId = $this->currentVersion($page)->id;
        $claimId = $world['claim_id'];
        $findingId = $world['finding_id'];

        $result = $this->deletePage($page, $world['actor']);

        $this->assertSame(1, $result['pages_deleted']);
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('enterprise_wiki_page_versions', ['id' => $versionId]);
        $this->assertDatabaseMissing('enterprise_wiki_claims', ['id' => $claimId]);
        $this->assertDatabaseMissing('enterprise_wiki_source_references', ['enterprise_wiki_claim_id' => $claimId]);
        $this->assertDatabaseMissing('enterprise_wiki_ingest_run_pages', ['enterprise_wiki_page_id' => $page->id]);
    }

    /**
     * enterprise_wiki_lint_findings.enterprise_wiki_page_id is nullOnDelete, so the database would
     * happily keep a finding about a page that no longer exists with every key nulled out. That is
     * the one row the service has to delete itself, and the one worth a test of its own.
     */
    public function test_the_pages_quality_findings_do_not_survive_it_as_orphans(): void
    {
        $world = $this->world();

        $result = $this->deletePage($world['page'], $world['actor']);

        $this->assertSame(1, $result['findings_deleted']);
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', ['id' => $world['finding_id']]);
    }

    // =========================================================================
    // What it never takes
    // =========================================================================

    public function test_the_source_document_and_its_ingest_run_survive_the_page(): void
    {
        $world = $this->world();

        $this->deletePage($world['page'], $world['actor']);

        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $world['document']->id]);
        $this->assertDatabaseHas('enterprise_wiki_ingest_runs', ['id' => $world['run']->id]);
    }

    public function test_the_pages_the_deleted_page_linked_to_are_knowledge_of_their_own_and_stay(): void
    {
        $world = $this->world();

        $this->deletePage($world['page'], $world['actor']);

        // The deleted page pointed AT this concept. Being referenced is not being owned.
        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $world['concept']->id]);
        $this->assertDatabaseHas('enterprise_wiki_page_versions', [
            'enterprise_wiki_page_id' => $world['concept']->id,
            'is_current' => true,
        ]);
    }

    public function test_the_quality_process_that_was_the_source_of_the_page_keeps_its_provenance(): void
    {
        $world = $this->world();

        $this->deletePage($world['page'], $world['actor']);

        $this->assertDatabaseHas('quality_items', ['id' => $world['quality_item']->id]);
        // The provenance row names the SOURCE DOCUMENT the activity produced, not a page — see
        // QualityActivityWikiPage. Deleting one of the pages the Wiki made of that document is not
        // a reason for the process to forget it ever produced anything.
        $this->assertDatabaseHas('quality_activity_wiki_pages', [
            'id' => $world['activity_provenance_id'],
            'enterprise_wiki_document_id' => $world['document']->id,
        ]);
    }

    // =========================================================================
    // No dead references left behind
    // =========================================================================

    public function test_a_page_that_linked_here_keeps_its_sentence_but_loses_the_link(): void
    {
        $world = $this->world();

        $result = $this->deletePage($world['page'], $world['actor']);

        $version = $this->currentVersion($world['linking_page']);
        $markdown = (string) $version->content_markdown;

        $this->assertStringNotContainsString('[[', $markdown, 'the wikilink markup is gone');
        $this->assertStringContainsString('hendelseshåndtering', $markdown, 'the words the reader saw are kept');
        $this->assertSame(1, $result['incoming_links_dematerialized']);
        $this->assertSame(1, $result['pages_rewritten']);

        $blocks = (array) $version->content_blocks_json;
        $this->assertSame([], $blocks[0]['link_intents'] ?? null, 'the structured intent goes with the link');
    }

    public function test_no_graph_edge_is_left_pointing_at_the_deleted_page(): void
    {
        $world = $this->world();

        $this->deletePage($world['page'], $world['actor']);

        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('to_page_id', $world['page']->id)->count());
        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('from_page_id', $world['page']->id)->count());
    }

    public function test_the_projection_is_told_about_the_deleted_page_and_about_the_page_it_rewrote(): void
    {
        Queue::fake();

        $world = $this->world();
        $customerId = (int) $world['customer']->id;

        $this->deletePage($world['page'], $world['actor']);

        Queue::assertPushed(
            ProjectEnterpriseWikiPageToGraph::class,
            fn (ProjectEnterpriseWikiPageToGraph $job): bool => $job->pageId === (int) $world['page']->id
                && $job->deletedForCustomerId === $customerId,
        );

        // Its own outgoing edges are what builds a page's node, so the page whose text was rewritten
        // has to be projected again or it keeps an edge to a page that is gone.
        Queue::assertPushed(
            ProjectEnterpriseWikiPageToGraph::class,
            fn (ProjectEnterpriseWikiPageToGraph $job): bool => $job->pageId === (int) $world['linking_page']->id
                && $job->deletedForCustomerId === null,
        );
    }

    // =========================================================================
    // Generic across page types — nothing here reads page_type
    // =========================================================================

    #[DataProvider('pageTypes')]
    public function test_every_wiki_page_type_is_deleted_the_same_way(string $pageType): void
    {
        $world = $this->world();
        $page = $this->page($world['customer'], 'Ordliste', 'ordliste-'.$pageType, $pageType);
        $this->version($page, [$this->block('Innhold.', 'source_based', $world['document']->id)]);

        $result = $this->deletePage($page, $world['actor']);

        $this->assertSame(1, $result['pages_deleted']);
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $page->id]);
    }

    public static function pageTypes(): array
    {
        return [
            'article' => [EnterpriseWikiPage::PAGE_TYPE_ARTICLE],
            'concept' => [EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
            'entity' => [EnterpriseWikiPage::PAGE_TYPE_ENTITY],
            'summary' => [EnterpriseWikiPage::PAGE_TYPE_SUMMARY],
        ];
    }

    // =========================================================================
    // The route: who may, and where it lands
    // =========================================================================

    public function test_a_system_owner_deletes_the_page_and_lands_back_on_the_wiki_list(): void
    {
        $world = $this->world();

        $response = $this->actingAs($world['actor'])
            ->delete('/app/wiki/'.$world['page']->slug);

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'pages']));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $world['page']->id]);
    }

    public function test_the_page_is_gone_from_the_wiki_list_afterwards(): void
    {
        $world = $this->world();
        $slug = $world['page']->slug;

        $this->actingAs($world['actor'])->delete('/app/wiki/'.$slug);

        $this->actingAs($world['actor'])->get('/app/wiki/'.$slug)->assertNotFound();
    }

    public function test_a_contributor_who_does_not_own_the_page_may_not_delete_it(): void
    {
        $world = $this->world();
        $contributor = $this->user($world['customer'], User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($contributor)
            ->delete('/app/wiki/'.$world['page']->slug)
            ->assertForbidden();

        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $world['page']->id]);
    }

    public function test_the_registered_page_owner_may_delete_their_own_page(): void
    {
        $world = $this->world();
        $owner = $this->user($world['customer'], User::BID_ROLE_CONTRIBUTOR);
        $world['page']->forceFill(['owner_user_id' => $owner->id])->save();

        $this->actingAs($owner)
            ->delete('/app/wiki/'.$world['page']->slug)
            ->assertRedirect(route('app.wiki.index', ['tab' => 'pages']));

        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $world['page']->id]);
    }

    public function test_a_user_from_another_customer_cannot_reach_the_page_at_all(): void
    {
        $world = $this->world();
        $stranger = $this->user($this->customer(), User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($stranger)
            ->delete('/app/wiki/'.$world['page']->slug)
            ->assertNotFound();

        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $world['page']->id]);
    }

    public function test_the_page_view_tells_the_frontend_whether_this_reader_may_delete(): void
    {
        $world = $this->world();
        $contributor = $this->user($world['customer'], User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($world['actor'])
            ->get('/app/wiki/'.$world['page']->slug)
            ->assertInertia(fn ($page) => $page->where('can_delete_page', true));

        $this->actingAs($contributor)
            ->get('/app/wiki/'.$world['page']->slug)
            ->assertInertia(fn ($page) => $page->where('can_delete_page', false));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function deletePage(EnterpriseWikiPage $page, User $actor): array
    {
        return app(EnterpriseWikiPageDeletionService::class)->deletePage($page, $actor);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->orderByDesc('version_number')
            ->firstOrFail();
    }

    /**
     * One page worth deleting, with one of everything the deletion has an opinion about: a source
     * document and the run that produced it, a concept it links to, a page that links to it, a
     * claim with a source reference, a quality finding, a run-page pivot row, and a Kvalitet
     * activity that was the source of the document behind it.
     */
    private function world(): array
    {
        $customer = $this->customer();
        $actor = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->document($customer);
        $run = $this->ingestRun($customer, $document);

        $concept = $this->page($customer, 'Hendelse', 'hendelse', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->version($concept, [$this->block('Et begrep som står på egne ben.', 'source_based', $document->id)]);
        $this->pivot($run, $concept);

        $page = $this->page($customer, 'Hendelseshåndtering', 'hendelseshandtering', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $pageVersion = $this->version($page, [$this->block('Prosessen beskrives her.', 'source_based', $document->id)]);
        $this->pivot($run, $page);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $pageVersion->id,
            'claim_text' => 'Prosessen beskrives her.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_element_key' => 'paragraph-0',
        ]);

        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => 'missing_source',
            'severity' => 'warning',
            'message' => 'Mangler kilde.',
            'status' => 'open',
            'detected_at' => now(),
        ]);

        // The page points AT the concept, and is pointed at BY the overview page.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $page->id,
            'to_page_id' => $concept->id,
            'from_page_version_id' => $pageVersion->id,
            'link_type' => 'wikilink',
            'source' => 'ai_generated',
            'confidence' => 'certain',
        ]);

        $linking = $this->page($customer, 'Oversikt', 'oversikt', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $linkBlock = $this->block('Se [[hendelseshandtering|hendelseshåndtering]] for detaljer.', 'source_based', $document->id);
        $linkBlock['link_intents'] = [[
            'intent_id' => 'l1',
            'target_page_id' => $page->id,
            'anchor_text' => 'hendelseshåndtering',
            'reason' => 'Peker til prosessen.',
        ]];
        $linkingVersion = $this->version($linking, [$linkBlock]);
        $this->pivot($run, $linking);

        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $linking->id,
            'to_page_id' => $page->id,
            'from_page_version_id' => $linkingVersion->id,
            'link_type' => 'wikilink',
            'source' => 'ai_generated',
            'confidence' => 'certain',
        ]);

        $qualityItem = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Hendelseshåndtering',
            'status' => QualityItem::STATUS_DRAFT,
            'created_by_user_id' => $actor->id,
        ]);

        $activityProvenance = QualityActivityWikiPage::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $qualityItem->id,
            'activity_key' => 'motta-melding',
            'enterprise_wiki_document_id' => $document->id,
            'created_by_user_id' => $actor->id,
        ]);

        return [
            'customer' => $customer,
            'actor' => $actor,
            'document' => $document,
            'run' => $run,
            'page' => $page,
            'concept' => $concept,
            'linking_page' => $linking,
            'claim_id' => (int) $claim->id,
            'finding_id' => (int) $finding->id,
            'quality_item' => $qualityItem,
            'activity_provenance_id' => (int) $activityProvenance->id,
        ];
    }

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

    private function pivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }

    private function page(Customer $customer, string $title, string $slug, string $pageType): EnterpriseWikiPage
    {
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

    private function document(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'hendelseshandtering.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(16)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Sletting AS',
            'slug' => 'sletting-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return $this->grantWikiPermissions($customer, User::query()->create([
            'name' => 'Bruker',
            'email' => Str::lower(Str::random(10)).'@page-delete-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]));
    }
}
