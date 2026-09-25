<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationSettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one rule every non-review writer obeys: a new version never publishes itself, and an older
 * published version never silently stops serving.
 *
 * The three cases differ only in who wrote the version. A person who may approve publishes by
 * saving; anyone else, and any machine, produces a working version the approved one outlives.
 */
class EnterpriseWikiPublicationSettlementServiceTest extends TestCase
{
    use DatabaseTransactions;

    private EnterpriseWikiPublicationSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnterpriseWikiPublicationSettlementService;
    }

    // ── Automated content change ────────────────────────────────────────────

    public function test_an_automated_change_to_a_published_page_returns_it_to_draft(): void
    {
        [$page, $v1] = $this->publishedPage();
        $v2 = $this->version($page, 2);

        $this->assertTrue($this->service->afterAutomatedContentChange($page->id));

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame((int) $v1->id, (int) $page->published_version_id, 'the approved version keeps serving');
        $this->assertSame((int) $v2->id, (int) $this->currentVersionId($page));
    }

    /** The machine may propose knowledge. It may not approve it. */
    public function test_an_automated_change_never_publishes_itself(): void
    {
        [$page, $v1] = $this->publishedPage();
        $v2 = $this->version($page, 2);

        $this->service->afterAutomatedContentChange($page->id);

        $this->assertNotSame((int) $v2->id, (int) $page->fresh()->published_version_id);
    }

    public function test_the_previously_published_version_is_left_exactly_as_it_was(): void
    {
        [$page, $v1] = $this->publishedPage();
        $markdown = $v1->content_markdown;
        $this->version($page, 2);

        $this->service->afterAutomatedContentChange($page->id);

        $v1->refresh();
        $this->assertSame($markdown, $v1->content_markdown);
        $this->assertSame(1, (int) $v1->version_number);
        $this->assertSame(2, EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->count(), 'history is kept, not replaced');
    }

    /** First publication is a decision of its own, and no writer here is a way around it. */
    public function test_a_page_that_was_never_published_is_left_alone(): void
    {
        $page = $this->page(EnterpriseWikiPage::STATUS_DRAFT);
        $this->version($page, 1);

        $this->assertFalse($this->service->afterAutomatedContentChange($page->id));

        $page->refresh();
        $this->assertNull($page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
    }

    /**
     * A page in review has a named reviewer holding an open handover. Neither publishing under them
     * nor cancelling it belongs in a writer — the review flow's own guard already refuses to
     * approve a version that changed after submission, which is the honest outcome there.
     */
    public function test_a_page_in_review_is_not_touched(): void
    {
        [$page, $v1] = $this->publishedPage();
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();

        $this->assertFalse($this->service->afterAutomatedContentChange($page->id));

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertSame((int) $v1->id, (int) $page->published_version_id);
    }

    // ── Manual edits, unchanged from 9933c7e ────────────────────────────────

    public function test_an_approver_editing_a_published_page_publishes_by_saving(): void
    {
        [$page] = $this->publishedPage();
        $v2 = $this->version($page, 2);
        $approver = $this->user(isWikiApprover: true);

        $this->assertTrue($this->service->afterManualEdit($page, $v2, $approver));

        $page->refresh();
        $this->assertSame((int) $v2->id, (int) $page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame((int) $approver->id, (int) $page->reviewed_by_user_id);
    }

    public function test_any_other_editor_leaves_the_published_version_serving(): void
    {
        [$page, $v1] = $this->publishedPage();
        $v2 = $this->version($page, 2);

        $this->assertFalse($this->service->afterManualEdit($page, $v2, $this->user()));

        $page->refresh();
        $this->assertSame((int) $v1->id, (int) $page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status, 'so it can be sent for review');
    }

    /**
     * The difference between the two paths is exactly one capability, and nothing else. Asserted
     * side by side because the whole design rests on it.
     */
    public function test_the_only_difference_between_the_two_editors_is_the_capability(): void
    {
        [$pageA] = $this->publishedPage();
        $vA = $this->version($pageA, 2);
        [$pageB] = $this->publishedPage();
        $vB = $this->version($pageB, 2);

        $this->service->afterManualEdit($pageA, $vA, $this->user(isWikiApprover: true));
        $this->service->afterManualEdit($pageB, $vB, $this->user(isQa: true));

        $this->assertSame((int) $vA->id, (int) $pageA->fresh()->published_version_id);
        $this->assertNotSame((int) $vB->id, (int) $pageB->fresh()->published_version_id, 'QA is not article authority');
    }

    // ── Coverage ────────────────────────────────────────────────────────────

    /**
     * Every writer that can make a new version current is classified, deliberately, one way or the
     * other. A new one added without a decision fails here rather than quietly publishing itself.
     *
     * TECHNICAL means the reader's knowledge does not change: a structural article/summary pairing
     * link restored to what generation should have produced, or block provenance rebuilt on the
     * existing version. Sending a published page back for review over those would be bureaucracy
     * with no question to answer. Everything else changes what the page says.
     */
    public function test_every_version_writer_is_classified(): void
    {
        $semantic = [
            'EnterpriseWikiClaimContentRepairService.php',      // manual edits + automated claim-content repair
            'EnterpriseWikiSemanticRepairService.php',          // AI rewrites the article
            'EnterpriseWikiLinkSemanticRepairService.php',      // AI adds, removes and re-anchors links in prose
            'EnterpriseWikiIncrementalRelinkService.php',       // AI weaves a link into another page's prose
            'EnterpriseWikiPatchApplicationService.php',        // corrected requirements
            'EnterpriseWikiDocumentWithdrawalService.php',      // content removed with its source document
        ];

        $technical = [
            // Restores the structural article/summary pairing link; navigation, not knowledge.
            'EnterpriseWikiArticleSummaryLinkRepairService.php',
            // Generation writes into pages the apply/ingest step already created as drafts.
            'EnterpriseWikiGenerateAppliedPagesService.php',
            // The mechanism itself.
            'EnterpriseWikiPageVersionWriter.php',
            // Human-initiated: a maintainer picks the target from documented candidates.
            'EnterpriseWikiOrphanConceptLinkService.php',
            // Listed because its docblock names another writer, not because it is one: it rebuilds
            // content_blocks_json on the EXISTING current version and never creates a new one.
            'EnterpriseWikiPageVersionBlockProvenanceRepairService.php',
        ];

        $writers = [];

        foreach (glob(app_path('Services/EnterpriseWiki/*.php')) as $path) {
            $source = file_get_contents($path);

            if (preg_match('/(?<!function )writeNewCurrentVersion(RestoringBlocks)?\(/', $source)) {
                $writers[] = basename($path);
            }
        }

        sort($writers);
        $classified = $semantic;
        array_push($classified, ...$technical);
        sort($classified);

        $this->assertSame(
            $classified,
            $writers,
            'a writer was added or removed without deciding whether it changes the reader\'s knowledge',
        );

        foreach ($semantic as $file) {
            $this->assertStringContainsString(
                'afterAutomatedContentChange(',
                file_get_contents(app_path('Services/EnterpriseWiki/'.$file)),
                "{$file} changes what the page says, so it must settle publication",
            );
        }

        foreach ($technical as $file) {
            $this->assertStringNotContainsString(
                'afterAutomatedContentChange(',
                file_get_contents(app_path('Services/EnterpriseWiki/'.$file)),
                "{$file} is technical maintenance and must not force a review",
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion} */
    private function publishedPage(): array
    {
        $page = $this->page(EnterpriseWikiPage::STATUS_APPROVED);
        $v1 = $this->version($page, 1);
        $page->forceFill(['published_version_id' => $v1->id])->save();

        return [$page, $v1];
    }

    private function currentVersionId(EnterpriseWikiPage $page): int
    {
        return (int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->value('id');
    }

    private function page(string $status): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $this->customer()->id,
            'slug' => 'oppgjor-'.Str::lower(Str::random(8)),
            'title' => 'Publiseringsoppgjør',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function version(EnterpriseWikiPage $page, int $number): EnterpriseWikiPageVersion
    {
        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->update(['is_current' => false]);

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => true,
            'content_markdown' => "# Versjon {$number}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function user(bool $isWikiApprover = false, bool $isQa = false): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@oppgjor.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $this->customer()->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
            'is_qa' => $isQa,
        ]);
    }

    private ?Customer $customer = null;

    private function customer(): Customer
    {
        if ($this->customer !== null) {
            return $this->customer;
        }

        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return $this->customer = Customer::query()->create([
            'name' => 'Publiseringsoppgjør AS',
            'slug' => 'oppgjor-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
