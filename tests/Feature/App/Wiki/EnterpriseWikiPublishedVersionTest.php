<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
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
 * A page has two versions in play, answering different questions.
 *
 * `is_current` is the WORKING version — what QA, lint, link building, patching and claim extraction
 * operate on. Roughly forty readers depend on that meaning, so it is unchanged.
 *
 * `published_version_id` is the version readers may rely on. It moves only on approval. Before this
 * split, FinalizeEnterpriseWikiIngest made brand-new unreviewed content current, which meant a
 * page's authoritative content changed the moment a run finished — approval described something
 * that was already published.
 *
 * See docs/enterprise-wiki-approval-model.md §6.
 */
class EnterpriseWikiPublishedVersionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // B. a page nobody has approved has nothing published
    public function test_a_new_page_has_no_published_version(): void
    {
        [, , $page] = $this->pageWithWorkingVersion();

        $this->assertNull($page->published_version_id);
        $this->assertFalse($page->hasPublishedVersion());
        $this->assertNotNull($page->currentVersion()->first(), 'but it does have a working version');
    }

    // C. approving publishes the working version
    public function test_approving_publishes_the_working_version(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $working = $page->currentVersion()->first();

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame($working->id, (int) $page->published_version_id);
    }

    // A. a new run replaces the working version and leaves the published one alone
    public function test_a_new_working_version_does_not_disturb_the_published_one(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $v1 = $page->currentVersion()->first();

        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/approve");
        $page->refresh();
        $this->assertSame($v1->id, (int) $page->published_version_id);

        // A later run: v2 becomes the working version, exactly as the ingest does.
        $v2 = $this->version($page, 2, isCurrent: false);
        $v1->forceFill(['is_current' => false])->save();
        $v2->forceFill(['is_current' => true])->save();
        EnterpriseWikiPage::query()->whereKey($page->id)->update(['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $v2->forceFill(['submitted_by_user_id' => null, 'submitted_at' => null, 'reviewer_user_id' => null])->save();
        $this->handOver($page->fresh(), $customer, User::query()->findOrFail($page->owner_user_id));

        $page->refresh();
        $this->assertSame($v2->id, (int) $page->currentVersion()->first()->id, 'v2 is the working version');
        $this->assertSame(
            $v1->id,
            (int) $page->published_version_id,
            'readers still get v1 — the version that was actually approved',
        );
    }

    // C + D. approving the new version moves publication, atomically, and only ever to one version
    public function test_approving_the_new_version_moves_publication(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $v1 = $page->currentVersion()->first();
        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/approve");

        $v2 = $this->version($page, 2, isCurrent: false);
        $v1->forceFill(['is_current' => false])->save();
        $v2->forceFill(['is_current' => true])->save();
        EnterpriseWikiPage::query()->whereKey($page->id)->update(['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $v2->forceFill(['submitted_by_user_id' => null, 'submitted_at' => null, 'reviewer_user_id' => null])->save();
        $this->handOver($page->fresh(), $customer, User::query()->findOrFail($page->owner_user_id));

        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/approve");

        $page->refresh();
        $this->assertSame($v2->id, (int) $page->published_version_id);
        $this->assertNotSame($v1->id, (int) $page->published_version_id, 'v1 is no longer published');
    }

    public function test_a_page_can_only_ever_name_one_published_version(): void
    {
        // Structural, not a rule to enforce: published_version_id is a single column, so two
        // published versions cannot be represented at all.
        [, , $page] = $this->pageWithWorkingVersion();

        $this->assertContains('published_version_id', (new EnterpriseWikiPage)->getFillable());
        $this->assertNull($page->published_version_id);
    }

    // E. rejection never withdraws what was already approved
    public function test_rejecting_the_new_version_leaves_the_published_one_in_place(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $v1 = $page->currentVersion()->first();
        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/approve");

        $v2 = $this->version($page, 2, isCurrent: false);
        $v1->forceFill(['is_current' => false])->save();
        $v2->forceFill(['is_current' => true])->save();
        EnterpriseWikiPage::query()->whereKey($page->id)->update(['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $v2->forceFill(['submitted_by_user_id' => null, 'submitted_at' => null, 'reviewer_user_id' => null])->save();
        $this->handOver($page->fresh(), $customer, User::query()->findOrFail($page->owner_user_id));

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/reject")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->status);
        $this->assertSame(
            $v1->id,
            (int) $page->published_version_id,
            'a rejected revision must not withdraw approved content',
        );
    }

    public function test_rejecting_a_page_that_never_published_anything_publishes_nothing(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);

        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/reject");

        $this->assertNull($page->fresh()->published_version_id);
    }

    // H + I. the working version stays reachable for review and for source-owner approval
    public function test_the_working_version_stays_reachable_after_publication(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $v1 = $page->currentVersion()->first();
        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/approve");

        $v2 = $this->version($page, 2, isCurrent: false);
        $v1->forceFill(['is_current' => false])->save();
        $v2->forceFill(['is_current' => true])->save();

        $page->refresh();
        $this->assertSame($v2->id, (int) $page->currentVersion()->first()->id);
        $this->assertSame($v1->id, (int) $page->publishedVersion()->first()->id);
        $this->assertNotSame(
            (int) $page->currentVersion()->first()->id,
            (int) $page->publishedVersion()->first()->id,
            'the two concepts are genuinely separate',
        );
    }

    // J. a page approved before the split keeps working
    public function test_a_page_whose_published_version_is_also_current_behaves_normally(): void
    {
        [$customer, $systemOwner, $page] = $this->pageWithWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/approve");

        $page->refresh();
        $this->assertSame(
            (int) $page->currentVersion()->first()->id,
            (int) $page->published_version_id,
            'straight after approval the working and published version are the same row',
        );
    }

    /**
     * @return array{0: Customer, 1: User, 2: EnterpriseWikiPage}
     */
    /**
     * A page with a working version, optionally already handed to a reviewer.
     *
     * Where a test needs pending_review it goes through submit(), because that is the only way a
     * page reaches that status now — ingest leaves pages in draft, and approve() refuses a version
     * with no assignment behind it.
     *
     * The System Owner returned acts as reviewer by taking over the assignment. These tests are
     * about version semantics, not about who is allowed to decide.
     *
     * @return array{0: Customer, 1: User, 2: EnterpriseWikiPage}
     */
    private function pageWithWorkingVersion(string $status = EnterpriseWikiPage::STATUS_DRAFT): array
    {
        $customer = $this->customer();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $pageOwner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT);
        $page->forceFill(['owner_user_id' => $pageOwner->id])->save();
        $this->version($page, 1, isCurrent: true);

        if ($status === EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
            $this->handOver($page, $customer, $pageOwner);
        }

        return [$customer, $systemOwner, $page->fresh()];
    }

    /** Submit the page to a reviewer who is not its owner, so the assignment is real. */
    private function handOver(EnterpriseWikiPage $page, Customer $customer, User $pageOwner): void
    {
        $settings = $customer->resolvedPermissionSettings();
        $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] = ['bid_manager'];
        $customer->forceFill(['permission_settings' => $settings])->save();

        $reviewer = $this->user($customer, User::BID_ROLE_BID_MANAGER);

        $this->actingAs($pageOwner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Publisering Test AS',
            'slug' => 'publisering-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@publisering.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function page(Customer $customer, string $status): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'publisert-side-'.Str::lower(Str::random(6)),
            'title' => 'Publisert Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
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
}
