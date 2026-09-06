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
 * Final approval of a Wiki page is a capability, not a job title.
 *
 * It used to be `if (! $user?->isSystemOwner()) abort(403)`, which made the person who submits the
 * page the same person who approves it. The right is now `approve_wiki_pages` in
 * customers.permission_settings, alongside the permissions that already live there.
 *
 * It is deliberately NOT `approve_wiki_claims`. A claim decision vouches for one statement against
 * its source; a page decision publishes the page. Holding one must never imply the other.
 *
 * System Owner still passes — roleHasPermission() short-circuits — but as an override rather than
 * because the workflow names them the approver. See docs/enterprise-wiki-approval-model.md §10.
 */
class EnterpriseWikiPageReviewCapabilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // A. the capability grants approval
    public function test_a_user_granted_the_capability_can_approve(): void
    {
        [$customer, $page] = $this->pendingPage();
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['bid_manager']);
        $reviewer = $this->user($customer, User::BID_ROLE_BID_MANAGER);

        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    // B. without it, approval is refused
    public function test_a_user_without_the_capability_is_refused(): void
    {
        [$customer, $page] = $this->pendingPage();
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($contributor)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_the_default_grants_it_to_nobody_but_system_owner(): void
    {
        // Introducing the capability must not widen anyone's access on deploy.
        $this->assertSame(
            ['system_owner'],
            Customer::DEFAULT_PERMISSION_SETTINGS[Customer::PERMISSION_APPROVE_WIKI_PAGES],
        );

        [$customer, $page] = $this->pendingPage();

        foreach ([User::BID_ROLE_BID_MANAGER, User::BID_ROLE_CONTRIBUTOR] as $role) {
            $this->actingAs($this->user($customer, $role))
                ->patch("/app/wiki/{$page->slug}/approve")
                ->assertForbidden();
        }
    }

    // C. System Owner keeps an override
    public function test_a_system_owner_can_still_approve_without_being_granted_it(): void
    {
        [$customer, $page] = $this->pendingPage();
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, []);

        $this->actingAs($this->user($customer, User::BID_ROLE_SYSTEM_OWNER))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    // D. never across customers
    public function test_a_reviewer_from_another_customer_cannot_approve(): void
    {
        [, $page] = $this->pendingPage();
        $otherCustomer = $this->customer('Fremmed Kunde AS');
        $this->grant($otherCustomer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['bid_manager']);

        $this->actingAs($this->user($otherCustomer, User::BID_ROLE_BID_MANAGER))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertNotFound();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    // E + F. page review and claim approval are different rights
    public function test_claim_approval_does_not_confer_page_approval(): void
    {
        [$customer, $page] = $this->pendingPage();
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_CLAIMS, ['contributor']);
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, []);

        $claimApprover = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertTrue($claimApprover->canApproveWikiClaims(), 'they may decide claims');
        $this->assertFalse($claimApprover->canApproveWikiPages(), 'but not publish the page');

        $this->actingAs($claimApprover)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertForbidden();
    }

    public function test_page_approval_does_not_confer_claim_approval(): void
    {
        $customer = $this->customer();
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['contributor']);
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_CLAIMS, []);

        $pageReviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertTrue($pageReviewer->canApproveWikiPages());
        $this->assertFalse($pageReviewer->canApproveWikiClaims());
    }

    public function test_the_two_permissions_are_distinct_keys(): void
    {
        $this->assertNotSame(
            Customer::PERMISSION_APPROVE_WIKI_CLAIMS,
            Customer::PERMISSION_APPROVE_WIKI_PAGES,
        );
    }

    // I. reject follows the same capability
    public function test_reject_uses_the_same_capability_as_approve(): void
    {
        [$customer, $page] = $this->pendingPage();
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['bid_manager']);

        $this->actingAs($this->user($customer, User::BID_ROLE_CONTRIBUTOR))
            ->patch("/app/wiki/{$page->slug}/reject")
            ->assertForbidden();

        $this->actingAs($this->user($customer, User::BID_ROLE_BID_MANAGER))
            ->patch("/app/wiki/{$page->slug}/reject")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
    }

    // H. the controller no longer decides this inline
    public function test_the_controller_authorizes_through_the_capability_not_a_role_check(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/App/WikiController.php'));

        foreach (['approve', 'reject'] as $action) {
            $at = strpos($source, "public function {$action}(string \$slug)");
            $this->assertNotFalse($at, "{$action}() is gone");

            $body = substr($source, $at, strpos($source, 'abort(403)', $at) - $at);
            $this->assertStringContainsString('canApproveWikiPages()', $body, "{$action}() must use the capability");
            $this->assertStringNotContainsString('isSystemOwner()', $body, "{$action}() must not gate on the role");
        }
    }

    // J. the review capability is not a submit right
    public function test_the_review_capability_alone_does_not_let_someone_submit(): void
    {
        // Step 5 moved submit to the page owner. The point this test has always made still holds:
        // being allowed to review is not being allowed to hand work over.
        [$customer, $page] = $this->pendingPage(EnterpriseWikiPage::STATUS_DRAFT);
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['bid_manager']);
        $reviewer = $this->user($customer, User::BID_ROLE_BID_MANAGER);

        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    // G. the capability is administrable in the existing permissions screen
    public function test_the_capability_appears_in_the_permission_settings_screen(): void
    {
        $customer = $this->customer();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)
            ->get('/app/customer-environment?tab=permissions')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where(
                'permissionSettings.permission_rows',
                fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['key'] === Customer::PERMISSION_APPROVE_WIKI_PAGES
                        && $row['label'] === 'Godkjenne Wiki-sider',
                ),
            ));
    }

    public function test_the_capability_can_be_granted_through_the_existing_endpoint(): void
    {
        $customer = $this->customer();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['bid_manager'],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['system_owner', 'bid_manager'],
            $customer->fresh()->resolvedPermissionSettings()[Customer::PERMISSION_APPROVE_WIKI_PAGES],
            'System Owner is always kept, as with every other permission',
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return array{0: Customer, 1: EnterpriseWikiPage} */
    private function pendingPage(string $status = EnterpriseWikiPage::STATUS_PENDING_REVIEW): array
    {
        $customer = $this->customer();
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'review-side-'.Str::lower(Str::random(6)),
            'title' => 'Review Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Review Side',
            'generated_by_model' => 'gpt-5',
        ]);

        return [$customer, $page];
    }

    /** @param list<string> $roles */
    private function grant(Customer $customer, string $permission, array $roles): void
    {
        $settings = $customer->resolvedPermissionSettings();
        $settings[$permission] = $roles;
        $customer->forceFill(['permission_settings' => $settings])->save();
    }

    private function customer(string $name = 'Review Test AS'): Customer
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

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@review.test',
            'password' => bcrypt('secret'),
            'role' => in_array($bidRole, [User::BID_ROLE_SYSTEM_OWNER, User::BID_ROLE_BID_MANAGER], true)
                ? User::ROLE_CUSTOMER_ADMIN
                : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
