<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Tiltak on a risk.
 *
 * What these tests defend:
 *
 *  - An action has a title, a responsible person and a deadline; it is open or completed, and
 *    completed_at follows the status. «Forfalt» is open past the deadline day, never stored.
 *  - The responsible person is an active user of the same customer who can see the risk.
 *  - Writing takes risk.edit in the risk's area; risk.view alone reads.
 *  - A hidden or foreign risk is a 404 for every action route, and an action is reached only
 *    through its own risk.
 *  - Deleting the risk takes its actions along; nothing about actions reaches Kvalitet.
 */
class RiskTreatmentActionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const RISK_EDITOR = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::RISK_EDIT,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_action_is_created_edited_completed_and_reopened(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);
        $colleague = $this->member($customer);
        $this->grant($customer, $colleague, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        $props = $this->showProps($editor, $risk);
        $this->assertSame([], $props['treatment_actions']);
        $this->assertTrue($props['permissions']['can_manage_actions']);
        $this->assertEqualsCanonicalizing([$editor->id, $colleague->id], array_column($props['treatment_owner_options'], 'id'));

        $this->actingAs($editor)->post($this->url($risk), [
            'title' => 'Innfør fire-øyne-kontroll på lønnskjøring',
            'owner_user_id' => $colleague->id,
            'due_at' => '2026-11-01',
        ])->assertRedirect()->assertSessionHas('success');

        $action = RiskTreatmentAction::query()->where('risk_id', $risk->id)->sole();
        $this->assertSame(RiskTreatmentAction::STATUS_OPEN, $action->status);
        $this->assertNull($action->completed_at);
        $this->assertSame($customer->id, (int) $action->customer_id);
        $this->assertSame($editor->id, (int) $action->created_by);

        $this->actingAs($editor)->patch("{$this->url($risk)}/{$action->id}", [
            'title' => 'Innfør fire-øyne-kontroll',
            'owner_user_id' => $editor->id,
            'due_at' => '2026-11-15',
            'outcome_note' => '',
        ])->assertRedirect()->assertSessionHas('success');
        $action->refresh();
        $this->assertSame('Innfør fire-øyne-kontroll', $action->title);
        $this->assertSame($editor->id, (int) $action->owner_user_id);
        $this->assertSame('2026-11-15', $action->due_at->toDateString());
        $this->assertNull($action->outcome_note);

        Carbon::setTestNow('2026-10-20 14:30:00');
        $this->actingAs($editor)->post("{$this->url($risk)}/{$action->id}/complete", ['outcome_note' => 'Innført fra oktober.'])
            ->assertRedirect()->assertSessionHas('success');
        $action->refresh();
        $this->assertSame(RiskTreatmentAction::STATUS_COMPLETED, $action->status);
        $this->assertSame('2026-10-20 14:30:00', $action->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Innført fra oktober.', $action->outcome_note);

        $row = $this->showProps($editor, $risk)['treatment_actions'][0];
        $this->assertSame('completed', $row['status']);
        $this->assertSame('Innført fra oktober.', $row['outcome_note']);
        $this->assertSame($editor->name, $row['owner_name']);
        $this->assertSame('2026-11-15', $row['due_at']);
        $this->assertNotNull($row['completed_at']);

        $this->actingAs($editor)->post("{$this->url($risk)}/{$action->id}/reopen")->assertRedirect()->assertSessionHas('success');
        $action->refresh();
        $this->assertSame(RiskTreatmentAction::STATUS_OPEN, $action->status);
        $this->assertNull($action->completed_at);
        // The note stays as a record of what was tried.
        $this->assertSame('Innført fra oktober.', $action->outcome_note);
    }

    public function test_title_owner_and_deadline_are_required(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);

        $this->actingAs($editor)->post($this->url($risk), ['title' => ' ', 'due_at' => '01.11.2026'])
            ->assertSessionHasErrors(['title', 'owner_user_id', 'due_at']);
        $this->assertSame(0, RiskTreatmentAction::query()->count());
    }

    public function test_a_missing_field_is_refused_in_the_users_language(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);

        $this->actingAs($editor)->post($this->url($risk), ['title' => 'Innfør kontroll', 'due_at' => '2026-11-01'])
            ->assertSessionHasErrors(['owner_user_id' => 'Ansvarlig må fylles ut.']);
        $this->actingAs($editor)->post($this->url($risk), ['owner_user_id' => $editor->id, 'due_at' => '2026-11-01'])
            ->assertSessionHasErrors(['title' => 'Hva skal gjøres må fylles ut.']);
    }

    public function test_editing_an_open_action_without_a_result_keeps_the_earlier_one(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);
        $action = $this->action($risk, $editor, 'Innfør kontroll', '2026-11-01', completed: true);
        $action->update(['outcome_note' => 'Første forsøk feilet.']);

        $this->actingAs($editor)->post("{$this->url($risk)}/{$action->id}/reopen")->assertRedirect();
        // The open action's form has no result field and sends none.
        $this->actingAs($editor)->patch("{$this->url($risk)}/{$action->id}", [
            'title' => 'Innfør kontroll, nytt forsøk',
            'owner_user_id' => $editor->id,
            'due_at' => '2026-12-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $action->refresh();
        $this->assertSame('Innfør kontroll, nytt forsøk', $action->title);
        $this->assertSame('Første forsøk feilet.', $action->outcome_note);
    }

    public function test_overdue_is_open_past_the_deadline_day_only(): void
    {
        Carbon::setTestNow('2026-10-04 23:30:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);

        $yesterday = $this->action($risk, $editor, 'Frist i går', '2026-10-03');
        $today = $this->action($risk, $editor, 'Frist i dag', '2026-10-04');
        $tomorrow = $this->action($risk, $editor, 'Frist i morgen', '2026-10-05');
        $doneLate = $this->action($risk, $editor, 'Fullført etter frist', '2026-09-01', completed: true);

        $rows = collect($this->showProps($editor, $risk)['treatment_actions'])->keyBy('id');
        $this->assertTrue($rows[$yesterday->id]['is_overdue']);
        $this->assertFalse($rows[$today->id]['is_overdue']);
        $this->assertFalse($rows[$tomorrow->id]['is_overdue']);
        $this->assertFalse($rows[$doneLate->id]['is_overdue']);

        // Open first by deadline, completed beneath.
        $this->assertSame(
            [$yesterday->id, $today->id, $tomorrow->id, $doneLate->id],
            array_column($this->showProps($editor, $risk)['treatment_actions'], 'id'),
        );

        // Reopening a late action makes it overdue again.
        $this->actingAs($editor)->post("{$this->url($risk)}/{$doneLate->id}/reopen")->assertRedirect();
        $rows = collect($this->showProps($editor, $risk)['treatment_actions'])->keyBy('id');
        $this->assertTrue($rows[$doneLate->id]['is_overdue']);
    }

    public function test_an_owner_of_another_tenant_inactive_or_unknown_is_refused(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);

        ['customer' => $foreign] = $this->context();
        $foreignArea = $this->area($foreign, 'HR');
        $foreignUser = $this->member($foreign);
        $this->grant($foreign, $foreignUser, self::RISK_EDITOR, [$foreignArea]);

        $inactive = $this->member($customer);
        $this->grant($customer, $inactive, self::RISK_EDITOR, [$hr]);
        $inactive->update(['is_active' => false]);

        foreach ([$foreignUser->id, $inactive->id, 999999999, 'abc'] as $ownerId) {
            $this->actingAs($editor)->post($this->url($risk), [
                'title' => 'Tiltak', 'owner_user_id' => $ownerId, 'due_at' => '2026-11-01',
            ])->assertSessionHasErrors('owner_user_id');
        }

        $options = array_column($this->showProps($editor, $risk)['treatment_owner_options'], 'id');
        $this->assertNotContains($foreignUser->id, $options);
        $this->assertNotContains($inactive->id, $options);

        // Editing to an invalid owner is refused the same way and leaves the action untouched.
        $action = $this->action($risk, $editor, 'Tiltak', '2026-11-01');
        $this->actingAs($editor)->patch("{$this->url($risk)}/{$action->id}", [
            'title' => 'Tiltak', 'owner_user_id' => $foreignUser->id, 'due_at' => '2026-11-01',
        ])->assertSessionHasErrors('owner_user_id');
        $this->assertSame($editor->id, (int) $action->fresh()->owner_user_id);
        $this->assertSame(1, RiskTreatmentAction::query()->count());
    }

    public function test_an_owner_who_cannot_see_the_risk_is_refused(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $board = $this->area($customer, 'Styre');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, self::RISK_EDITOR, [$hr]);

        // Sees risks, but in another area; and one who reaches HR without risk.view.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW], [$board]);
        $noView = $this->member($customer);
        $this->grant($customer, $noView, [CustomerPermissionCatalog::RISK_CREATE], [$hr]);

        foreach ([$elsewhere->id, $noView->id] as $ownerId) {
            $this->actingAs($editor)->post($this->url($risk), [
                'title' => 'Tiltak', 'owner_user_id' => $ownerId, 'due_at' => '2026-11-01',
            ])->assertSessionHasErrors('owner_user_id');
        }

        $this->assertSame(0, RiskTreatmentAction::query()->count());
        $options = array_column($this->showProps($editor, $risk)['treatment_owner_options'], 'id');
        $this->assertSame([$editor->id], $options);
    }

    public function test_risk_view_reads_actions_but_needs_risk_edit_in_the_same_role_to_change_them(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr, 'Sykefravær');

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        // risk.edit, but from a role that reaches another area: still not an editor here.
        $this->grant($customer, $reader, self::RISK_EDITOR, [$finance]);
        $action = $this->action($risk, $reader, 'Oppfølgingsrutine', '2026-11-01');

        $props = $this->showProps($reader, $risk);
        $this->assertSame(['Oppfølgingsrutine'], array_column($props['treatment_actions'], 'title'));
        $this->assertFalse($props['permissions']['can_manage_actions']);
        $this->assertSame([], $props['treatment_owner_options']);

        $payload = ['title' => 'Endret', 'owner_user_id' => $reader->id, 'due_at' => '2026-12-01'];
        $this->actingAs($reader)->post($this->url($risk), $payload)->assertForbidden();
        $this->actingAs($reader)->patch("{$this->url($risk)}/{$action->id}", $payload)->assertForbidden();
        $this->actingAs($reader)->post("{$this->url($risk)}/{$action->id}/complete")->assertForbidden();
        $this->actingAs($reader)->post("{$this->url($risk)}/{$action->id}/reopen")->assertForbidden();

        $action->refresh();
        $this->assertSame('Oppfølgingsrutine', $action->title);
        $this->assertSame(RiskTreatmentAction::STATUS_OPEN, $action->status);
        $this->assertSame(1, RiskTreatmentAction::query()->count());
    }

    public function test_a_hidden_or_foreign_risk_and_its_actions_do_not_leak(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $board = $this->area($customer, 'Styre');
        $visible = $this->risk($customer, $hr, 'Synlig risiko');
        $hidden = $this->risk($customer, $board, 'Fusjon under vurdering');

        $user = $this->member($customer);
        $this->grant($customer, $user, self::RISK_EDITOR, [$hr]);
        $boardMember = $this->member($customer);
        $this->grant($customer, $boardMember, self::RISK_EDITOR, [$board]);
        $hiddenAction = $this->action($hidden, $boardMember, 'Hemmelig tiltakstittel', '2026-11-01');

        ['customer' => $foreign] = $this->context();
        $foreignArea = $this->area($foreign, 'HR');
        $foreignRisk = $this->risk($foreign, $foreignArea, 'Utenlandsk risiko');
        $foreignUser = $this->member($foreign);
        $foreignAction = $this->action($foreignRisk, $foreignUser, 'Utenlandsk tiltak', '2026-11-01');

        $payload = ['title' => 'Overtatt', 'owner_user_id' => $user->id, 'due_at' => '2026-12-01'];

        foreach ([[$hidden, $hiddenAction], [$foreignRisk, $foreignAction]] as [$risk, $action]) {
            $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertNotFound();
            $this->actingAs($user)->post($this->url($risk), $payload)->assertNotFound();
            $this->actingAs($user)->patch("{$this->url($risk)}/{$action->id}", $payload)->assertNotFound();
            $this->actingAs($user)->post("{$this->url($risk)}/{$action->id}/complete")->assertNotFound();
            $this->actingAs($user)->post("{$this->url($risk)}/{$action->id}/reopen")->assertNotFound();

            // An action reached through a risk the user *can* edit, but belonging to another risk.
            $this->actingAs($user)->patch("{$this->url($visible)}/{$action->id}", $payload)->assertNotFound();
            $this->actingAs($user)->post("{$this->url($visible)}/{$action->id}/complete")->assertNotFound();
            $this->actingAs($user)->post("{$this->url($visible)}/{$action->id}/reopen")->assertNotFound();
        }

        $this->assertSame('Hemmelig tiltakstittel', $hiddenAction->fresh()->title);
        $this->assertSame(RiskTreatmentAction::STATUS_OPEN, $hiddenAction->fresh()->status);
        $this->assertSame('Utenlandsk tiltak', $foreignAction->fresh()->title);
        $this->assertSame(0, RiskTreatmentAction::query()->where('risk_id', $visible->id)->count());

        // Not on the visible risk's page, not in the register, not in its search.
        foreach (["/app/risk/risks/{$visible->id}", '/app/risk', '/app/risk?search=Hemmelig'] as $url) {
            $content = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Hemmelig tiltakstittel', $content, $url);
            $this->assertStringNotContainsString('Utenlandsk tiltak', $content, $url);
        }

        // Tiltak are not in Kvalitet, even for someone who sees the risk and Kvalitet.
        $this->grant($customer, $boardMember, [CustomerPermissionCatalog::QUALITY_VIEW]);
        foreach (['/app/quality?tab=overview', '/app/quality?tab=controls'] as $url) {
            $content = $this->actingAs($boardMember)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Hemmelig tiltakstittel', $content, $url);
        }
    }

    public function test_deleting_the_risk_takes_its_actions_along(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $other = $this->risk($customer, $hr, 'Annen risiko');
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::RISK_DELETE], [$hr]);
        $this->action($risk, $user, 'Tiltak 1', '2026-11-01');
        $this->action($risk, $user, 'Tiltak 2', '2026-11-01', completed: true);
        $kept = $this->action($other, $user, 'Beholdes', '2026-11-01');

        $this->actingAs($user)->delete("/app/risk/risks/{$risk->id}")->assertRedirect();

        $this->assertDatabaseMissing('risk_treatment_actions', ['risk_id' => $risk->id]);
        $this->assertDatabaseHas('risk_treatment_actions', ['id' => $kept->id]);
    }

    private function action(Risk $risk, User $owner, string $title, string $dueAt, bool $completed = false): RiskTreatmentAction
    {
        return RiskTreatmentAction::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'title' => $title,
            'owner_user_id' => $owner->id,
            'due_at' => $dueAt,
            'status' => $completed ? RiskTreatmentAction::STATUS_COMPLETED : RiskTreatmentAction::STATUS_OPEN,
            'completed_at' => $completed ? now() : null,
        ]);
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}/actions";
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys, $areas);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function role(Customer $customer, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function risk(Customer $customer, BusinessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function showProps(User $user, Risk $risk): array
    {
        return $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertOk()->viewData('page')['props'];
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risiko-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(bool $withRisk = true): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Risiko Tilgang AS',
            'slug' => 'risiko-tilgang-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($withRisk) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => 'governance'],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
