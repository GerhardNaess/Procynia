<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticePhaseComment;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\BidWorkflowNotificationService;
use App\Services\InfoCenter\RequirementResponsibilityTaskService;
use App\Services\SavedNoticeWorkflowSignals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bid workflow's notifications, written into the panel Procynia already has.
 *
 * Sending them is the easy half. These tests are mostly about the other half: a daily sweep that
 * says the same thing every morning is noise, and noise is what makes people stop reading the panel
 * at all. So nearly every case below runs the check twice and asserts the second run is silent —
 * and then changes the underlying situation and asserts it speaks again.
 */
class BidWorkflowNotificationTest extends TestCase
{
    use DatabaseTransactions;

    // ── Task assigned ───────────────────────────────────────────────────────

    public function test_the_new_owner_is_told_they_have_the_task(): void
    {
        [$customer, $notice] = $this->activeCase();
        $assignee = $this->user($customer);
        $actor = $this->user($customer);

        $this->assignRequirement($notice, $assignee, $actor);

        $notification = $this->notificationsFor($assignee, BidWorkflowNotificationService::EVENT_TASK_ASSIGNED)->sole();

        $this->assertSame('Ny oppgave', $notification->title);
        $this->assertStringContainsString($notice->title, $notification->message);
        $this->assertSame(UserNotification::SEVERITY_INFO, $notification->severity);
        $this->assertSame((int) $notice->id, (int) $notification->saved_notice_id);
    }

    public function test_reassigning_tells_the_new_person_and_not_the_old_one(): void
    {
        [$customer, $notice] = $this->activeCase();
        $first = $this->user($customer);
        $second = $this->user($customer);
        $actor = $this->user($customer);

        $requirement = $this->assignRequirement($notice, $first, $actor);
        $this->assignRequirement($notice, $second, $actor, $requirement);

        $this->assertCount(1, $this->notificationsFor($first, BidWorkflowNotificationService::EVENT_TASK_ASSIGNED));
        $this->assertCount(1, $this->notificationsFor($second, BidWorkflowNotificationService::EVENT_TASK_ASSIGNED));
    }

    /** Re-saving an unchanged assignment is not news, and must not look like a second handover. */
    public function test_syncing_the_same_assignment_again_says_nothing(): void
    {
        [$customer, $notice] = $this->activeCase();
        $assignee = $this->user($customer);
        $actor = $this->user($customer);

        $requirement = $this->assignRequirement($notice, $assignee, $actor);
        $this->taskService()->syncRequirementTask($requirement->fresh(), $actor);

        $this->assertCount(1, $this->notificationsFor($assignee, BidWorkflowNotificationService::EVENT_TASK_ASSIGNED));
    }

    /** Telling someone what they just did themselves is noise, not news. */
    public function test_assigning_a_task_to_yourself_notifies_nobody(): void
    {
        [$customer, $notice] = $this->activeCase();
        $person = $this->user($customer);

        $this->assignRequirement($notice, $person, $person);

        $this->assertCount(0, $this->notificationsFor($person, BidWorkflowNotificationService::EVENT_TASK_ASSIGNED));
    }

    // ── Deadline approaching ────────────────────────────────────────────────

    public function test_both_the_bid_manager_and_the_commercial_owner_are_warned(): void
    {
        [$customer, $notice, $bidManager, $commercialOwner] = $this->caseWithDeadline(now()->addDays(3));

        $this->sweep($customer);

        $this->assertCount(1, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
        $this->assertCount(1, $this->notificationsFor($commercialOwner, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));

        $notification = $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING)->sole();
        $this->assertSame('Frist nærmer seg', $notification->title);
        $this->assertSame(UserNotification::SEVERITY_WARNING, $notification->severity);
        $this->assertStringContainsString($notice->title, $notification->message);
    }

