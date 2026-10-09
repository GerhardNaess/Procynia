<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\ImprovementAction;
use App\Models\Language;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MyTasks\MyTasksService;
use App\Services\MyTasks\TaskDeadlineReminderService;
use App\Services\UserNotificationService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesGovernanceTaskScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\TestCase;

/**
 * Punkt 6: fristpåminnelser. One daily sweep over «Mine oppgaver» for every person; at most one
 * «nærmer seg» and one «passert» per task and deadline, in the module's own window, and only about
 * work the person still has and may still see.
 */
class TaskDeadlineReminderTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesGovernanceTaskScenarios;
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use DatabaseTransactions;

    private const TODAY = '2026-10-07';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 06:45:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Auth::logout();
        parent::tearDown();
    }

    public function test_a_coming_deadline_is_announced_once_inside_the_window_and_never_outside_it(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);
        $case = $this->caseOwnedBy($customer, $area, $owner, 'Avvik');
        $soon = $this->improvementActionFor($case, $owner, '2026-10-10', 'Snart');
        $this->improvementActionFor($case, $owner, '2026-10-30', 'Senere');

        $this->sweep();
        $this->sweep();

        $reminders = $this->remindersFor($owner);
        $this->assertSame(['improvement.task_due_soon'], $reminders->pluck('event_type')->all());
        $this->assertSame('«Snart» har frist 10. oktober 2026.', $reminders->first()->message);
        $this->assertSame('improvement-action-'.$soon->id, $reminders->first()->metadata['task_id']);
        $this->assertStringContainsString("#improvement-action-{$soon->id}", $reminders->first()->target_url);
    }

    public function test_an_overdue_task_is_announced_once_and_again_only_when_its_deadline_moves(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);
        $case = $this->caseOwnedBy($customer, $area, $owner, 'Forfalt sak', '2026-10-01');

        $this->sweep();
        $this->sweep();
        $this->assertSame(['improvement.task_overdue'], $this->remindersFor($owner)->pluck('event_type')->all());
        $this->assertSame(UserNotification::SEVERITY_WARNING, $this->remindersFor($owner)->first()->severity);

        $case->forceFill(['due_date' => '2026-10-03'])->saveQuietly();
        $this->sweep();
        $this->sweep();
        $this->assertSame(2, $this->remindersFor($owner)->count(), 'a moved deadline is a new situation, once');
    }

    public function test_finished_reassigned_or_unreadable_work_is_never_reminded_about(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->member($customer);
        $role = $this->grant($customer, $owner, self::GOVERNANCE_PERMISSIONS, [$area]);
        $colleague = $this->governancePerson($customer);
        $case = $this->caseOwnedBy($customer, $area, $owner->fresh(), 'Avvik');

        $done = $this->improvementActionFor($case, $owner->fresh(), '2026-10-09', 'Ferdig');
        $done->forceFill(['status' => ImprovementAction::STATUS_COMPLETED, 'completed_at' => now(), 'completion_note' => 'Gjort.'])->saveQuietly();
        $moved = $this->improvementActionFor($case, $owner->fresh(), '2026-10-09', 'Flyttet');
        $moved->forceFill(['owner_user_id' => $colleague->id])->saveQuietly();
        $this->improvementActionFor($case, $owner->fresh(), '2026-10-08', 'Synlig');

        $this->sweep();
        $this->assertSame(['Synlig'], $this->remindedTitles($owner));
        $this->assertSame(['Flyttet'], $this->remindedTitles($colleague));

        // The fagområde is taken away: no new reminder, and the old one leaves the bell.
        $role->syncBusinessAreas(false, []);
        $this->improvementActionFor($case, $owner->fresh(), '2026-10-09', 'Etter tap av tilgang');
        $this->sweep();
        $this->assertSame(['Synlig'], $this->remindedTitles($owner));
        $this->assertSame(0, app(UserNotificationService::class)->panelPayload($owner->fresh())['unread_count']);
    }

    public function test_a_task_without_a_deadline_is_never_reminded_about(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);
        // Not assessed (no date), control without evidence (no date), audit planned far ahead.
        $this->riskOwnedBy($customer, $area, $owner);
        $this->qualityItemOwnedBy($customer, $owner);
        $this->auditFor($customer, $owner, '2026-12-31');

        $this->assertNotEmpty(app(MyTasksService::class)->tasksFor($owner, (int) $customer->id));
        $this->sweep();

        $this->assertSame(0, $this->remindersFor($owner)->count());
    }

    public function test_suppliers_use_their_60_days_and_anbud_its_7(): void
    {
        ['customer' => $customer] = $this->governanceCustomer();
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $owner, 'Drift AS');
        SupplierDocument::query()->create([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id,
            'document_type' => 'insurance_certificate', 'title' => 'Forsikring', 'valid_until' => '2026-11-20',
        ]);
        $notice = $this->savedNoticeFor($owner);
        $this->aksjon($notice, $owner, 'Om fem dager', '2026-10-12');
        $this->aksjon($notice, $owner, 'Om ti dager', '2026-10-17');

        $this->sweep();

        $reminders = $this->remindersFor($owner);
        $this->assertEqualsCanonicalizing(['supplier.task_due_soon', 'bid.task_due_soon'], $reminders->pluck('event_type')->all());
        $this->assertSame((int) $supplier->id, $reminders->firstWhere('event_type', 'supplier.task_due_soon')->metadata['supplier_id']);
        $this->assertSame((int) $notice->id, (int) $reminders->firstWhere('event_type', 'bid.task_due_soon')->saved_notice_id);
        $this->assertStringContainsString('Om fem dager', $reminders->firstWhere('event_type', 'bid.task_due_soon')->message);
    }

    public function test_inactive_people_are_skipped_and_english_people_get_english(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $inactive = $this->governancePerson($customer);
        $english = $this->governancePerson($customer);
        $english->forceFill(['preferred_language_id' => Language::query()->firstOrCreate(['code' => 'en'], ['name_en' => 'English', 'name_no' => 'Engelsk'])->id])->save();
        $this->caseOwnedBy($customer, $area, $inactive, 'Inaktiv', '2026-10-01');
        $this->caseOwnedBy($customer, $area, $english->fresh(), 'Late', '2026-10-01');
        $inactive->forceFill(['is_active' => false])->save();

        $this->sweep();

        $this->assertSame(0, $this->remindersFor($inactive)->count());
        $reminder = $this->remindersFor($english)->sole();
        $this->assertSame('Deadline passed', $reminder->title);
        $this->assertSame('«Late» was due 1 October 2026.', $reminder->message);
    }

    public function test_one_persons_failure_stops_nobody_else(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $broken = $this->governancePerson($customer);
        $fine = $this->governancePerson($customer);
        $this->caseOwnedBy($customer, $area, $fine, 'Forfalt', '2026-10-01');

        $real = app(MyTasksService::class);
        $mock = Mockery::mock(MyTasksService::class);
        $mock->shouldReceive('tasksFor')->andReturnUsing(function (User $user, int $customerId, $today) use ($broken, $real) {
            if ((int) $user->id === (int) $broken->id) {
                throw new RuntimeException('a broken row');
            }

            return $real->tasksFor($user, $customerId, $today);
        });
        $this->app->instance(MyTasksService::class, $mock);

        $counts = app(TaskDeadlineReminderService::class)->remindCustomer($customer, Carbon::parse(self::TODAY));

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $this->remindersFor($fine)->count());
    }

    public function test_the_command_is_scheduled_daily_and_safe_to_rerun(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);
        $this->caseOwnedBy($customer, $area, $owner, 'Forfalt', '2026-10-01');

        $this->artisan('notifications:task-reminders', ['--customer' => $customer->id, '--date' => self::TODAY])
            ->expectsOutputToContain('1 «overdue»')
            ->assertSuccessful();
        // The rerun reports what it actually wrote: nothing.
        $this->artisan('notifications:task-reminders', ['--customer' => $customer->id, '--date' => self::TODAY])
            ->expectsOutputToContain('0 «overdue»')
            ->assertSuccessful();
        $this->assertSame(1, $this->remindersFor($owner)->count());

        $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:task-reminders'));
        $this->assertNotNull($event, 'the reminder sweep is on the schedule');
        $this->assertSame('45 6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function sweep(): void
    {
        foreach (Customer::query()->where('is_active', true)->get() as $customer) {
            app(TaskDeadlineReminderService::class)->remindCustomer($customer, Carbon::parse(self::TODAY));
        }
    }

    private function remindersFor(User $user)
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('event_type', 'like', '%.task_due_soon')->orWhere('event_type', 'like', '%.task_overdue'))
            ->orderBy('id')
            ->get();
    }

    /** @return list<string> */
    private function remindedTitles(User $user): array
    {
        return $this->remindersFor($user)
            ->map(fn (UserNotification $notification): string => Str::between($notification->message, '«', '»'))
            ->all();
    }

    private function savedNoticeFor(User $user): SavedNotice
    {
        $reference = Str::upper(Str::random(10));
        Notice::query()->firstOrCreate(['notice_id' => $reference], ['title' => 'Kunngjøring', 'source' => 'doffin']);

        return SavedNotice::query()->create([
            'customer_id' => $user->customer_id, 'external_id' => $reference, 'title' => 'Rammeavtale',
            'buyer_name' => 'Etaten', 'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS, 'bid_manager_user_id' => $user->id,
        ]);
    }

    private function aksjon(SavedNotice $notice, User $owner, string $subject, string $dueOn): void
    {
        (new SavedNoticeInfoItem)->forceFill([
            'saved_notice_id' => $notice->id, 'type' => SavedNoticeInfoItem::TYPE_MESSAGE,
            'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL, 'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => $subject, 'body' => $subject, 'status' => SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => false, 'response_due_at' => $dueOn,
            'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id,
        ])->save();
    }
}
