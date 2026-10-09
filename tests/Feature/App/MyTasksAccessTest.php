<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\UserNotificationService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\Concerns\ReadsMyTasks;
use Tests\TestCase;

/**
 * Punkt 6A: access is decided again on every read, for «Mine oppgaver» and for the bell.
 *
 * The finding this closes: a Wiki task was shown to its assignee whether or not they could still open
 * the Wiki, so a person who lost the module or wiki.view kept seeing page titles under Oppfølging.
 * The same held for the bell, which kept every title it was ever given. Losing access now empties
 * both on the next read, and restoring it brings them back — nothing is deleted.
 */
class MyTasksAccessTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;
    use ReadsMyTasks;

    // ── Wiki tasks ──────────────────────────────────────────────────────────

    public function test_a_reviewer_sees_the_wiki_task_while_they_hold_wiki_view(): void
    {
        [, $reviewer, $page] = $this->pageAwaitingReview();

        $tasks = $this->wikiTasksIn($this->infoCenterFor($reviewer));

        $this->assertSame([$page->title], array_column($tasks, 'page_title'));
        $this->assertSame('wiki', $tasks[0]['module']);
        $this->assertSame('no_due', $tasks[0]['group']);
    }

    public function test_losing_wiki_view_hides_the_task_and_its_title(): void
    {
        [, $reviewer, $page] = $this->pageAwaitingReview();
        $reviewer->customerRoles()->detach();

        $response = $this->actingAs($reviewer)->get(route('app.info-center.index', ['view' => 'my_tasks']))->assertOk();

        $this->assertSame([], $this->wikiTasksIn($response->viewData('page')['props']['infoCenter']));
        $this->assertSame(0, $this->myTasksCount($response->viewData('page')['props']['infoCenter']));
        $this->assertStringNotContainsString($page->title, $response->getContent());
    }

    public function test_a_customer_without_the_wiki_module_shows_no_wiki_task(): void
    {
        [$customer, $reviewer] = $this->pageAwaitingReview();
        $this->revokePackage($customer, 'tender');

        $this->assertSame([], $this->wikiTasksIn($this->infoCenterFor($reviewer)));
    }

    // ── Anbud aksjoner ──────────────────────────────────────────────────────

    public function test_an_open_aksjon_is_a_tender_task_with_its_deadline(): void
    {
        [$customer, $owner] = $this->customerWithUser();
        $notice = $this->savedNotice($customer);
        $this->aksjon($notice, $owner, 'Avklar kontraktsvilkår', now()->subDay()->toDateString());

        $tasks = $this->myTasksIn($this->infoCenterFor($owner), 'tender');

        $this->assertSame(['Avklar kontraktsvilkår'], array_column($tasks, 'title'));
        $this->assertSame('overdue', $tasks[0]['group']);
        $this->assertSame($notice->title, $tasks[0]['subject_title']);
        $this->assertSame((int) $notice->id, $tasks[0]['item']['saved_notice']['id']);
    }

    public function test_a_customer_without_the_tender_module_shows_no_aksjon(): void
    {
        [$customer, $owner] = $this->customerWithUser();
        $this->aksjon($this->savedNotice($customer), $owner, 'Avklar kontraktsvilkår');
        $this->revokePackage($customer, 'tender');

        $infoCenter = $this->infoCenterFor($owner);

        $this->assertSame([], $this->myTasksIn($infoCenter, 'tender'));
        $this->assertSame([], $infoCenter['items']);
    }

    // ── The bell ────────────────────────────────────────────────────────────

    public function test_a_wiki_notification_is_hidden_once_wiki_view_is_lost_and_back_once_restored(): void
    {
        [$customer, $reviewer, $page] = $this->pageAwaitingReview();
        $this->notification($reviewer, 'wiki.review_assigned', 'Til gjennomgang: '.$page->title);

        $this->assertSame(1, $this->bell($reviewer)['unread_count']);

        $reviewer->customerRoles()->detach();
        $panel = $this->bell($reviewer->fresh());

        $this->assertSame(0, $panel['unread_count']);
        $this->assertSame([], $panel['items']);

        $this->grantWikiPermissions($customer, $reviewer, [CustomerPermissionCatalog::WIKI_VIEW]);
        $this->assertSame(1, $this->bell($reviewer->fresh())['unread_count']);
    }

    public function test_a_case_notification_is_hidden_when_the_case_is_no_longer_visible(): void
    {
        [$customer, $contributor] = $this->customerWithUser(User::BID_ROLE_CONTRIBUTOR);
        $notice = $this->savedNotice($customer);
        $notice->forceFill(['bid_manager_user_id' => $contributor->id])->save();
        $this->notification($contributor, 'bid.deadline_approaching', 'Frist nærmer seg', $notice);

        $this->assertSame(1, $this->bell($contributor)['unread_count']);

        // No longer involved, and the customer does not let contributors see every case.
        $customer->forceFill(['permission_settings' => ['view_all_cases' => [User::BID_ROLE_SYSTEM_OWNER]]])->save();
        $notice->forceFill(['bid_manager_user_id' => null])->save();

        $this->assertSame(0, $this->bell($contributor->fresh())['unread_count']);
    }

    public function test_marking_everything_read_never_reaches_a_hidden_notification(): void
    {
        [, $reviewer] = $this->pageAwaitingReview();
        $hidden = $this->notification($reviewer, 'wiki.review_assigned', 'Til gjennomgang');
        $reviewer->customerRoles()->detach();

        app(UserNotificationService::class)->markAllAsRead($reviewer->fresh());

        $this->assertFalse((bool) $hidden->fresh()->is_read);
    }

    public function test_account_notifications_are_not_module_data_and_always_show(): void
    {
        [, $user] = $this->customerWithUser();
        $this->notification($user, 'ai_quota.warning', 'AI-kapasitet');
        $this->revokePackage($user->customer, 'tender');

        $this->assertSame(1, $this->bell($user->fresh())['unread_count']);
    }

    public function test_the_bell_reads_its_cases_in_one_query_however_many_there_are(): void
    {
        [$customer, $owner] = $this->customerWithUser();

        $queriesFor = function (int $count) use ($customer, $owner): int {
            UserNotification::query()->where('user_id', $owner->id)->delete();

            foreach (range(1, $count) as $i) {
                $notice = $this->savedNotice($customer);
                $notice->forceFill(['bid_manager_user_id' => $owner->id])->save();
                $this->notification($owner, 'bid.case_inactive', 'Sak uten fremdrift '.$i, $notice);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $panel = app(UserNotificationService::class)->panelPayload($owner->fresh());
            DB::disableQueryLog();

            $this->assertCount($count, $panel['items']);
            $this->assertNotNull($panel['items'][0]['saved_notice']);

            return count(DB::getQueryLog());
        };

        $this->assertSame($queriesFor(2), $queriesFor(8));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function infoCenterFor(User $user): array
    {
        return $this->actingAs($user)
            ->get(route('app.info-center.index', ['view' => 'my_tasks']))
            ->assertOk()
            ->viewData('page')['props']['infoCenter'];
    }

    /** @param  array<string, mixed>  $infoCenter */
    private function myTasksCount(array $infoCenter): int
    {
        return (int) collect($infoCenter['summary']['items'])->firstWhere('key', 'my_tasks')['count'];
    }

    /** @return array<string, mixed> */
    private function bell(User $user): array
    {
        return $this->actingAs($user)->getJson(route('app.notifications.index'))->assertOk()->json('notifications');
    }

    private function notification(User $user, string $eventType, string $title, ?SavedNotice $notice = null): UserNotification
    {
        return UserNotification::query()->create([
            'customer_id' => $user->customer_id,
            'user_id' => $user->id,
            'saved_notice_id' => $notice?->id,
            'event_type' => $eventType,
            'dedupe_key' => $eventType.':'.Str::random(12),
            'severity' => UserNotification::SEVERITY_INFO,
            'title' => $title,
            'message' => $title,
            'target_url' => '/app',
        ]);
    }

    private function revokePackage(Customer $customer, string $packageKey): void
    {
        CustomerPackageEntitlement::query()
            ->where('customer_id', $customer->id)
            ->where('package_key', $packageKey)
            ->update(['status' => CustomerPackageEntitlement::STATUS_REVOKED]);
    }

    /** @return array{0: Customer, 1: User, 2: EnterpriseWikiPage} */
    private function pageAwaitingReview(): array
    {
        [$customer, $owner] = $this->customerWithUser();
        $this->grantWikiPermissions($customer, $owner);
        $reviewer = $this->grantWikiPermissions($customer, $this->user($customer, User::BID_ROLE_CONTRIBUTOR), [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_REVIEW]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'tilgang-'.Str::lower(Str::random(8)),
            'title' => 'Beredskapsplan '.Str::upper(Str::random(4)),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Beredskapsplan',
            'generated_by_model' => 'gpt-5',
            'reviewer_user_id' => $reviewer->id,
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
        ]);

        return [$customer, $reviewer->fresh(), $page];
    }

    private function aksjon(SavedNotice $notice, User $owner, string $subject, ?string $dueOn = null): SavedNoticeInfoItem
    {
        $item = new SavedNoticeInfoItem;
        $item->forceFill([
            'saved_notice_id' => $notice->id,
            'type' => SavedNoticeInfoItem::TYPE_MESSAGE,
            'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => $subject,
            'body' => $subject,
            'status' => SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => false,
            'response_due_at' => $dueOn,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ])->save();

        return $item;
    }

    private function savedNotice(Customer $customer): SavedNotice
    {
        $reference = Str::upper(Str::random(10));

        Notice::query()->firstOrCreate(
            ['notice_id' => $reference],
            ['title' => 'Kunngjøring '.$reference, 'source' => 'doffin'],
        );

        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => $reference,
            'title' => 'Anskaffelse '.$reference,
            'buyer_name' => 'Testetaten',
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);
    }

    /** @return array{0: Customer, 1: User} */
    private function customerWithUser(string $bidRole = User::BID_ROLE_SYSTEM_OWNER): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Tilgang AS',
            'slug' => 'tilgang-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);

        return [$customer, $this->user($customer, $bidRole)];
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@tilgang.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