    /** One person holding both roles is one person to tell. */
    public function test_one_person_in_both_roles_gets_one_notification(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(now()->addDays(2));
        $notice->forceFill(['opportunity_owner_user_id' => $bidManager->id])->save();

        $this->sweep($customer);

        $this->assertCount(1, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    /** The point of the whole design: five days of warning, not five warnings. */
    public function test_running_the_sweep_again_writes_nothing(): void
    {
        [$customer, , $bidManager] = $this->caseWithDeadline(now()->addDays(3));

        $this->sweep($customer);
        $this->sweep($customer);
        $this->sweep($customer);

        $this->assertCount(1, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    /** A moved deadline is a new fact, and worth saying again. */
    public function test_a_moved_deadline_is_announced_again(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(now()->addDays(2));

        $this->sweep($customer);
        $notice->forceFill(['deadline' => now()->addDays(4)])->save();
        $this->sweep($customer);

        $this->assertCount(2, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    /**
     * Each of the dates somebody actually has work to do about. Run as one test rather than four,
     * because what matters is that the whole set still warns — not any one column.
     */
    public function test_every_actionable_deadline_is_announced(): void
    {
        foreach (['deadline', 'questions_deadline_at', 'questions_rfi_deadline_at', 'rfi_submission_deadline_at', 'questions_rfp_deadline_at'] as $attribute) {
            [$customer, $notice, $bidManager] = $this->caseWithDeadline(null);
            $notice->forceFill([$attribute => now()->addDays(3)])->save();

            $this->sweep($customer);

            $this->assertCount(
                1,
                $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING),
                "{$attribute} is work somebody has to do, and must be announced",
            );
        }
    }

    /**
     * The award date is when the BUYER intends to decide. Warning someone five days before it asks
     * them to act on something they do not control, and a panel full of dates nobody can act on is
     * how people stop reading the panel.
     */
    public function test_the_award_date_is_a_milestone_and_never_warned_about(): void
    {
        [$customer, $notice, $bidManager, $commercialOwner] = $this->caseWithDeadline(null);
        $notice->forceFill(['award_date_at' => now()->addDays(3)])->save();

        $this->sweep($customer);

        $this->assertCount(0, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
        $this->assertCount(0, $this->notificationsFor($commercialOwner, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    /** A milestone must not mask the real deadline sitting behind it either. */
    public function test_an_award_date_does_not_displace_a_real_deadline(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(null);
        $notice->forceFill([
            'award_date_at' => now()->addDays(1),
            'questions_deadline_at' => now()->addDays(4),
        ])->save();

        $this->sweep($customer);

        $notification = $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING)->sole();

        $this->assertSame('questions_deadline_at', $notification->metadata['deadline_type']);
    }

    /** The cockpit is right to list the milestone; only the notification sweep narrows the set. */
    public function test_the_cockpit_still_lists_the_award_date(): void
    {
        $this->assertArrayHasKey('award_date_at', SavedNoticeWorkflowSignals::DEADLINE_DEFINITIONS);

        [, $notice] = $this->activeCase();
        $notice->forceFill(['award_date_at' => now()->addDays(3)])->save();

        $signals = app(SavedNoticeWorkflowSignals::class);

        $this->assertSame(
            ['award_date_at'],
            array_column($signals->deadlinesWithin($notice->fresh()), 'type'),
            'the timeline keeps the date',
        );
        $this->assertSame(
            [],
            $signals->actionableDeadlinesWithin($notice->fresh()),
            'the notification sweep does not',
        );
    }

    public function test_a_deadline_further_out_than_the_window_is_not_announced(): void
    {
        [$customer, , $bidManager] = $this->caseWithDeadline(now()->addDays(12));

        $this->sweep($customer);

        $this->assertCount(0, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    /** A won case has nothing left to chase, and its deadline is a date that already passed us by. */
    public function test_a_finished_case_is_never_warned_about(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(now()->addDays(2));
        $notice->forceFill(['bid_status' => SavedNotice::BID_STATUS_WON])->save();

        $this->sweep($customer);

        $this->assertCount(0, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    public function test_an_archived_case_is_never_warned_about(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(now()->addDays(2));
        $notice->forceFill(['archived_at' => now()])->save();

        $this->sweep($customer);

        $this->assertCount(0, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    // ── Case without progress ───────────────────────────────────────────────

    public function test_the_bid_manager_is_told_a_case_has_gone_quiet(): void
    {
        [$customer, $notice, $bidManager, $commercialOwner] = $this->caseWithDeadline(null);
        $this->makeQuietSince($notice, now()->subDays(10));

        $this->sweep($customer);

        $notification = $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_CASE_INACTIVE)->sole();

        $this->assertSame('Sak uten fremdrift', $notification->title);
        $this->assertSame(UserNotification::SEVERITY_WARNING, $notification->severity);
        $this->assertCount(
            0,
            $this->notificationsFor($commercialOwner, BidWorkflowNotificationService::EVENT_CASE_INACTIVE),
            'progress is the bid manager\'s job; telling the team would spread the responsibility',
        );
    }

    public function test_a_case_worked_on_recently_is_left_alone(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(null);
        $this->makeQuietSince($notice, now()->subDays(2));

        $this->sweep($customer);

        $this->assertCount(0, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_CASE_INACTIVE));
    }

    public function test_the_same_quiet_period_is_only_mentioned_once(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(null);
        $this->makeQuietSince($notice, now()->subDays(10));

        $this->sweep($customer);
        $this->sweep($customer);

        $this->assertCount(1, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_CASE_INACTIVE));
    }

    /**
     * The case people actually hit: a case goes quiet, someone picks it up, it goes quiet again.
     * The second silence is a new problem and deserves to be said — which is why the last activity
     * date is part of the dedupe key rather than a "notified" flag nobody would ever reset.
     */
    public function test_a_case_that_goes_quiet_again_is_mentioned_again(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(null);
        $this->makeQuietSince($notice, now()->subDays(30));

        $this->sweep($customer);

        // Somebody worked on it, then it went quiet once more.
        $comment = SavedNoticePhaseComment::query()->create([
            'saved_notice_id' => $notice->id,
            'user_id' => $bidManager->id,
            'phase_status' => $notice->bid_status,
            'comment' => 'Tok en runde på kravene.',
        ]);

        // Eloquent stamps created_at on insert, so the date it should have is written back after.
        SavedNoticePhaseComment::query()->whereKey($comment->id)->update(['created_at' => now()->subDays(9)]);
        SavedNotice::query()->whereKey($notice->id)->update(['updated_at' => now()->subDays(9)]);

        $this->sweep($customer);

        $this->assertCount(2, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_CASE_INACTIVE));
    }

    // ── Watch profile ───────────────────────────────────────────────────────

    public function test_the_watch_profile_owner_hears_about_a_new_match(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        [$profile, $record] = $this->watchProfileMatch($customer, $owner);

        $this->notifications()->watchProfileMatched($profile, $record);

        $notification = $this->notificationsFor($owner, BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH)->sole();

        $this->assertSame('Ny relevant kunngjøring', $notification->title);
        $this->assertStringContainsString($profile->name, $notification->message);
        $this->assertStringContainsString('tab=alerts', (string) $notification->target_url);
        $this->assertNull($notification->saved_notice_id, 'a match is not a case yet');
    }

    public function test_the_same_notice_never_alerts_twice_for_the_same_profile(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        [$profile, $record] = $this->watchProfileMatch($customer, $owner);

        $this->notifications()->watchProfileMatched($profile, $record);
        $this->notifications()->watchProfileMatched($profile, $record);

        $this->assertCount(1, $this->notificationsFor($owner, BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH));
    }

    /**
     * A department-wide profile has no one person whose opportunity this is. Guessing would put the
     * alert in front of people who never asked for it.
     */
    public function test_a_profile_with_no_owner_reaches_nobody(): void
    {
        $customer = $this->customer();
        [$profile, $record] = $this->watchProfileMatch($customer, null);

        $this->notifications()->watchProfileMatched($profile, $record);

        $this->assertSame(0, UserNotification::query()
            ->where('event_type', BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH)
            ->where('customer_id', $customer->id)
            ->count());
    }

    // ── Customer isolation ──────────────────────────────────────────────────

    /**
     * A notification names a case and links to it. Addressed to the wrong customer it would be a
     * cross-tenant leak with a clickable URL attached, so the recipient is checked against the
     * customer the notification is about before anything is written.
     */
    public function test_a_user_from_another_customer_is_never_notified(): void
    {
        [$customerA, $noticeA] = $this->activeCase();
        $customerB = $this->customer('Annen Kunde AS');
        $outsider = $this->user($customerB);

        $noticeA->forceFill([
            'bid_manager_user_id' => $outsider->id,
            'deadline' => now()->addDays(2),
        ])->save();

        $this->sweep($customerA);

        $this->assertSame(0, UserNotification::query()->where('user_id', $outsider->id)->count());
    }

    public function test_a_sweep_of_one_customer_never_reaches_another(): void
    {
        [$customerA, , $managerA] = $this->caseWithDeadline(now()->addDays(2));
        [$customerB, , $managerB] = $this->caseWithDeadline(now()->addDays(2));

        $this->sweep($customerA);

        $this->assertCount(1, $this->notificationsFor($managerA, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
        $this->assertCount(0, $this->notificationsFor($managerB, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    public function test_an_inactive_user_is_not_notified(): void
    {
        [$customer, , $bidManager] = $this->caseWithDeadline(now()->addDays(2));
        $bidManager->forceFill(['is_active' => false])->save();

        $this->sweep($customer);

        $this->assertCount(0, $this->notificationsFor($bidManager, BidWorkflowNotificationService::EVENT_DEADLINE_APPROACHING));
    }

    // ── Target URL ──────────────────────────────────────────────────────────

    public function test_every_case_notification_links_to_its_case(): void
    {
        [$customer, $notice, $bidManager] = $this->caseWithDeadline(now()->addDays(2));
        $this->makeQuietSince($notice, now()->subDays(10));

        $this->sweep($customer);

        $expected = route('app.notices.saved.show', ['savedNotice' => $notice->id], false);

        foreach (UserNotification::query()->where('user_id', $bidManager->id)->get() as $notification) {
            $this->assertSame($expected, $notification->target_url);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function notifications(): BidWorkflowNotificationService
    {
        return app(BidWorkflowNotificationService::class);
    }

    private function taskService(): RequirementResponsibilityTaskService
    {
        return app(RequirementResponsibilityTaskService::class);
    }

    private function sweep(Customer $customer): void
    {
        $this->notifications()->sweepCustomer($customer->id);
    }

    /** @return Collection<int, UserNotification> */
    private function notificationsFor(User $user, string $eventType)
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('event_type', $eventType)
            ->get();
    }

    private function assignRequirement(
        SavedNotice $notice,
        User $assignee,
        User $actor,
        ?SavedNoticeAiRequirement $requirement = null,
    ): SavedNoticeAiRequirement {
        $requirement ??= SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'requirement_text' => 'Leverandøren skal dokumentere informasjonssikkerhet.',
            'requirement_type' => 'requirement',
            'extraction_method' => 'manual',
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
        ]);

        $requirement->forceFill(['assigned_user_id' => $assignee->id])->save();
        $this->taskService()->syncRequirementTask($requirement->fresh(), $actor);

        return $requirement->fresh();
    }

    /**
     * Push every activity signal into the past. updated_at is set last and with a raw update,
     * because saving the model is itself activity.
     */
    private function makeQuietSince(SavedNotice $notice, Carbon $when): void
    {
        SavedNoticePhaseComment::query()->where('saved_notice_id', $notice->id)->delete();
        SavedNotice::query()->whereKey($notice->id)->update(['updated_at' => $when]);
    }

    /** @return array{0: Customer, 1: SavedNotice} */
    private function activeCase(): array
    {
        $customer = $this->customer();

        return [$customer, $this->savedNotice($customer)];
    }

    /** @return array{0: Customer, 1: SavedNotice, 2: User, 3: User} */
    private function caseWithDeadline(?Carbon $deadline): array
    {
        $customer = $this->customer();
        $bidManager = $this->user($customer);
        $commercialOwner = $this->user($customer);
        $notice = $this->savedNotice($customer);

        $notice->forceFill([
            'bid_manager_user_id' => $bidManager->id,
            'opportunity_owner_user_id' => $commercialOwner->id,
            'deadline' => $deadline,
        ])->save();

        return [$customer, $notice->fresh(), $bidManager, $commercialOwner];
    }

    /** @return array{0: WatchProfile, 1: WatchProfileInboxRecord} */
    private function watchProfileMatch(Customer $customer, ?User $owner): array
    {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $owner?->id,
            'name' => 'Sikkerhetstjenester Østlandet',
            'is_active' => true,
        ]);

        $record = WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'user_id' => $owner?->id,
            'doffin_notice_id' => 'DOFFIN-'.Str::upper(Str::random(6)),
            'title' => 'Rammeavtale for sikkerhetstjenester',
            'discovered_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [$profile, $record];
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

    private function user(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@varsel.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(string $name = 'Varsel AS'): Customer
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
