<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\SavedNoticeAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A44: «Lagre» on a notice that is already a case never opens the case to the person. It used to
 * grant contributor access to whoever could not see the case, so any colleague could join any case
 * by pressing save, and a reader could overwrite it. Creating a case is unchanged; changing one
 * takes the right to work on it; joining one goes through the case's own access controls.
 */
class TenderSaveExistingCaseTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        // The register lookup behind a save answers with nothing: identity is not what is tested.
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

    public function test_saving_a_case_one_cannot_see_grants_nothing_and_changes_nothing(): void
    {
        $world = $this->world();

        $this->save($world['bystander'], $world['notice']->external_id, 'Overskrevet tittel')
            ->assertRedirect()
            ->assertSessionHas('error', __('procynia.notices.save_existing_case_no_access'));

        $this->assertSame('Driftsavtale', $world['notice']->fresh()->title);
        $this->assertFalse(SavedNoticeUserAccess::query()->where('user_id', $world['bystander']->id)->exists());
        $this->assertFalse(app(SavedNoticeAccessService::class)->canView($world['bystander'], $world['notice']));
        $this->assertSame(1, SavedNotice::query()->where('customer_id', $world['customer']->id)->count());
    }

    public function test_a_reader_who_saves_keeps_reading_and_changes_nothing(): void
    {
        $world = $this->world();

        foreach (['per-case reader' => $world['caseViewer'], 'bid_role viewer' => $world['bidViewer']] as $who => $reader) {
            $this->save($reader, $world['notice']->external_id, 'Overskrevet av leser')
                ->assertRedirect()
                ->assertSessionHas('success', __('procynia.notices.save_existing_case_read_only'));

            $this->assertSame('Driftsavtale', $world['notice']->fresh()->title, $who);
            $this->assertFalse(app(SavedNoticeAccessService::class)->mayWork($reader->fresh(), $world['notice']), $who);
        }

        $this->assertSame(
            [SavedNoticeUserAccess::ACCESS_ROLE_VIEWER],
            SavedNoticeUserAccess::query()->where('user_id', $world['caseViewer']->id)->pluck('access_role')->all(),
        );
    }

    /** Someone who works on the case updates it from the register as before, and gains nothing new. */
    public function test_a_person_working_on_the_case_still_updates_it_by_saving(): void
    {
        $world = $this->world();
        $accessRows = SavedNoticeUserAccess::query()->count();

        $this->save($world['contributor'], $world['notice']->external_id, 'Oppdatert tittel fra Doffin')
            ->assertRedirect()
            ->assertSessionHas('success');

        $notice = $world['notice']->fresh();
        $this->assertSame('Oppdatert tittel fra Doffin', $notice->title);
        $this->assertSame($world['bidViewer']->id, (int) $notice->saved_by_user_id);
        $this->assertSame($accessRows, SavedNoticeUserAccess::query()->count());
    }

    /** Creating a case is unchanged: the person who saved it can see and work on it. */
    public function test_saving_a_new_notice_still_creates_a_case_for_the_person(): void
    {
        $world = $this->world();

        $this->save($world['bystander'], 'NY-'.Str::upper(Str::random(6)), 'Ny anskaffelse')
            ->assertRedirect()
            ->assertSessionHas('success');

        $case = SavedNotice::query()->where('customer_id', $world['customer']->id)->where('title', 'Ny anskaffelse')->sole();
        $this->assertSame($world['bystander']->id, (int) $case->saved_by_user_id);
        $this->assertTrue(app(SavedNoticeAccessService::class)->mayWork($world['bystander'], $case));
    }

    /** Another customer saving the same notice gets a case of its own; the first is untouched. */
    public function test_another_customer_never_reaches_the_case(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');

        $this->save($other['owner'], $world['notice']->external_id, 'Fra annen kunde')->assertRedirect()->assertSessionHas('success');

        $this->assertSame('Driftsavtale', $world['notice']->fresh()->title);
        $this->assertFalse(SavedNoticeUserAccess::query()->where('saved_notice_id', $world['notice']->id)->where('user_id', $other['owner']->id)->exists());
        $this->assertTrue(SavedNotice::query()->where('customer_id', $other['customer']->id)->where('external_id', $world['notice']->external_id)->exists());
    }

    /** Joining a case still works the legitimate way: someone who manages its access grants it. */
    public function test_access_is_still_granted_through_the_case_access_controls(): void
    {
        $world = $this->world();

        $this->actingAs($world['owner'])
            ->post(route('app.notices.saved.case-access.store', ['savedNotice' => $world['notice']->id]), [
                'user_id' => $world['bystander']->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ])
            ->assertRedirect();

        $this->assertTrue(app(SavedNoticeAccessService::class)->mayWork($world['bystander'], $world['notice']));
    }

    private function save(User $user, string $externalId, string $title): TestResponse
    {
        return $this->actingAs($user)
            ->from('/app/notices')
            ->post('/app/notices/save', [
                'notice_id' => $externalId,
                'title' => $title,
                'buyer_name' => 'Kommunen',
                'status' => 'ACTIVE',
            ]);
    }

    /**
     * One customer with Anbud, one case («Se alle saker» held by System Owner only, so access comes
     * from being part of the case): owner, contributor on the case, caseViewer (per-case reader),
     * bidViewer (bid_role viewer who saved the case), bystander (contributor with no part in it).
     *
     * @return array<string, mixed>
     */
    private function world(string $name = 'Anbud AS'): array
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
        $caseViewer = $person('Leser', User::BID_ROLE_CONTRIBUTOR);
        $bidViewer = $person('Viewer', User::BID_ROLE_VIEWER);
        $bystander = $person('Kollega', User::BID_ROLE_CONTRIBUTOR);

        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $bidViewer->id,
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'A44-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'status' => 'ACTIVE',
        ]);

        foreach ([[$contributor, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR], [$caseViewer, SavedNoticeUserAccess::ACCESS_ROLE_VIEWER]] as [$user, $role]) {
            SavedNoticeUserAccess::query()->create([
                'saved_notice_id' => $notice->id,
                'user_id' => $user->id,
                'granted_by_user_id' => $owner->id,
                'access_role' => $role,
            ]);
        }

        return compact('customer', 'notice', 'owner', 'contributor', 'caseViewer', 'bidViewer', 'bystander');
    }
}
