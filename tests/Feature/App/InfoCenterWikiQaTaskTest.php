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
use App\Services\EnterpriseWiki\EnterpriseWikiReviewNotificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki quality work, shown where a person looks for their own work.
 *
 * The distinction the whole feature rests on, and the reason most of these tests exist: the bell
 * says something happened and goes quiet once it is read; "Mine oppgaver" says the work is still
 * yours and goes quiet only when the work is done. A notification the user read on Monday must not
 * take the Tuesday work off their list with it.
 *
 * Nothing is mirrored into a task table. The assignment lives on the page version and the progress
 * lives in the claims, so the Info Center reads both and presents them — which is why a reassigned
 * or superseded task simply stops appearing rather than needing to be closed by somebody.
 */
class InfoCenterWikiQaTaskTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── The task appears ────────────────────────────────────────────────────

    public function test_an_assigned_qa_user_sees_the_work_under_my_tasks(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims(pending: 2, approved: 8);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $task = collect($this->infoCenterFor($qa)['wiki_qa_tasks'])->sole();

        $this->assertSame('wiki_qa', $task['type']);
        $this->assertSame('Kvalitetssikre Wiki-side', $task['subject_label']);
        $this->assertSame($page->title, $task['page_title']);
        $this->assertSame(10, $task['claims_total']);
        $this->assertSame(8, $task['claims_handled']);
        $this->assertSame(2, $task['claims_pending']);
        $this->assertNotNull($task['assigned_at']);
    }

    public function test_the_task_links_straight_to_the_wiki_page(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $task = collect($this->infoCenterFor($qa)['wiki_qa_tasks'])->sole();

        $this->assertSame(route('app.wiki.show', ['slug' => $page->slug], false), $task['action_url']);
    }

    /** The counter and the list have to agree, or one of them is lying. */
    public function test_the_my_tasks_counter_includes_the_qa_work(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $infoCenter = $this->infoCenterFor($qa);

        $this->assertSame(1, $this->myTasksCount($infoCenter));
        $this->assertCount(1, $infoCenter['wiki_qa_tasks']);
    }

    /**
     * A Contributor reads the Info Center as a commercial owner, which has no standing task counter
     * — and a Contributor with the QA capability is the ordinary person to hand Wiki quality work
     * to. Work assigned by name has to be countable, whichever surface the person normally uses.
     */
    public function test_the_counter_appears_for_a_contributor_only_once_qa_work_exists(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $qa = $this->qaUser($customer);

        $this->assertSame('commercial_owner', $this->infoCenterFor($qa)['role_context']['persona']);
        $this->assertNull($this->myTasksCountOrNull($this->infoCenterFor($qa)));

        $this->assign($owner, $page, $qa);

        $this->assertSame(1, $this->myTasksCount($this->infoCenterFor($qa)));
    }

    /** The person has work to do. They are not waiting on anybody. */
    public function test_the_task_does_not_appear_under_awaiting_response(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $this->assertSame([], $this->infoCenterFor($qa, 'awaiting_response')['wiki_qa_tasks']);
        $this->assertCount(1, $this->infoCenterFor($qa, 'my_tasks')['wiki_qa_tasks']);
    }

    // ── The bell and the list are different things ──────────────────────────

    /**
     * The single most important behaviour here. Reading the notification acknowledges the message;
     * it does not do the work, and the list must keep saying so.
     */
    public function test_reading_the_notification_does_not_clear_the_task(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 2);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $notification = UserNotification::query()
            ->where('user_id', $qa->id)
            ->where('event_type', EnterpriseWikiReviewNotificationService::EVENT_QA_ASSIGNED)
            ->sole();

        $this->assertFalse((bool) $notification->is_read, 'the premise: it starts unread');

        $this->actingAs($qa)->patch(route('app.notifications.read', ['userNotification' => $notification->id]));

        $this->assertTrue((bool) $notification->fresh()->is_read);
        $this->assertCount(1, $this->infoCenterFor($qa)['wiki_qa_tasks'], 'the work is still theirs');
    }

    /** And when the work really is done, the task goes without anybody closing it. */
    public function test_the_task_disappears_when_no_claim_is_pending(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims(pending: 2);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $this->assertCount(1, $this->infoCenterFor($qa)['wiki_qa_tasks']);

        EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->update(['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED]);

        $this->assertSame([], $this->infoCenterFor($qa)['wiki_qa_tasks']);
    }

    /**
     * A rejected claim has had its QA decision made. Counting it as outstanding would keep the task
     * alive forever over work that is finished — whatever repair it triggers elsewhere.
     */
    public function test_a_rejected_claim_counts_as_decided(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 0, approved: 8, rejected: 2);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $this->assertSame([], $this->infoCenterFor($qa)['wiki_qa_tasks']);
    }

    /** A version nobody wrote claims for has no quality work, exactly as the Wiki page says. */
    public function test_a_version_without_claims_produces_no_task(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 0);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $this->assertSame([], $this->infoCenterFor($qa)['wiki_qa_tasks']);
    }

    // ── Assignment changes ──────────────────────────────────────────────────

    /**
     * Taking the work yourself produces no notification, by design — you know what you just did.
     * The responsibility is still real, and the Info Center is where responsibility lives.
     */
    public function test_taking_the_work_yourself_still_puts_it_on_your_list(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $owner->forceFill(['is_qa' => true])->save();

        $this->assign($owner->fresh(), $page, $owner);

        $this->assertCount(0, UserNotification::query()
            ->where('user_id', $owner->id)
            ->where('event_type', EnterpriseWikiReviewNotificationService::EVENT_QA_ASSIGNED)
            ->get(), 'no self-notification');
        $this->assertCount(1, $this->infoCenterFor($owner->fresh())['wiki_qa_tasks'], 'but the work is theirs');
    }

    /** Nothing has to be closed: the list reads the assignment, so a handover moves it outright. */
    public function test_reassignment_moves_the_task_and_leaves_nothing_behind(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 2);
        $first = $this->qaUser($customer);
        $second = $this->qaUser($customer);

        $this->assign($owner, $page, $first);
        $this->assertCount(1, $this->infoCenterFor($first)['wiki_qa_tasks']);

        $this->assign($owner, $page, $second);

        $this->assertSame([], $this->infoCenterFor($first)['wiki_qa_tasks']);
        $this->assertCount(1, $this->infoCenterFor($second)['wiki_qa_tasks']);
    }

    /**
     * The assignment is version-scoped, so a regenerated page starts over. Chasing a superseded
     * version would send the person to check content that is no longer current.
     */
    public function test_a_new_current_version_retires_the_old_task(): void
    {
        [$customer, $owner, $page, $v1] = $this->pageWithClaims(pending: 2);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);
        $this->assertCount(1, $this->infoCenterFor($qa)['wiki_qa_tasks']);

        $v1->forceFill(['is_current' => false])->save();
        $this->version($page, 2);

        $this->assertSame([], $this->infoCenterFor($qa)['wiki_qa_tasks']);
        $this->assertSame((int) $qa->id, (int) $v1->refresh()->qa_user_id, 'v1 keeps its history');
    }

    // ── Scope ───────────────────────────────────────────────────────────────

    public function test_nobody_sees_someone_elses_qa_work(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $qa = $this->qaUser($customer);
        $bystander = $this->qaUser($customer);

        $this->assign($owner, $page, $qa);

        $this->assertSame([], $this->infoCenterFor($bystander)['wiki_qa_tasks']);
    }

    public function test_qa_work_never_crosses_a_customer_boundary(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 1);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $outsider = $this->qaUser($this->customer('Annen Kunde AS'));

        $this->assertSame([], $this->infoCenterFor($outsider)['wiki_qa_tasks']);
    }

    public function test_an_archived_page_is_not_outstanding_work(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 2);
        $qa = $this->qaUser($customer);
        $this->assign($owner, $page, $qa);

        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_ARCHIVED])->save();

        $this->assertSame([], $this->infoCenterFor($qa)['wiki_qa_tasks']);
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
        $count = $this->myTasksCountOrNull($infoCenter);

        if ($count === null) {
            $this->fail('expected a "Mine oppgaver" counter');
        }

        return $count;
    }

    /** @param array<string, mixed> $infoCenter */
    private function myTasksCountOrNull(array $infoCenter): ?int
    {
        foreach ($infoCenter['summary']['items'] as $item) {
            if ($item['key'] === 'my_tasks') {
                return (int) $item['count'];
            }
        }

        return null;
    }

    private function assign(User $actor, EnterpriseWikiPage $page, User $assignee): void
    {
        $this->actingAs($actor)
            ->patch(route('app.wiki.qa-assignment.update', ['slug' => $page->slug]), ['qa_user_id' => $assignee->id])
            ->assertSessionHasNoErrors();
    }

    /** @return array{0: Customer, 1: User, 2: EnterpriseWikiPage, 3: EnterpriseWikiPageVersion} */
    private function pageWithClaims(int $pending = 1, int $approved = 0, int $rejected = 0): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'infosenter-qa-'.Str::lower(Str::random(8)),
            'title' => 'Incident Response Team (IRT)',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);

        $version = $this->version($page, 1);

        foreach ([
            EnterpriseWikiClaim::APPROVAL_STATUS_PENDING => $pending,
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED => $approved,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED => $rejected,
        ] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $version->id,
                    'claim_text' => 'Vi har døgnbemannet responsteam.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'conflict_flag' => false,
                    'approval_status' => $status,
                    'position_order' => 0,
                ]);
            }
        }

        return [$customer, $owner, $page->fresh(), $version];
    }

    private function version(EnterpriseWikiPage $page, int $number): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => true,
            'content_markdown' => "# Versjon {$number}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function qaUser(Customer $customer): User
    {
        return $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
    }

    private function user(Customer $customer, string $bidRole, bool $isQa = false): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@infosenter.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_qa' => $isQa,
        ]);
    }

    private function customer(string $name = 'Infosenter QA AS'): Customer
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
