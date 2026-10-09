<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAudit;
use App\Models\Language;
use App\Models\Notice;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\BidWorkflowNotificationService;
use App\Services\UserNotificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesGovernanceTaskScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\TestCase;

/**
 * Punkt 6D: «Du er tildelt ansvar» in the bell for the styringsmoduler, and for an Anbud aksjon made
 * by hand. One notification per real handover, to a person who can read the object, never to the
 * one who made the choice, never twice, never when the save rolls back — and gone from the bell once
 * the person can no longer read the object.
 */
class GovernanceAssignmentNotificationTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesGovernanceTaskScenarios;
    use CreatesImprovementCaseScenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_owner_field_announces_a_new_ansvarlig(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $actor = $this->governancePerson($customer);
        $owner = $this->governancePerson($customer);
        Auth::login($actor);

        $risk = $this->riskOwnedBy($customer, $area, $owner, 'Leverandørsvikt');
        $this->riskAction($risk, $owner, '2026-11-01', 'Reserveleverandør');
        $case = $this->caseOwnedBy($customer, $area, $owner, 'Avvik i rutine');
        $this->improvementActionFor($case, $owner, '2026-11-01', 'Oppdater rutinen');
        $this->requirementOwnedBy($customer, $owner, 'Tilgangsstyring');
        $this->complianceAudit($customer, $owner, 'Internrevisjon');
        $this->qualityItemOwnedBy($customer, $owner, 'Tilgangskontroll');
        $objective = $this->objectiveOwnedBy($customer, $area, $owner, 'Leveransepresisjon');
        $this->kpiSince($objective, $owner, '2026-08-01', 'Oppetid');

        $notifications = UserNotification::query()->where('user_id', $owner->id)->orderBy('id')->get()->keyBy('event_type');

        $this->assertEqualsCanonicalizing([
            'risk.owner_assigned', 'risk.action_assigned', 'improvement.case_assigned', 'improvement.action_assigned',
            'compliance.requirement_assigned', 'compliance.audit_assigned', 'quality.item_assigned',
            'objective.objective_assigned', 'objective.kpi_assigned',
        ], $notifications->keys()->all());
        $this->assertSame('Du er satt som risikoeier for «Leverandørsvikt».', $notifications['risk.owner_assigned']->message);
        $this->assertSame('Du er ansvarlig for tiltaket «Reserveleverandør» på risikoen «Leverandørsvikt».', $notifications['risk.action_assigned']->message);
        $this->assertSame("/app/risk/risks/{$risk->id}", $notifications['risk.owner_assigned']->target_url);
        $this->assertSame((int) $actor->id, $notifications['risk.owner_assigned']->metadata['assigned_by_user_id']);
        $this->assertSame(0, UserNotification::query()->where('user_id', $actor->id)->count());

        // Every one of them is in the owner's bell, and each link opens.
        $panel = $this->bell($owner, 20);
        $this->assertSame(9, $panel['unread_count']);
        foreach ($panel['items'] as $item) {
            $this->actingAs($owner)->get($item['target_url'])->assertOk();
        }
    }

    public function test_only_a_real_handover_to_someone_else_is_announced(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $actor = $this->governancePerson($customer);
        $first = $this->governancePerson($customer);
        $second = $this->governancePerson($customer);
        Auth::login($actor);

        // Choosing yourself is not news.
        $this->riskOwnedBy($customer, $area, $actor, 'Egen');
        $this->assertSame(0, UserNotification::query()->where('user_id', $actor->id)->count());

        $risk = $this->riskOwnedBy($customer, $area, $first, 'Delt');
        // Later saves through the same instance, seconds apart so the dedupe key cannot hide a repeat.
        Carbon::setTestNow('2026-10-07 12:00:05');
        $risk->forceFill(['title' => 'Delt risiko'])->save();
        Carbon::setTestNow('2026-10-07 12:00:10');
        $risk->forceFill(['owner_user_id' => $first->id, 'cause' => 'nye rutiner'])->save();
        $this->assertSame(1, $this->countFor($first, 'risk.owner_assigned'));

        Carbon::setTestNow('2026-10-08 09:00:00');
        $risk->forceFill(['owner_user_id' => $second->id])->save();
        Carbon::setTestNow('2026-10-09 09:00:00');
        $risk->forceFill(['owner_user_id' => $first->id])->save();

        $this->assertSame(2, $this->countFor($first, 'risk.owner_assigned'), 'away and back is two handovers');
        $this->assertSame(1, $this->countFor($second, 'risk.owner_assigned'));
        $this->assertSame((int) $second->id, UserNotification::query()->where('user_id', $first->id)->latest('id')->first()->metadata['previous_owner_user_id']);

        // Removing the owner tells nobody.
        $risk->forceFill(['owner_user_id' => null])->save();
        $this->assertSame(3, UserNotification::query()->whereIn('user_id', [$first->id, $second->id])->count());
    }

    public function test_nobody_is_told_about_closed_work_unreadable_work_or_across_customers(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $otherArea = $this->area($customer, 'Økonomi');
        $actor = $this->governancePerson($customer);
        Auth::login($actor);

        $inactive = $this->governancePerson($customer);
        $inactive->forceFill(['is_active' => false])->save();
        $this->riskOwnedBy($customer, $area, $inactive);

        // A person who cannot read the risk's fagområde is not told about it.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, self::GOVERNANCE_PERMISSIONS, [$otherArea]);
        $this->riskOwnedBy($customer, $area, $elsewhere->fresh());

        // Closed and finished objects are no news.
        $reader = $this->governancePerson($customer);
        $this->riskOwnedBy($customer, $area, $reader, 'Lukket', Risk::STATUS_CLOSED);
        $this->riskAction($this->riskOwnedBy($customer, $area, null), $reader, '2026-11-01', 'Ferdig', RiskTreatmentAction::STATUS_COMPLETED);
        $this->complianceAudit($customer, null, 'Avlyst', ComplianceAudit::STATUS_CANCELLED)->forceFill(['responsible_user_id' => $reader->id])->save();

        // Another customer's person, named by force.
        ['customer' => $other] = $this->governanceCustomer();
        $outsider = $this->governancePerson($other);
        $risk = $this->riskOwnedBy($customer, $area, null, 'Feilkoblet');
        $risk->forceFill(['owner_user_id' => $outsider->id])->save();

        $this->assertSame(0, UserNotification::query()->whereIn('user_id', [$inactive->id, $elsewhere->id, $reader->id, $outsider->id])->count());
    }

    public function test_a_handover_that_rolls_back_is_never_announced(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $actor = $this->governancePerson($customer);
        $owner = $this->governancePerson($customer);
        Auth::login($actor);

        try {
            DB::transaction(function () use ($customer, $area, $owner): void {
                $this->caseOwnedBy($customer, $area, $owner, 'Rulles tilbake');

                throw new RuntimeException('the save failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, UserNotification::query()->where('user_id', $owner->id)->count());
    }

    public function test_the_bell_hides_the_notification_once_the_object_cannot_be_read(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $actor = $this->governancePerson($customer);
        $owner = $this->member($customer);
        $role = $this->grant($customer, $owner, self::GOVERNANCE_PERMISSIONS, [$area]);
        Auth::login($actor);

        $risk = $this->riskOwnedBy($customer, $area, $owner->fresh(), 'Sensitiv risiko');
        $objective = $this->objectiveOwnedBy($customer, $area, $owner->fresh(), 'Sensitivt mål');
        $this->assertSame(2, $this->bell($owner->fresh())['unread_count']);

        // The fagområde is taken away: neither title is shown any more, nor counted.
        $role->syncBusinessAreas(false, []);
        $panel = $this->bell($owner->fresh());
        $this->assertSame(0, $panel['unread_count']);
        $this->assertStringNotContainsString('Sensitiv', json_encode($panel));
        $this->actingAs($owner->fresh())->get("/app/risk/risks/{$risk->id}")->assertNotFound();

        // Back again — and then the object is deleted: its notification goes with it.
        $role->syncBusinessAreas(false, [(int) $area->id]);
        $this->assertSame(2, $this->bell($owner->fresh())['unread_count']);
        $objective->kpis()->delete();
        DB::table('objectives')->where('id', $objective->id)->delete();
        $this->assertSame(1, $this->bell($owner->fresh())['unread_count']);
    }

    public function test_a_hand_made_anbud_aksjon_tells_its_new_ansvarlig(): void
    {
        ['customer' => $customer] = $this->governanceCustomer();
        $manager = $this->governancePerson($customer);
        $manager->forceFill(['bid_role' => User::BID_ROLE_SYSTEM_OWNER, 'role' => User::ROLE_CUSTOMER_ADMIN])->save();
        $assignee = $this->governancePerson($customer);
        $reference = Str::upper(Str::random(10));
        Notice::query()->firstOrCreate(['notice_id' => $reference], ['title' => 'Kunngjøring', 'source' => 'doffin']);
        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => $reference, 'title' => 'Rammeavtale renhold',
            'buyer_name' => 'Etaten', 'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS, 'bid_manager_user_id' => $manager->id,
        ]);
        $aksjon = fn (User $owner, string $subject) => $this->actingAs($manager->fresh())->post(route('app.notices.saved.info-items.store', ['savedNotice' => $notice->id]), [
            'type' => SavedNoticeInfoItem::TYPE_MESSAGE, 'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL, 'subject' => $subject, 'body' => $subject,
            'status' => SavedNoticeInfoItem::STATUS_OPEN, 'owner_user_id' => $owner->id,
        ])->assertSessionHasNoErrors();

        $aksjon($assignee, 'Avklar opsjoner');
        $aksjon($manager, 'Egen aksjon');

        $notification = UserNotification::query()->where('event_type', BidWorkflowNotificationService::EVENT_TASK_ASSIGNED)->sole();
        $this->assertSame((int) $assignee->id, (int) $notification->user_id);
        $this->assertStringContainsString('Avklar opsjoner', $notification->message);
    }

    public function test_the_message_is_in_the_recipients_language(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $actor = $this->governancePerson($customer);
        $owner = $this->governancePerson($customer);
        $english = Language::query()->firstOrCreate(['code' => 'en'], ['name_en' => 'English', 'name_no' => 'Engelsk']);
        $owner->forceFill(['preferred_language_id' => $english->id])->save();
        Auth::login($actor);

        $this->caseOwnedBy($customer, $area, $owner->fresh(), 'Late deliveries');

        $notification = UserNotification::query()->where('user_id', $owner->id)->sole();
        $this->assertSame('Case responsibility', $notification->title);
        $this->assertSame('You are responsible for the case «Late deliveries» in Deviations and improvements.', $notification->message);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function bell(User $user, int $limit = 10): array
    {
        return app(UserNotificationService::class)->panelPayload($user, $limit);
    }

    private function countFor(User $user, string $event): int
    {
        return UserNotification::query()->where('user_id', $user->id)->where('event_type', $event)->count();
    }
}
