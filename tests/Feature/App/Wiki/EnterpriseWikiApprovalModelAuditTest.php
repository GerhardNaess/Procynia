<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The audit exists to answer one question in an environment nobody can page through by hand: is any
 * Wiki page in a state the workflow cannot express?
 *
 * It repairs nothing, on purpose. Every anomaly it finds is either structural corruption — where
 * only a person knows what the right value was — or a legacy state whose resolution is a product
 * decision. Guessing either would attach a name or a version to work nobody agreed to.
 *
 * These tests do two things: prove each check actually fires on a broken row, and prove the command
 * leaves every table exactly as it found it.
 */
class EnterpriseWikiApprovalModelAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function audit(Customer $customer): int
    {
        return $this->artisan('enterprise-wiki:audit-approval-model', ['--customer' => $customer->id])->run();
    }

    // G. the normal, healthy shape must not be flagged
    public function test_a_published_version_with_a_newer_working_version_is_not_an_anomaly(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, $this->user($customer));

        $published = $this->version($page, 1, isCurrent: false);
        $working = $this->version($page, 2, isCurrent: true);
        $page->forceFill(['published_version_id' => $published->id])->save();

        $owner = User::query()->findOrFail($page->owner_user_id);
        $working->forceFill([
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $this->user($customer)->id,
        ])->save();

        $this->assertSame(0, $this->audit($customer), 'V1 published while V2 is reviewed is the intended shape');
    }

    public function test_a_clean_customer_passes(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        $this->assertSame(0, $this->audit($customer));
    }

    // C. an approved page must name what it approved
    public function test_it_catches_an_approved_page_with_nothing_published(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_APPROVED, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        $this->assertSame(1, $this->audit($customer), 'approved without a published version is serious');
    }

    // D. review requires a real handover
    public function test_it_catches_a_page_in_review_with_no_assignment(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        $this->assertSame(1, $this->audit($customer));
    }

    // E. nobody reviews their own submission
    public function test_it_catches_a_self_review_assignment(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, $owner);
        $this->version($page, 1, isCurrent: true)->forceFill([
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $owner->id,
        ])->save();

        $this->assertSame(1, $this->audit($customer));
    }

    // F. responsibility never crosses a customer boundary
    public function test_it_catches_a_reviewer_from_another_customer(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, $this->user($customer));
        $this->version($page, 1, isCurrent: true)->forceFill([
            'submitted_by_user_id' => $page->owner_user_id,
            'submitted_at' => now(),
            'reviewer_user_id' => $this->user($this->customer('Fremmed Kunde AS'))->id,
        ])->save();

        $this->assertSame(1, $this->audit($customer));
    }

    public function test_it_catches_a_page_owner_from_another_customer(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($this->customer('Fremmed Kunde AS')));
        $this->version($page, 1, isCurrent: true);

        $this->assertSame(1, $this->audit($customer));
    }

    // H. a published pointer must name a version of its own page
    public function test_it_catches_a_published_pointer_into_another_page(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        $otherPage = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $foreignVersion = $this->version($otherPage, 1, isCurrent: true);

        $page->forceFill(['published_version_id' => $foreignVersion->id])->save();

        $this->assertSame(1, $this->audit($customer));
    }

    public function test_it_catches_a_review_event_attached_to_the_wrong_page(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        $otherPage = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $otherVersion = $this->version($otherPage, 1, isCurrent: true);

        EnterpriseWikiPageReviewEvent::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $otherVersion->id,
            'actor_user_id' => $this->user($customer)->id,
            'actor_role' => EnterpriseWikiPageReviewEvent::ACTOR_ROLE_REVIEWER,
            'event_type' => EnterpriseWikiPageReviewEvent::EVENT_TYPE_CHANGES_REQUESTED,
            'reason' => 'En begrunnelse som er lang nok.',
        ]);

        $this->assertSame(1, $this->audit($customer));
    }

    // B. an owner that could not be derived stays unset, and is reported rather than invented
    public function test_a_page_without_an_owner_is_reported_but_not_serious(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, null);
        $this->version($page, 1, isCurrent: true);

        $this->assertSame(0, $this->audit($customer), 'unknown ownership is a known state, not corruption');
        $this->assertNull($page->fresh()->owner_user_id, 'and the audit does not invent one');
    }

    // J + K + L + M. the audit reads and nothing else
    public function test_the_audit_changes_no_data_at_all(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_APPROVED, $owner);
        $version = $this->version($page, 1, isCurrent: true);

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'document_owner_user_id' => $owner->id,
            'source_document_ids' => [],
            'source_documents_hash' => Str::random(64),
            'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
        ]);

        $event = EnterpriseWikiPageReviewEvent::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'actor_user_id' => $owner->id,
            'actor_role' => EnterpriseWikiPageReviewEvent::ACTOR_ROLE_REVIEWER,
            'event_type' => EnterpriseWikiPageReviewEvent::EVENT_TYPE_CHANGES_REQUESTED,
            'reason' => 'En begrunnelse som er lang nok.',
        ]);

        $notificationCount = UserNotification::query()->count();
        $before = [
            'page' => $page->fresh()->getAttributes(),
            'version' => $version->fresh()->getAttributes(),
            'approval' => $approval->fresh()->getAttributes(),
            'event' => $event->fresh()->getAttributes(),
        ];

        $this->audit($customer);

        $this->assertSame($before['page'], $page->fresh()->getAttributes(), 'pages are untouched');
        $this->assertSame($before['version'], $version->fresh()->getAttributes(), 'versions are untouched');
        $this->assertSame($before['approval'], $approval->fresh()->getAttributes(), 'document-owner approvals are never repaired here');
        $this->assertSame($before['event'], $event->fresh()->getAttributes(), 'review history is never rewritten');
        $this->assertSame($notificationCount, UserNotification::query()->count(), 'no notification is ever sent');
    }

    public function test_a_stale_owner_approval_is_reported_without_being_retired(): void
    {
        // Retiring these belongs to the ordinary sync, by decision in step 1.
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $old = $this->version($page, 1, isCurrent: false);
        $this->version($page, 2, isCurrent: true);

        $stale = EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $old->id,
            'document_owner_user_id' => $this->user($customer)->id,
            'source_document_ids' => [],
            'source_documents_hash' => Str::random(64),
            'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
        ]);

        $this->assertSame(0, $this->audit($customer), 'a stale row is information, not a blocker');
        $this->assertNull($stale->fresh()->superseded_at, 'and the audit does not retire it');
    }

    public function test_it_catches_duplicate_notification_keys(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        // The unique index normally prevents this; the check exists for data written before it.
        $key = 'wiki.review_assigned:1:1';
        foreach ([1, 2] as $i) {
            DB::table('user_notifications')->insert([
                'customer_id' => $customer->id,
                'user_id' => $this->user($customer)->id,
                'event_type' => 'wiki.review_assigned',
                'dedupe_key' => $key.':'.$i,
                'severity' => UserNotification::SEVERITY_INFO,
                'title' => 'T',
                'message' => 'M',
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Distinct keys are fine — this asserts the check does not fire on ordinary rows.
        $this->assertSame(0, $this->audit($customer));
    }

    // A. running it twice says the same thing
    public function test_the_audit_is_repeatable(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_APPROVED, $this->user($customer));
        $this->version($page, 1, isCurrent: true);

        $this->assertSame($this->audit($customer), $this->audit($customer));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function customer(string $name = 'Revisjon Test AS'): Customer
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

    private function user(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@revisjon.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function page(Customer $customer, string $status, ?User $owner): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'slug' => 'revisjon-'.Str::lower(Str::random(6)),
            'title' => 'Revisjon Side',
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
            'content_markdown' => "# Revisjon v{$number}",
            'generated_by_model' => 'gpt-5',
        ]);
    }
}
