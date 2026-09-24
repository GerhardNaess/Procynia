<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Wiki tells the user where a page stands and what happens next.
 *
 * The publishing mechanism itself is unchanged and covered elsewhere (see
 * EnterpriseWikiPublishedVersionTest, EnterpriseWikiSourceOwnerGateTest). What these tests protect
 * is that the answer reaches the screen, and that it stays true to the gates the controller
 * actually enforces — because a status that quietly disagrees with what submit() and approve() do
 * is worse than no status at all.
 */
class EnterpriseWikiPublicationVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * The headline number. A Wiki full of drafts looks busy and produces nothing for tender
     * drafting, and until now no screen said so.
     */
    public function test_the_page_list_counts_how_much_of_the_wiki_is_published(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);
        $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);
        $published = $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_APPROVED);
        $published->forceFill(['published_version_id' => $published->currentVersion()->first()->id])->save();

        $summary = $this->pagesProps($owner)['publication_summary'];

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['published']);
        $this->assertSame(2, $summary['draft']);
        $this->assertSame(0, $summary['in_review']);
    }

    /**
     * §17's nuance: a page that WAS published and has newer work is not "published" in the sense a
     * reader of the summary cares about — the work in hand has not reached anyone.
     */
    public function test_a_page_with_newer_work_is_not_counted_as_published(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_APPROVED);
        $v1 = $page->currentVersion()->first();
        $page->forceFill(['published_version_id' => $v1->id])->save();

        // A later run: v2 becomes current, the page returns to draft, v1 keeps serving readers.
        $v1->forceFill(['is_current' => false])->save();
        $this->version($page, 2, isCurrent: true);
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        $summary = $this->pagesProps($owner)['publication_summary'];

        $this->assertSame(0, $summary['published'], 'the work in hand is not published');
        $this->assertSame(1, $summary['unpublished_changes']);
    }

    public function test_each_row_carries_its_own_state_and_next_step(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);

        $row = $this->pagesProps($owner)['pages'][0];

        $this->assertSame('draft', $row['publication']['state']);
        $this->assertArrayHasKey('next_step_label', $row['publication']);
        $this->assertNotSame('', $row['publication']['next_step_label']);
        $this->assertSame([], $row['publication']['blocking_reasons']);
    }

    /**
     * The distinction the whole change rests on. Neither submit() nor approve() reads claims, so
     * unapproved claims are reported as quality status and must never appear as a blocker — saying
     * otherwise would invent a publication rule the domain does not have.
     */
    public function test_unapproved_claims_never_become_a_publication_blocker(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        // A second person who can approve Wiki pages, because submit() refuses a reviewer who is
        // the submitter — without one the page is genuinely blocked, which is a different test.
        $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);
        $this->claim($page, 'pending');
        $this->claim($page, 'pending');
        $this->claim($page, 'approved');

        $listRow = $this->pagesProps($owner)['pages'][0];
        $this->assertSame([], $listRow['publication']['blocking_reasons']);
        $this->assertSame(3, $listRow['claims_count']);
        $this->assertSame(1, $listRow['claims_approved_count']);

        $publication = $this->showProps($owner, $page)['publication'];
        $this->assertSame([], $publication['blocking_reasons']);
        $this->assertSame(3, $publication['claims_total']);
        $this->assertSame(1, $publication['claims_approved'], 'reported as quality, counted the same way as the list');
    }

    public function test_the_page_view_states_where_the_page_stands(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);

        $publication = $this->showProps($owner, $page)['publication'];

        $this->assertSame('draft', $publication['state']);
        $this->assertFalse($publication['has_published_version']);
        $this->assertFalse($publication['has_unpublished_changes']);
    }

    /**
     * A published page keeps serving readers while newer work is drafted. Showing it as a plain
     * draft would say the customer has no approved knowledge on this subject, which is false.
     */
    public function test_a_published_page_with_newer_work_still_reports_its_published_version(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_APPROVED);
        $v1 = $page->currentVersion()->first();
        $page->forceFill(['published_version_id' => $v1->id])->save();
        $v1->forceFill(['is_current' => false])->save();
        $this->version($page, 2, isCurrent: true);
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        $publication = $this->showProps($owner, $page->fresh())['publication'];

        $this->assertSame('published_with_changes', $publication['state']);
        $this->assertTrue($publication['has_published_version']);
        $this->assertTrue($publication['has_unpublished_changes']);
        $this->assertSame(1, $publication['published_version_number']);
        $this->assertSame(2, $publication['working_version_number']);
    }

    /**
     * The publication status must come out of relations the list already loads.
     *
     * The bound is not zero growth: documentOwnerSummaryForPage() has its own per-row lookup that
     * predates this change (previewRequirementsForPageVersion() re-reads the page for each version),
     * and fixing that is a separate piece of work. What this guards is that publication status did
     * not add another one on top — letting publishedVersion or currentVersion.reviewer fall back to
     * a lazy load would cost a query per row and break this immediately.
     */
    public function test_publication_status_adds_no_query_per_page(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        for ($i = 0; $i < 2; $i++) {
            $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);
        }

        $twoPages = $this->countQueries(fn () => $this->pagesProps($owner));

        for ($i = 0; $i < 6; $i++) {
            $this->pageWithVersion($customer, $owner, EnterpriseWikiPage::STATUS_DRAFT);
        }

        $eightPages = $this->countQueries(fn () => $this->pagesProps($owner));

        $addedRows = 6;

        $this->assertLessThanOrEqual(
            $twoPages + $addedRows,
            $eightPages,
            "six more rows must cost well under one extra query each: {$twoPages} → {$eightPages}",
        );
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        \DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }

    /** @return array<string, mixed> */
    private function pagesProps(User $user): array
    {
        $response = $this->actingAs($user)->get('/app/wiki?tab=pages');
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function showProps(User $user, EnterpriseWikiPage $page): array
    {
        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}");
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function pageWithVersion(Customer $customer, User $owner, string $status): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'publiseringsstatus-'.Str::lower(Str::random(8)),
            'title' => 'Publiseringsstatus '.Str::random(4),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);

        $this->version($page, 1, isCurrent: true);

        return $page->fresh();
    }

    private function version(EnterpriseWikiPage $page, int $number, bool $isCurrent): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => $isCurrent,
            'content_markdown' => "# Versjon {$number}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function claim(EnterpriseWikiPage $page, string $approvalStatus): void
    {
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $page->currentVersion()->first()->id,
            'claim_text' => 'Vi er sertifisert.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => $approvalStatus,
            'position_order' => 0,
        ]);
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@publiseringsstatus.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Publiseringsstatus AS',
            'slug' => 'publiseringsstatus-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
