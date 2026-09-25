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
use App\Services\EnterpriseWiki\EnterpriseWikiReviewNotificationService as Notify;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Handing a Wiki page to somebody, and what each surface then says about it.
 *
 * The three things this separates, because mixing any two of them is what made the workflow
 * confusing: the HANDOVER is a request that either succeeded or did not; the BELL says something
 * new happened and goes quiet once it is read; "Mine oppgaver" says the work is still yours and
 * goes quiet only when you have decided.
 *
 * Nothing is mirrored into a task table. The assignment lives on the page version and the decision
 * state lives in the page status, so approving or sending a page back retires the task by itself —
 * there is no second record anybody has to remember to close.
 */
class InfoCenterWikiReviewTaskTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── The handover itself ─────────────────────────────────────────────────

    /**
     * The whole handover in one pass, from the request to the row the reviewer will see.
     *
     * Asserted end to end through the endpoint rather than against the notification service, so
     * that a change which quietly stops calling it cannot pass.
     */
    public function test_submitting_hands_the_page_over_and_tells_the_reviewer(): void
    {
        [$customer, $owner, $reviewer, $page, $version] = $this->draftPage();

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug))
            ->assertSessionHas('success', "Siden er sendt til gjennomgang hos {$reviewer->name}.");

        $page->refresh();
        $version->refresh();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertSame((int) $reviewer->id, (int) $version->reviewer_user_id);
        $this->assertSame((int) $owner->id, (int) $version->submitted_by_user_id);
        $this->assertNotNull($version->submitted_at);

        $notification = UserNotification::query()
            ->where('user_id', $reviewer->id)
            ->where('event_type', Notify::EVENT_REVIEW_ASSIGNED)
            ->sole();

        $this->assertSame((int) $customer->id, (int) $notification->customer_id);
        $this->assertFalse((bool) $notification->is_read);
        $this->assertNotSame('', trim((string) $notification->title));
        $this->assertStringContainsString($page->title, $notification->message);
        $this->assertStringContainsString($owner->name, $notification->message);
        // Straight to the page in question. A general Wiki list would make the reader search for
        // the one thing the notification already knows.
        $this->assertSame("/app/wiki/{$page->slug}", parse_url($notification->target_url, PHP_URL_PATH));
    }

    /** The submitter is not the reviewer, so nothing announces their own action back to them. */
    public function test_the_submitter_is_not_notified_of_their_own_handover(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();

        $this->submit($owner, $page, $reviewer);

        $this->assertSame(0, UserNotification::query()
            ->where('user_id', $owner->id)
            ->where('event_type', Notify::EVENT_REVIEW_ASSIGNED)
            ->count());
    }

    // ── The task ────────────────────────────────────────────────────────────

    public function test_the_reviewer_sees_the_page_under_my_tasks(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $task = collect($this->infoCenterFor($reviewer)['wiki_tasks'])->sole();

        $this->assertSame('wiki_review', $task['type']);
        $this->assertSame('Gjennomgå Wiki-side', $task['subject_label']);
        $this->assertSame($page->title, $task['page_title']);
        $this->assertSame($owner->name, $task['submitted_by']);
        $this->assertNotNull($task['submitted_at']);
        $this->assertSame(route('app.wiki.show', ['slug' => $page->slug], false), $task['action_url']);
    }

    /** The counter and the list have to agree, or one of them is lying. */
    public function test_the_my_tasks_counter_includes_the_review(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $this->assertSame(1, $this->myTasksCount($this->infoCenterFor($reviewer)));
    }

    /** A page nobody has handed over yet is on nobody's list. */
    public function test_a_draft_page_is_nobodys_review_task(): void
    {
        [, , $reviewer] = $this->draftPage();

        $this->assertSame([], $this->infoCenterFor($reviewer)['wiki_tasks']);
    }

    // ── Bell versus task ────────────────────────────────────────────────────

    /**
     * The single most important behaviour here. Reading the notification acknowledges the message;
     * it does not make the decision, and the list must keep saying so.
     */
    public function test_reading_the_notification_does_not_clear_the_task(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $notification = UserNotification::query()
            ->where('user_id', $reviewer->id)
            ->where('event_type', Notify::EVENT_REVIEW_ASSIGNED)
            ->sole();

        $this->actingAs($reviewer)
            ->patch(route('app.notifications.read', ['userNotification' => $notification->id]))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 0);

        $this->assertTrue((bool) $notification->fresh()->is_read);
        $this->assertCount(1, $this->infoCenterFor($reviewer)['wiki_tasks'], 'the decision is still theirs');
    }

    /** Marking everything read clears the bell and nothing else. */
    public function test_marking_everything_read_leaves_the_task_standing(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $this->actingAs($reviewer)
            ->patch(route('app.notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('notifications.unread_count', 0);

        $this->assertCount(1, $this->infoCenterFor($reviewer)['wiki_tasks']);
    }

    /** And when the decision is actually made, the task goes without anybody closing it. */
    public function test_approving_retires_the_task(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);
        $this->assertCount(1, $this->infoCenterFor($reviewer)['wiki_tasks']);

        $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve")->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
        $this->assertSame([], $this->infoCenterFor($reviewer)['wiki_tasks']);
    }

    /** Sending it back is a decision too — the work moves to the owner, so it leaves this list. */
    public function test_sending_the_page_back_retires_the_task(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/reject", ['reason' => 'Avsnittet om leveransemodell mangler kilde.'])
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
        $this->assertSame([], $this->infoCenterFor($reviewer)['wiki_tasks']);
    }

    // ── Scope ───────────────────────────────────────────────────────────────

    public function test_nobody_else_sees_the_review(): void
    {
        [$customer, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $bystander = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertSame([], $this->infoCenterFor($bystander)['wiki_tasks']);
        $this->assertSame([], $this->infoCenterFor($owner)['wiki_tasks'], 'not even the person who sent it');
    }

    public function test_a_review_never_crosses_a_customer_boundary(): void
    {
        [, $owner, $reviewer, $page] = $this->draftPage();
        $this->submit($owner, $page, $reviewer);

        $outsider = $this->user($this->customer('Annen Kunde AS'), User::BID_ROLE_SYSTEM_OWNER);

        $this->assertSame([], $this->infoCenterFor($outsider)['wiki_tasks']);
        $this->assertSame(0, UserNotification::query()->where('user_id', $outsider->id)->count());
    }

    // ── Review and QA are different jobs ────────────────────────────────────

    /**
     * One person can hold both at once, and the list has to say which is which — reviewing decides
     * whether the page is published, quality assurance decides whether its claims hold.
     */
    public function test_review_and_quality_assurance_appear_as_separate_tasks(): void
    {
        [, $owner, $reviewer, $page, $version] = $this->draftPage();
        $reviewer->forceFill(['is_qa' => true])->save();
        $this->submit($owner, $page, $reviewer);

        // Quality assurance is about claims, so the page needs one still awaiting a decision.
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Vi har døgnbemannet responsteam.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 0,
        ]);

        $version->refresh()->forceFill([
            'qa_user_id' => $reviewer->id,
            'qa_assigned_at' => now(),
            'qa_assigned_by_user_id' => $owner->id,
        ])->save();

        $tasks = collect($this->infoCenterFor($reviewer->fresh())['wiki_tasks']);

        $this->assertEqualsCanonicalizing(['wiki_review', 'wiki_qa'], $tasks->pluck('type')->all());
        $this->assertSame(
            ['Gjennomgå Wiki-side', 'Kvalitetssikre Wiki-side'],
            $tasks->pluck('subject_label')->sort()->values()->all(),
        );
        $this->assertSame(2, $this->myTasksCount($this->infoCenterFor($reviewer->fresh())));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function infoCenterFor(User $user, string $view = 'my_tasks'): array
    {
        $response = $this->actingAs($user)->get(route('app.info-center.index', ['view' => $view]));
        $response->assertOk();

        return $response->viewData('page')['props']['infoCenter'];
    }

    /** @param array<string, mixed> $infoCenter */
    private function myTasksCount(array $infoCenter): int
    {
        foreach ($infoCenter['summary']['items'] as $item) {
            if ($item['key'] === 'my_tasks') {
                return (int) $item['count'];
            }
        }

        $this->fail('expected a "Mine oppgaver" counter');
    }

    private function submit(User $owner, EnterpriseWikiPage $page, User $reviewer): void
    {
        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertSessionHasNoErrors();
    }

    /**
     * A page with no source documents, so the document-owner gate is empty and the reviewer can
     * decide on it. The gate itself is covered by the review suites; here it would only stand
     * between the test and the thing being tested.
     *
     * @return array{0: Customer, 1: User, 2: User, 3: EnterpriseWikiPage, 4: EnterpriseWikiPageVersion}
     */
    private function draftPage(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'gjennomgang-'.Str::lower(Str::random(8)),
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

        return [$customer, $owner, $reviewer, $page->fresh(), $version];
    }

    private function user(Customer $customer, string $bidRole, bool $isWikiApprover = false): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@gjennomgang.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function customer(string $name = 'Gjennomgang AS'): Customer
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
