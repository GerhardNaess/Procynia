<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerAccessChange;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\SavedNoticeAccessService;
use App\Services\SavedNoticeStatusService;
use App\Services\Security\CustomerAccessChangeRecorder as Recorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Phase 0, step 18 — access and traceability at case level:
 *  - granting and revoking per-case access, and changing the commercial owner or Bid Manager, are
 *    recorded with the person who did it (customer_access_changes, subject saved_notice);
 *  - moving a case to history names who did it (archived_by_user_id);
 *  - on a case in history, owner and Bid Manager are changed only by whoever administers access to
 *    the case — on an active case it stays ordinary case work.
 */
class TenderCaseAccessTrailTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_granting_changing_and_revoking_case_access_is_recorded_with_its_actor(): void
    {
        $world = $this->world();
        $grant = fn (string $role) => $this->actingAs($world['owner'])
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $world['notice']->id]), ['user_id' => $world['colleague']->id, 'access_role' => $role])
            ->assertRedirect();

        $grant(SavedNoticeUserAccess::ACCESS_ROLE_VIEWER);
        $first = $this->latest($world);
        $this->assertSame(Recorder::CASE_ACCESS_GRANTED, $first->change_type);
        $this->assertSame($world['owner']->id, (int) $first->actor_user_id);
        $this->assertNull($first->before);
        $this->assertSame(['user_id' => $world['colleague']->id, 'access_role' => 'viewer'], $first->after);

        // The same row is reused for a new grant; what it held is kept in the trail.
        $grant(SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);
        $second = $this->latest($world);
        $this->assertSame(['user_id' => $world['colleague']->id, 'access_role' => 'viewer'], $second->before);
        $this->assertSame(['user_id' => $world['colleague']->id, 'access_role' => 'contributor'], $second->after);

        $access = SavedNoticeUserAccess::query()->where('saved_notice_id', $world['notice']->id)->where('user_id', $world['colleague']->id)->sole();
        $this->actingAs($world['owner'])
            ->delete(route('app.notices.saved.case-access.destroy', ['savedNotice' => $world['notice']->id, 'caseAccess' => $access->id]))
            ->assertRedirect();
        $revoked = $this->latest($world);
        $this->assertSame(Recorder::CASE_ACCESS_REVOKED, $revoked->change_type);
        $this->assertSame($world['owner']->id, (int) $revoked->actor_user_id);
        $this->assertSame(['user_id' => $world['colleague']->id, 'access_role' => 'contributor'], $revoked->before);
        $this->assertNull($revoked->after);
    }

    /** On an active case, changing owner and Bid Manager stays ordinary case work, now recorded. */
    public function test_owner_and_bid_manager_changes_on_an_active_case_are_recorded(): void
    {
        $world = $this->world();

        $this->actingAs($world['contributor'])
            ->patch(route('app.notices.saved.opportunity-owner.update', ['savedNotice' => $world['notice']->id]), ['opportunity_owner_user_id' => $world['commercial']->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $owner = $this->latest($world);
        $this->assertSame(Recorder::CASE_OPPORTUNITY_OWNER_CHANGED, $owner->change_type);
        $this->assertSame($world['contributor']->id, (int) $owner->actor_user_id);
        $this->assertSame(['opportunity_owner_user_id' => null], $owner->before);
        $this->assertSame(['opportunity_owner_user_id' => $world['commercial']->id], $owner->after);

        $this->actingAs($world['contributor'])
            ->patch(route('app.notices.saved.bid-manager.update', ['savedNotice' => $world['notice']->id]), ['bid_manager_user_id' => $world['bidManager']->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $manager = $this->latest($world);
        $this->assertSame(Recorder::CASE_BID_MANAGER_CHANGED, $manager->change_type);
        $this->assertSame(['bid_manager_user_id' => $world['bidManager']->id], $manager->after);
    }

    /**
     * A case in history: a contributor who may work on it cannot hand it to someone else, and is not
     * offered to; whoever administers its access still can.
     */
    public function test_owner_and_bid_manager_on_a_case_in_history_follow_who_administers_its_access(): void
    {
        $world = $this->world();
        $world['notice']->forceFill(['archived_at' => now(), 'history_type' => SavedNotice::HISTORY_TYPE_LOST])->save();
        $trail = $this->trailSize($world);

        foreach ([
            'opportunity-owner' => ['opportunity_owner_user_id' => $world['commercial']->id],
            'bid-manager' => ['bid_manager_user_id' => $world['bidManager']->id],
        ] as $route => $payload) {
            $this->actingAs($world['contributor'])
                ->patch(route("app.notices.saved.{$route}.update", ['savedNotice' => $world['notice']->id]), $payload)
                ->assertForbidden();
        }

        $fresh = $world['notice']->fresh();
        $this->assertNull($fresh->opportunity_owner_user_id);
        $this->assertNull($fresh->bid_manager_user_id);
        $this->assertSame($trail, $this->trailSize($world));

        $this->actingAs($world['contributor'])
            ->get(route('app.notices.saved.show', ['savedNotice' => $world['notice']->id]))
            ->assertOk()
            ->assertViewHas('page', function (array $page): bool {
                $this->assertNull(data_get($page, 'props.notice.actions.update_opportunity_owner_url'));
                $this->assertNull(data_get($page, 'props.notice.actions.update_bid_manager_url'));

                return true;
            });

        $this->actingAs($world['owner'])
            ->patch(route('app.notices.saved.opportunity-owner.update', ['savedNotice' => $world['notice']->id]), ['opportunity_owner_user_id' => $world['commercial']->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($world['commercial']->id, (int) $world['notice']->fresh()->opportunity_owner_user_id);
        $this->assertSame($world['owner']->id, (int) $this->latest($world)->actor_user_id);
    }

    public function test_moving_a_case_to_history_names_who_did_it_and_reopening_clears_it(): void
    {
        $world = $this->world();

        $this->actingAs($world['contributor'])
            ->patch(route('app.notices.saved.archive', ['savedNotice' => $world['notice']->id]), ['history_type' => SavedNotice::HISTORY_TYPE_LOST])
            ->assertRedirect();
        $this->assertSame($world['contributor']->id, (int) $world['notice']->fresh()->archived_by_user_id);

        // A case archived before this was recorded keeps an unknown archiver — nothing is filled in.
        $legacy = $this->notice($world['customer'], $world['owner']);
        $legacy->forceFill(['archived_at' => now()->subYear(), 'history_type' => SavedNotice::HISTORY_TYPE_WON])->save();
        $this->assertNull($legacy->fresh()->archived_by_user_id);

        // A No-Go that is reopened is no longer in history, so it names no archiver.
        $noGo = $this->notice($world['customer'], $world['owner']);
        $noGo->forceFill(['bid_status' => SavedNotice::BID_STATUS_NO_GO, 'archived_at' => now(), 'archived_by_user_id' => $world['owner']->id, 'history_type' => SavedNotice::HISTORY_TYPE_NO_GO])->save();
        DB::table('saved_notice_no_go_decisions')->insert(['saved_notice_id' => $noGo->id, 'customer_id' => $world['customer']->id, 'closed_by_user_id' => $world['owner']->id, 'closure_reason' => SavedNotice::BID_CLOSURE_REASON_CAPACITY, 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SavedNoticeStatusService::class)->reopenAfterNoGo($world['owner'], $noGo, 'Ny vurdering.', true);
        $this->assertNull($noGo->fresh()->archived_at);
        $this->assertNull($noGo->fresh()->archived_by_user_id);
    }

    /** Another customer reaches neither the case nor its trail. */
    public function test_another_customer_neither_changes_the_case_nor_appears_in_its_trail(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');

        $this->actingAs($other['owner'])
            ->patch(route('app.notices.saved.bid-manager.update', ['savedNotice' => $world['notice']->id]), ['bid_manager_user_id' => $other['bidManager']->id])
            ->assertNotFound();
        $this->actingAs($other['owner'])
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $world['notice']->id]), ['user_id' => $other['colleague']->id, 'access_role' => 'viewer'])
            ->assertNotFound();

        $this->assertNull($world['notice']->fresh()->bid_manager_user_id);
        $this->assertSame(0, $this->trailSize($world));

        // And the rule itself, without the controller's lookup: a System Owner administers case
        // access in their own customer only (§2m).
        $this->assertTrue(app(SavedNoticeAccessService::class)->canManageContributorAccess($world['owner'], $world['notice']));
        $this->assertFalse(app(SavedNoticeAccessService::class)->canManageContributorAccess($other['owner'], $world['notice']));
        $this->assertSame(0, CustomerAccessChange::query()->where('customer_id', $other['customer']->id)->where('subject_type', CustomerAccessChange::SUBJECT_SAVED_NOTICE)->count());
    }

    private function latest(array $world): CustomerAccessChange
    {
        return CustomerAccessChange::query()
            ->where('customer_id', $world['customer']->id)
            ->where('subject_type', CustomerAccessChange::SUBJECT_SAVED_NOTICE)
            ->where('subject_id', $world['notice']->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function trailSize(array $world): int
    {
        return CustomerAccessChange::query()->where('customer_id', $world['customer']->id)->where('subject_type', CustomerAccessChange::SUBJECT_SAVED_NOTICE)->count();
    }

    /**
     * One customer with one active case («Se alle saker» narrowed to System Owner, so case access
     * comes from being part of it): owner (System Owner), contributor (works on the case), colleague
     * (contributor with no part in it), a Bid Manager and a commercial owner to assign.
     *
     * @return array<string, mixed>
     */
    private function world(string $name = 'Saksspor AS'): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'permission_settings' => [Customer::PERMISSION_VIEW_ALL_CASES => ['system_owner']],
        ]);

        $person = fn (string $label, string $bidRole, string $role = User::ROLE_USER): User => User::factory()->create([
            'name' => $label,
            'email' => Str::slug($label).'.'.Str::lower(Str::random(6)).'@example.test',
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $owner = $person('Eier', User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);
        $contributor = $person('Bidragsyter', User::BID_ROLE_CONTRIBUTOR);
        $colleague = $person('Kollega', User::BID_ROLE_CONTRIBUTOR);
        $bidManager = $person('Bid Manager', User::BID_ROLE_BID_MANAGER, User::ROLE_CUSTOMER_ADMIN);
        $commercial = $person('Kommersiell', User::BID_ROLE_COMMERCIAL_OWNER);

        $notice = $this->notice($customer, $owner);
        SavedNoticeUserAccess::query()->create([
            'saved_notice_id' => $notice->id,
            'user_id' => $contributor->id,
            'granted_by_user_id' => $owner->id,
            'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
        ]);

        return compact('customer', 'owner', 'contributor', 'colleague', 'bidManager', 'commercial', 'notice');
    }

    private function notice(Customer $customer, User $savedBy): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $savedBy->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'S18-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'status' => 'ACTIVE',
        ]);
    }
}
