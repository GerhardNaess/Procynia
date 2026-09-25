<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clearing the bell, and the line it must never cross.
 *
 * A notification is a message about something that happened. The work itself lives in the domain —
 * who is assigned to review a Wiki page, whose claims are still pending, what a case is waiting on
 * — and the Info Center reads it from there. So deleting a message removes a message, and that is
 * the whole of what it does.
 *
 * Most of what follows exists to hold that line, because it is the one that would be expensive to
 * get wrong: somebody clears an inbox and quietly loses the work they were supposed to do.
 */
class UserNotificationDeletionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── One message ─────────────────────────────────────────────────────────

    public function test_deleting_an_unread_message_removes_it_and_lowers_the_badge(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $doomed = $this->notification($customer, $user);
        $kept = $this->notification($customer, $user);

        $this->actingAs($user)
            ->delete(route('app.notifications.destroy', ['userNotification' => $doomed->id]))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 1);

        $this->assertDatabaseMissing('user_notifications', ['id' => $doomed->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $kept->id]);
    }

    /** A message already seen was never in the count, so removing it cannot change it. */
    public function test_deleting_a_read_message_leaves_the_badge_alone(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $read = $this->notification($customer, $user, isRead: true);
        $this->notification($customer, $user);

        $this->actingAs($user)
            ->delete(route('app.notifications.destroy', ['userNotification' => $read->id]))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 1);

        $this->assertDatabaseMissing('user_notifications', ['id' => $read->id]);
    }

    /** Two clicks, or two tabs. The second one finds nothing, which is the truthful answer. */
    public function test_deleting_the_same_message_twice_is_not_an_error_worth_showing(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $notification = $this->notification($customer, $user);
        $url = route('app.notifications.destroy', ['userNotification' => $notification->id]);

        $this->actingAs($user)->delete($url)->assertOk();
        $this->actingAs($user)->delete($url)->assertNotFound();
    }

    public function test_the_panel_hands_the_page_a_delete_url_for_each_message(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $notification = $this->notification($customer, $user);

        $payload = $this->actingAs($user)->get(route('app.notifications.index'))->assertOk()->json('notifications');

        $this->assertSame(
            route('app.notifications.destroy', ['userNotification' => $notification->id]),
            $payload['items'][0]['delete_url'],
        );
        $this->assertSame(route('app.notifications.destroy-unread'), $payload['delete_unread_url']);
    }

    // ── Whose message it is ─────────────────────────────────────────────────

    public function test_one_persons_message_is_not_another_persons_to_delete(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        $colleague = $this->user($customer);
        $notification = $this->notification($customer, $owner);

        $this->actingAs($colleague)
            ->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('user_notifications', ['id' => $notification->id]);
    }

    public function test_a_message_never_crosses_a_customer_boundary(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $notification = $this->notification($customer, $user);

        $outsider = $this->user($this->customer('Annen Kunde AS'));

        $this->actingAs($outsider)
            ->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('user_notifications', ['id' => $notification->id]);
    }

    // ── Clearing the unread ─────────────────────────────────────────────────

    public function test_clearing_unread_keeps_what_has_already_been_read(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $unread = collect(range(1, 3))->map(fn (): UserNotification => $this->notification($customer, $user));
        $read = collect(range(1, 2))->map(fn (): UserNotification => $this->notification($customer, $user, isRead: true));

        $this->actingAs($user)
            ->delete(route('app.notifications.destroy-unread'))
            ->assertOk()
            ->assertJsonPath('deleted', 3)
            ->assertJsonPath('notifications.unread_count', 0);

        foreach ($unread as $notification) {
            $this->assertDatabaseMissing('user_notifications', ['id' => $notification->id]);
        }

        foreach ($read as $notification) {
            $this->assertDatabaseHas('user_notifications', ['id' => $notification->id]);
        }
    }

    public function test_clearing_unread_reaches_nobody_elses_messages(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $colleague = $this->user($customer);
        $outsider = $this->user($this->customer('Annen Kunde AS'));

        $this->notification($customer, $user);
        $colleagues = $this->notification($customer, $colleague);
        $theirs = $this->notification($outsider->customer, $outsider);

        $this->actingAs($user)->delete(route('app.notifications.destroy-unread'))->assertOk();

        $this->assertDatabaseHas('user_notifications', ['id' => $colleagues->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $theirs->id]);
    }

    /** An empty bell is the state the click asked for, not a failure. */
    public function test_clearing_an_empty_bell_succeeds(): void
    {
        $user = $this->user($this->customer());

        $this->actingAs($user)
            ->delete(route('app.notifications.destroy-unread'))
            ->assertOk()
            ->assertJsonPath('deleted', 0)
            ->assertJsonPath('notifications.unread_count', 0);
    }

    /** Both bulk actions still exist and mean different things. */
    public function test_marking_everything_read_still_keeps_the_messages(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $notification = $this->notification($customer, $user);

        $this->actingAs($user)
            ->patch(route('app.notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 0);

        $this->assertDatabaseHas('user_notifications', ['id' => $notification->id]);
        $this->assertTrue((bool) $notification->fresh()->is_read);
    }

    // ── The line that matters ───────────────────────────────────────────────

    /**
     * The regression this whole feature had to avoid. Clearing an inbox must not quietly clear the
     * work — the review is still assigned, and the Info Center still says so.
     */
    public function test_deleting_a_review_alert_leaves_the_review_on_the_reviewers_list(): void
    {
        $case = $this->pageOutForReview();
        $notification = $this->notification($case['customer'], $case['reviewer'], eventType: 'wiki.review_assigned');

        $this->assertCount(1, $this->wikiTasksFor($case['reviewer']), 'the premise: it is their work');

        $this->actingAs($case['reviewer'])
            ->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 0);

        $tasks = $this->wikiTasksFor($case['reviewer']);

        $this->assertCount(1, $tasks, 'the message went; the work did not');
        $this->assertSame('wiki_review', $tasks[0]['type']);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertSame(
            (int) $case['reviewer']->id,
            (int) $case['version']->fresh()->reviewer_user_id,
            'and the assignment is untouched',
        );
    }

    /** The same for quality assurance, which is a different assignment on the same page. */
    public function test_deleting_a_qa_alert_leaves_the_quality_work_outstanding(): void
    {
        $case = $this->pageOutForReview();
        $case['reviewer']->forceFill(['is_qa' => true])->save();
        $case['version']->forceFill([
            'qa_user_id' => $case['reviewer']->id,
            'qa_assigned_at' => now(),
            'qa_assigned_by_user_id' => $case['owner']->id,
        ])->save();
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $case['page']->id,
            'enterprise_wiki_page_version_id' => $case['version']->id,
            'claim_text' => 'Vi har døgnbemannet responsteam.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $notification = $this->notification($case['customer'], $case['reviewer'], eventType: 'wiki.qa_assigned');

        $this->actingAs($case['reviewer']->fresh())
            ->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]))
            ->assertOk();

        $types = collect($this->wikiTasksFor($case['reviewer']->fresh()))->pluck('type')->all();

        $this->assertContains('wiki_qa', $types);
        $this->assertSame((int) $case['reviewer']->id, (int) $case['version']->fresh()->qa_user_id);
    }

    /** And clearing everything at once is no different. */
    public function test_clearing_every_unread_alert_leaves_every_task_standing(): void
    {
        $case = $this->pageOutForReview();
        $this->notification($case['customer'], $case['reviewer'], eventType: 'wiki.review_assigned');
        $this->notification($case['customer'], $case['reviewer'], eventType: 'bid.task_assigned');

        $this->actingAs($case['reviewer'])
            ->delete(route('app.notifications.destroy-unread'))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 0);

        $this->assertCount(1, $this->wikiTasksFor($case['reviewer']));
    }

    /**
     * Deleting is not a decision. The work leaves the list when the work is done — here, when the
     * reviewer actually approves the page.
     */
    public function test_the_task_goes_when_the_work_is_done_not_when_the_message_is_deleted(): void
    {
        $case = $this->pageOutForReview();
        $notification = $this->notification($case['customer'], $case['reviewer'], eventType: 'wiki.review_assigned');

        $this->actingAs($case['reviewer'])
            ->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]));
        $this->assertCount(1, $this->wikiTasksFor($case['reviewer']));

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $this->assertSame([], $this->wikiTasksFor($case['reviewer']));
    }

    /** One mechanism for every kind of message; no event type has its own delete path. */
    public function test_every_kind_of_alert_deletes_the_same_way(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);

        foreach ([
            'wiki.review_assigned',
            'wiki.qa_assigned',
            'wiki.page_published',
            'bid.task_assigned',
            'bid.deadline_approaching',
            'watch_profile.match_found',
        ] as $eventType) {
            $notification = $this->notification($customer, $user, eventType: $eventType);

            $this->actingAs($user)
                ->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]))
                ->assertOk();

            $this->assertDatabaseMissing('user_notifications', ['id' => $notification->id]);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function wikiTasksFor(User $user): array
    {
        $response = $this->actingAs($user)->get(route('app.info-center.index', ['view' => 'my_tasks']));
        $response->assertOk();

        return $response->viewData('page')['props']['infoCenter']['wiki_tasks'];
    }

    /** @return array<string, mixed> */
    private function pageOutForReview(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'varselrydding-'.Str::lower(Str::random(8)),
            'title' => 'Incident Commander',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Incident Commander',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();

        // The handover creates its own notification; these tests are about the ones they add.
        UserNotification::query()->where('customer_id', $customer->id)->delete();

        return [
            'customer' => $customer,
            'owner' => $owner,
            'reviewer' => $reviewer,
            'page' => $page->fresh(),
            'version' => $version->fresh(),
        ];
    }

    private function notification(
        Customer $customer,
        User $user,
        bool $isRead = false,
        string $eventType = 'wiki.page_published',
    ): UserNotification {
        return UserNotification::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'event_type' => $eventType,
            'severity' => UserNotification::SEVERITY_INFO,
            'title' => 'Varsel '.Str::random(4),
            'message' => 'En hendelse du bør vite om.',
            'target_url' => '/app/wiki',
            'dedupe_key' => $eventType.':'.Str::random(12),
            'is_read' => $isRead,
            'read_at' => $isRead ? now() : null,
        ]);
    }

    private function user(Customer $customer, string $bidRole = User::BID_ROLE_CONTRIBUTOR, bool $isWikiApprover = false): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@varselrydding.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function customer(string $name = 'Varselrydding AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
