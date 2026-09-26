<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\EnterpriseWiki\EnterpriseWikiReviewNotificationService as Notify;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Making Wiki review work visible without letting notifications become a second source of truth.
 *
 * The domain tables still decide everything: reviewer_user_id says who reviews, the approval rows say
 * whose sign-off is outstanding, and the review events hold why something came back. A notification
 * only says "this is waiting for you" — delete every one of them and the workflow is unchanged.
 *
 * Procynia has in-app notifications and no general task concept, so UserNotification is what is used.
 * Writes are idempotent on dedupe_key and deferred to after commit, so a retried job cannot
 * double-notify and a rolled-back decision is never announced.
 */
class EnterpriseWikiReviewNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private const REASON = 'Avsnittet om leveransemodell stemmer ikke med kildedokumentet.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * Only the reviewer. A document owner used to be told their material was in use and had to be
     * checked, because that check gated publishing the page. It does not any more — a source
     * document is provenance, not a level of approval — so nobody is waiting on them and the bell
     * has nothing to say. The ownership itself is still recorded, on the approval rows.
     */
    public function test_submitting_tells_nobody_but_the_reviewer(): void
    {
        $case = $this->submittedPage(documentOwners: 2);

        foreach ($case['documentOwners'] as $owner) {
            $this->assertSame(
                0,
                UserNotification::query()->where('user_id', $owner->id)->count(),
                'a document owner is asked for nothing',
            );
        }

        $this->assertNotNull($this->notificationFor($case['reviewer'], Notify::EVENT_REVIEW_ASSIGNED));
        $this->assertSame(1, UserNotification::query()->count(), 'one notification, to one person');
    }

    /** A document owner approving their own source unblocks nothing, so it announces nothing. */
    public function test_deciding_a_source_requirement_notifies_nobody(): void
    {
        $case = $this->submittedPage();
        $before = UserNotification::query()->count();

        $this->clearGate($case);

        $this->assertSame($before, UserNotification::query()->count());
    }

    // A. the reviewer is told they have been assigned
    public function test_submitting_tells_the_assigned_reviewer(): void
    {
        $case = $this->submittedPage();

        $notification = $this->notificationFor($case['reviewer'], Notify::EVENT_REVIEW_ASSIGNED);

        $this->assertNotNull($notification);
        $this->assertStringContainsString('kontrollør', $notification->title);
        $this->assertSame("/app/wiki/{$case['page']->slug}", parse_url($notification->target_url, PHP_URL_PATH));

        // The gate may still be closed, so it must not promise a decision they cannot make.
        $this->assertStringNotContainsString('klar til godkjenning', mb_strtolower($notification->message));
    }

    // H. a document owner's objection reaches the page owner, with the reason
    public function test_a_document_owner_objection_reaches_the_page_owner(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();

        $this->actingAs($case['documentOwners'][0])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject", [
                'comment' => self::REASON,
            ])->assertRedirect();

        $notification = $this->notificationFor($case['pageOwner'], Notify::EVENT_CHANGES_REQUESTED);
        $this->assertNotNull($notification);
        $this->assertStringContainsString(self::REASON, $notification->message);
        $this->assertStringContainsString('dokumenteier', mb_strtolower($notification->message));
        $this->assertSame(UserNotification::SEVERITY_WARNING, $notification->severity);
    }

    // I. so does the reviewer's
    public function test_a_reviewer_return_reaches_the_page_owner(): void
    {
        $case = $this->submittedPage();
        $this->clearGate($case);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON])
            ->assertRedirect();

        $notification = $this->notificationFor($case['pageOwner'], Notify::EVENT_CHANGES_REQUESTED);
        $this->assertNotNull($notification);
        $this->assertStringContainsString(self::REASON, $notification->message);
        $this->assertStringContainsString($case['reviewer']->name, $notification->message);
    }

    // J + K. publication reaches the owner, and the submitter when they differ
    public function test_publishing_tells_the_page_owner(): void
    {
        $case = $this->submittedPage();
        $this->clearGate($case);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect();

        $this->assertSame(1, $this->notificationCount($case['pageOwner'], Notify::EVENT_PAGE_PUBLISHED));
    }

    public function test_the_owner_is_told_once_when_they_are_also_the_submitter(): void
    {
        $case = $this->submittedPage();
        $this->clearGate($case);
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        // The page owner submitted it, so owner and submitter are the same person.
        $this->assertSame(1, $this->notificationCount($case['pageOwner'], Notify::EVENT_PAGE_PUBLISHED));
    }

    public function test_a_separate_submitter_is_told_as_well(): void
    {
        $case = $this->submittedPage();
        $submitter = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);
        $case['version']->forceFill(['submitted_by_user_id' => $submitter->id])->save();
        $this->clearGate($case);

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $this->assertSame(1, $this->notificationCount($case['pageOwner'], Notify::EVENT_PAGE_PUBLISHED));
        $this->assertSame(1, $this->notificationCount($submitter, Notify::EVENT_PAGE_PUBLISHED));
    }

    // "you did this" is noise; "you now own this" is not
    public function test_nobody_is_told_about_their_own_action(): void
    {
        $case = $this->submittedPage();
        $this->clearGate($case);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON]);

        $this->assertSame(0, $this->notificationCount($case['reviewer'], Notify::EVENT_CHANGES_REQUESTED));
    }

    // L + M. nothing is announced for a change that did not survive
    public function test_a_rolled_back_decision_announces_nothing(): void
    {
        $case = $this->submittedPage();
        $version = $case['version']->fresh();

        DB::beginTransaction();
        app(Notify::class)->pagePublished($case['page'], $version, $case['reviewer']);
        DB::rollBack();

        $this->assertSame(0, $this->notificationCount($case['pageOwner'], Notify::EVENT_PAGE_PUBLISHED));
    }

    public function test_a_committed_decision_does_announce(): void
    {
        $case = $this->submittedPage();
        $version = $case['version']->fresh();

        DB::transaction(fn () => app(Notify::class)->pagePublished($case['page'], $version, $case['reviewer']));

        $this->assertSame(1, $this->notificationCount($case['pageOwner'], Notify::EVENT_PAGE_PUBLISHED));
    }

    // N. a notification is a disclosure, so the customer boundary is enforced here too
    public function test_a_recipient_from_another_customer_is_never_notified(): void
    {
        $case = $this->submittedPage();
        $otherCustomer = $this->customer('Fremmed Kunde AS');
        $outsider = $this->user($otherCustomer);
        $case['page']->forceFill(['owner_user_id' => $outsider->id])->save();

        DB::transaction(fn () => app(Notify::class)->pagePublished(
            $case['page']->fresh(),
            $case['version']->fresh(),
            $case['reviewer'],
        ));

        $this->assertSame(0, $this->notificationCount($outsider, Notify::EVENT_PAGE_PUBLISHED));
    }

    // O. deactivated people are not given work
    public function test_an_inactive_user_is_not_given_work(): void
    {
        $case = $this->submittedPage();
        $case['pageOwner']->forceFill(['is_active' => false])->save();

        DB::transaction(fn () => app(Notify::class)->pagePublished(
            $case['page'],
            $case['version']->fresh(),
            $case['reviewer'],
        ));

        $this->assertSame(0, $this->notificationCount($case['pageOwner'], Notify::EVENT_PAGE_PUBLISHED));
    }

    // P. a later round is a different objection and is told again
    public function test_a_later_round_notifies_again(): void
    {
        $case = $this->submittedPage();
        $this->clearGate($case);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Runde 1: mangler leveransemodell.']);
        $this->assertSame(1, $this->notificationCount($case['pageOwner'], Notify::EVENT_CHANGES_REQUESTED));

        $this->actingAs($case['pageOwner'])->patch("/app/wiki/{$case['page']->slug}/submit");
        $this->submitFor($case['page'], $case['reviewer']);
        $this->clearGate($case);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Runde 2: fortsatt uklart avsnitt.']);

        $this->assertSame(2, $this->notificationCount($case['pageOwner'], Notify::EVENT_CHANGES_REQUESTED));
    }

    // The domain, not the notification, is the truth
    public function test_deleting_every_notification_leaves_the_workflow_intact(): void
    {
        $case = $this->submittedPage();
        UserNotification::query()->where('customer_id', $case['customer']->id)->delete();

        $this->clearGate($case);
        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
        $this->assertSame($case['version']->id, (int) $case['page']->fresh()->published_version_id);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function submittedPage(int $documentOwners = 1): array
    {
        $case = $this->pageWithSources($documentOwners);
        $this->submitFor($case['page'], $case['reviewer']);

        return $case;
    }

    private function pageWithSources(int $documentCount, bool $sharedOwner = false): array
    {
        $customer = $this->customer();
        $pageOwner = $this->user($customer);
        $reviewer = $this->reviewer($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $pageOwner->id,
            'slug' => 'varsel-'.Str::lower(Str::random(6)),
            'title' => 'Varsel Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Varsel Side',
            'generated_by_model' => 'gpt-5',
        ]);

        $owners = [];
        $documents = [];
        $sharedOwnerUser = $sharedOwner ? $this->user($customer) : null;

        for ($i = 0; $i < $documentCount; $i++) {
            $owner = $sharedOwnerUser ?? $this->user($customer);
            $document = $this->document($customer, $owner);
            $this->attachSource($page, $version, $document, $i);

            if ($sharedOwnerUser === null || $i === 0) {
                $owners[] = $owner;
            }

            $documents[] = $document;
        }

        return compact('customer', 'pageOwner', 'reviewer', 'page', 'version') + [
            'documentOwners' => $owners,
            'documents' => $documents,
        ];
    }

    private function attachSource(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, EnterpriseWikiDocument $document, int $order): void
    {
        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => "Påstand {$order}.",
            'position_order' => $order,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag',
        ]);
    }

    private function submitFor(EnterpriseWikiPage $page, User $reviewer): void
    {
        $owner = User::query()->findOrFail($page->owner_user_id);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));
    }

    private function clearGate(array $case): void
    {
        foreach ($this->activeRequirements($case['version']) as $requirement) {
            $this->decideRequirement($case['page'], $requirement, $case['documentOwners']);
        }
    }

    /** @param list<User> $owners */
    private function decideRequirement(EnterpriseWikiPage $page, EnterpriseWikiPageVersionDocumentOwnerApproval $requirement, array $owners): void
    {
        $owner = collect($owners)->firstWhere('id', $requirement->document_owner_user_id);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));
    }

    private function activeRequirements(EnterpriseWikiPageVersion $version)
    {
        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->whereNull('superseded_at')
            ->orderBy('id')
            ->get();
    }

    private function notificationFor(User $user, string $eventType): ?UserNotification
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('event_type', $eventType)
            ->latest('id')
            ->first();
    }

    private function notificationCount(User $user, string $eventType): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('event_type', $eventType)
            ->count();
    }

    private function reviewer(Customer $customer): User
    {
        $settings = $customer->resolvedPermissionSettings();
        $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] = ['bid_manager'];
        $customer->forceFill(['permission_settings' => $settings])->save();

        return $this->user($customer, User::BID_ROLE_BID_MANAGER);
    }

    private function customer(string $name = 'Varsel Test AS'): Customer
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

    private function user(Customer $customer, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@varsel.test',
            'password' => bcrypt('secret'),
            'role' => in_array($bidRole, [User::BID_ROLE_SYSTEM_OWNER, User::BID_ROLE_BID_MANAGER], true)
                ? User::ROLE_CUSTOMER_ADMIN
                : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function document(Customer $customer, User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'original_filename' => 'kilde-'.Str::random(4).'.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
