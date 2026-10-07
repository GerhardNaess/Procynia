<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\Risk;
use App\Models\RiskActivity;
use App\Models\RiskProcess;
use App\Models\User;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risiko → hører hjemme i → Kvalitet-prosess / -aktivitet.
 *
 * What these tests defend:
 *
 *  - Only the relation is stored, in two small tables; names are read live from Kvalitet.
 *  - A risk can be linked to a whole process, to activities in it, and to several of each.
 *  - Linking takes risk.edit in the risk's area AND Kvalitet read access; without Kvalitet read the
 *    risk page says nothing about context — not even that there is some.
 *  - Only a process of the risk's own customer, and only a step in that process's flow, can be linked.
 *  - Removing a step from the flow, the flow, or the process removes the link and leaves the risk.
 *  - Nothing about the link reaches Kvalitet.
 */
class RiskQualityContextLinkTest extends TestCase
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
        Bus::fake();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_risk_is_linked_to_a_process_and_to_activities_and_read_live(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling');
        $payroll = $this->process($customer, $owner, 'Lønnskjøring', ['Registrer timer', 'Godkjenn lønn']);
        $hiring = $this->process($customer, $owner, 'Ansettelse', ['Sjekk referanser']);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $props = $this->showProps($user, $risk);
        $this->assertSame([], $props['quality_context']);
        $this->assertTrue($props['permissions']['can_link_context']);
        $this->assertSame(['Ansettelse', 'Lønnskjøring'], array_column($props['quality_context_options'], 'title'));
        $payrollOption = collect($props['quality_context_options'])->firstWhere('id', $payroll->id);
        // Steps only — start and end are markers, not activities.
        $this->assertSame(['registrer-timer', 'godkjenn-lonn'], array_column($payrollOption['activities'], 'key'));
        $this->assertSame('Lønnsansvarlig', $payrollOption['activities'][0]['role']);

        // A whole process, without an activity.
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $payroll->id])->assertRedirect()->assertSessionHas('success');
        // An activity in it, and one in another process: several links on the same risk.
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $payroll->id, 'activity_key' => 'godkjenn-lonn'])->assertRedirect();
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $hiring->id, 'activity_key' => 'sjekk-referanser'])->assertRedirect();
        // Linking twice is the same link.
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $payroll->id])->assertRedirect();
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $payroll->id, 'activity_key' => 'godkjenn-lonn'])->assertRedirect();

        $this->assertSame(1, RiskProcess::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(2, RiskActivity::query()->where('risk_id', $risk->id)->count());
        $this->assertDatabaseHas('risk_processes', ['risk_id' => $risk->id, 'quality_item_id' => $payroll->id, 'customer_id' => $customer->id, 'created_by' => $user->id]);
        $this->assertDatabaseHas('risk_activities', ['risk_id' => $risk->id, 'quality_item_id' => $hiring->id, 'activity_key' => 'sjekk-referanser', 'created_by' => $user->id]);

        // Only ids: nothing about the process or the step is copied.
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'risk_id', 'quality_item_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('risk_processes'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'risk_id', 'quality_item_id', 'activity_key', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('risk_activities'),
        );

        // Read live from Kvalitet.
        $payroll->update(['title' => 'Lønnskjøring v2']);
        $context = $this->showProps($user, $risk)['quality_context'];
        $this->assertSame(['Ansettelse', 'Lønnskjøring v2'], array_column($context, 'title'));
        [$hiringRow, $payrollRow] = $context;
        $this->assertFalse($hiringRow['whole_process']);
        $this->assertSame(['Sjekk referanser'], array_column($hiringRow['activities'], 'label'));
        $this->assertTrue($payrollRow['whole_process']);
        $this->assertSame(['Godkjenn lønn'], array_column($payrollRow['activities'], 'label'));
        $this->assertSame(route('app.quality.items.show', ['item' => $payroll->id]), $payrollRow['url']);
        $this->assertSame(route('app.quality.items.show', ['item' => $payroll->id]).'?tab=flow&activity=godkjenn-lonn', $payrollRow['activities'][0]['url']);

        // A link is not an assessment.
        $this->assertSame([], $this->showProps($user, $risk)['assessments']);

        // Unlinking the whole process leaves the activity link; unlinking the activity leaves the process.
        $this->actingAs($user)->delete("{$this->url($risk)}/processes/{$payroll->id}")->assertRedirect()->assertSessionHas('success');
        $context = $this->showProps($user, $risk)['quality_context'];
        $this->assertFalse(collect($context)->firstWhere('id', $payroll->id)['whole_process']);

        $activityLink = RiskActivity::query()->where('risk_id', $risk->id)->where('activity_key', 'godkjenn-lonn')->firstOrFail();
        $this->actingAs($user)->delete("{$this->url($risk)}/activities/{$activityLink->id}")->assertRedirect();
        $this->assertSame(['Ansettelse'], array_column($this->showProps($user, $risk)['quality_context'], 'title'));
        $this->assertDatabaseHas('quality_items', ['id' => $payroll->id, 'title' => 'Lønnskjøring v2']);

        // Unlinking what is not linked is a 404.
        $this->actingAs($user)->delete("{$this->url($risk)}/processes/{$payroll->id}")->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($risk)}/activities/{$activityLink->id}")->assertNotFound();
    }

    public function test_only_a_process_of_the_own_customer_and_a_step_in_its_flow_can_be_linked(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $payroll = $this->process($customer, $owner, 'Lønnskjøring', ['Registrer timer']);
        $hiring = $this->process($customer, $owner, 'Ansettelse', ['Sjekk referanser']);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $control = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => 'Avstemming',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->context();
        $foreignProcess = $this->process($foreign, $foreignOwner, 'Utenlandsk prosess', ['Utenlandsk steg']);

        foreach ([$control->id, $foreignProcess->id, 999999999, 'abc'] as $id) {
            $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $id])->assertSessionHasErrors('quality_item_id');
        }

        // The activity must be a step in the chosen process: not another process's step, not a
        // start/end marker, not another customer's step, not a made-up key.
        foreach (['sjekk-referanser', 'start', 'slutt', 'utenlandsk-steg', 'finnes-ikke'] as $key) {
            $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $payroll->id, 'activity_key' => $key])
                ->assertSessionHasErrors('activity_key');
        }
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $foreignProcess->id, 'activity_key' => 'utenlandsk-steg'])
            ->assertSessionHasErrors('quality_item_id');

        $this->assertSame(0, RiskProcess::query()->count());
        $this->assertSame(0, RiskActivity::query()->count());

        $options = $this->showProps($user, $risk)['quality_context_options'];
        $this->assertSame([$hiring->id, $payroll->id], array_column($options, 'id'));
    }

    public function test_without_quality_view_the_risk_page_reveals_no_context_and_cannot_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Nøkkelperson slutter');
        $process = $this->process($customer, $owner, 'Hemmelig prosesstittel', ['Hemmelig aktivitet']);
        $this->linkProcess($risk, $process);
        $activity = $this->linkActivity($risk, $process, 'hemmelig-aktivitet');

        $user = $this->member($customer);
        $this->grant($customer, $user, self::RISK_EDITOR, [$hr]);

        $response = $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['quality_context']);
        $this->assertSame([], $props['quality_context_options']);
        $this->assertFalse($props['permissions']['can_link_context']);
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertStringNotContainsString('Hemmelig prosesstittel', $response->getContent());
        $this->assertStringNotContainsString('Hemmelig aktivitet', $response->getContent());

        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $process->id])->assertForbidden();
        $this->actingAs($user)->delete("{$this->url($risk)}/processes/{$process->id}")->assertForbidden();
        $this->actingAs($user)->delete("{$this->url($risk)}/activities/{$activity->id}")->assertForbidden();
        $this->assertSame(1, RiskProcess::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(1, RiskActivity::query()->where('risk_id', $risk->id)->count());
    }

    public function test_risk_view_with_quality_view_sees_context_but_needs_risk_edit_to_change_it(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Sykefravær');
        $process = $this->process($customer, $owner, 'Fraværsoppfølging', ['Ring den syke']);
        $this->linkProcess($risk, $process);
        $activity = $this->linkActivity($risk, $process, 'ring-den-syke');

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $props = $this->showProps($reader, $risk);
        $this->assertSame(['Fraværsoppfølging'], array_column($props['quality_context'], 'title'));
        $this->assertSame(['Ring den syke'], array_column($props['quality_context'][0]['activities'], 'label'));
        $this->assertFalse($props['permissions']['can_link_context']);
        $this->assertSame([], $props['quality_context_options']);

        $this->actingAs($reader)->post($this->url($risk), ['quality_item_id' => $process->id, 'activity_key' => 'ring-den-syke'])->assertForbidden();
        $this->actingAs($reader)->delete("{$this->url($risk)}/processes/{$process->id}")->assertForbidden();
        $this->actingAs($reader)->delete("{$this->url($risk)}/activities/{$activity->id}")->assertForbidden();
        $this->assertSame(1, RiskProcess::query()->count());
        $this->assertSame(1, RiskActivity::query()->count());
    }

    public function test_a_hidden_or_foreign_risk_cannot_be_linked_or_unlinked(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $board = $this->area($customer, 'Styre');
        $hidden = $this->risk($customer, $board, 'Fusjon under vurdering');
        $process = $this->process($customer, $owner, 'Styrebehandling', ['Forbered sak']);
        $this->linkProcess($hidden, $process);
        $activity = $this->linkActivity($hidden, $process, 'forbered-sak');

        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $this->actingAs($user)->get("/app/risk/risks/{$hidden->id}")->assertNotFound();
        $this->actingAs($user)->post($this->url($hidden), ['quality_item_id' => $process->id])->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($hidden)}/processes/{$process->id}")->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($hidden)}/activities/{$activity->id}")->assertNotFound();

        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->context();
        $foreignRisk = $this->risk($foreign, $this->area($foreign, 'HR'), 'Utenlandsk risiko');
        $foreignProcess = $this->process($foreign, $foreignOwner, 'Utenlandsk prosess', ['Utenlandsk steg']);
        $this->linkProcess($foreignRisk, $foreignProcess);

        $this->actingAs($user)->post($this->url($foreignRisk), ['quality_item_id' => $process->id])->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($foreignRisk)}/processes/{$foreignProcess->id}")->assertNotFound();

        // An activity link of another risk cannot be removed through a visible one.
        $visible = $this->risk($customer, $hr, 'Synlig risiko');
        $this->actingAs($user)->delete("{$this->url($visible)}/activities/{$activity->id}")->assertNotFound();

        $this->assertSame(2, RiskProcess::query()->count());
        $this->assertSame(1, RiskActivity::query()->count());
    }

    public function test_removing_a_step_the_flow_or_the_process_in_kvalitet_removes_only_the_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling');
        $payroll = $this->process($customer, $owner, 'Lønnskjøring', ['Registrer timer', 'Godkjenn lønn']);
        $this->linkProcess($risk, $payroll);
        $this->linkActivity($risk, $payroll, 'registrer-timer');
        $this->linkActivity($risk, $payroll, 'godkjenn-lonn');

        // Kvalitet user who knows nothing about the risk.
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT, CustomerPermissionCatalog::QUALITY_DELETE]);

        // Remove one step through Kvalitet's own editor: that link goes, the other stays.
        $this->actingAs($editor)->put("/app/quality/items/{$payroll->id}/blueprint", $this->payload(['Godkjenn lønn']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['godkjenn-lonn'], RiskActivity::query()->where('risk_id', $risk->id)->pluck('activity_key')->all());

        // A later step that happens to get the removed key back does not inherit the risk.
        $this->actingAs($editor)->put("/app/quality/items/{$payroll->id}/blueprint", $this->payload(['Registrer timer', 'Godkjenn lønn']))
            ->assertRedirect();
        $this->assertSame(['godkjenn-lonn'], RiskActivity::query()->where('risk_id', $risk->id)->pluck('activity_key')->all());

        // Removing the flow removes every activity link; the whole-process link and the risk stay.
        $this->actingAs($editor)->delete("/app/quality/items/{$payroll->id}/blueprint")->assertRedirect();
        $this->assertSame(0, RiskActivity::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(1, RiskProcess::query()->where('risk_id', $risk->id)->count());

        // Deleting the process is not refused because of a link it cannot see.
        $hiring = $this->process($customer, $owner, 'Ansettelse', ['Sjekk referanser']);
        $this->linkActivity($risk, $hiring, 'sjekk-referanser');
        $this->actingAs($editor)->delete("/app/quality/items/{$payroll->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($editor)->delete("/app/quality/items/{$hiring->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('quality_items', ['id' => $payroll->id]);
        $this->assertDatabaseMissing('quality_items', ['id' => $hiring->id]);
        $this->assertSame(0, RiskProcess::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(0, RiskActivity::query()->where('risk_id', $risk->id)->count());
        $this->assertDatabaseHas('risks', ['id' => $risk->id, 'title' => 'Feil lønnsutbetaling']);
    }

    public function test_nothing_about_the_link_reaches_kvalitet(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Konfidensiell risikotittel');
        $process = $this->process($customer, $owner, 'Lønnskjøring', ['Godkjenn lønn']);
        $this->linkProcess($risk, $process);
        $this->linkActivity($risk, $process, 'godkjenn-lonn');

        // Even someone who can see the risk does not see it from Kvalitet.
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        foreach ([
            "/app/quality/items/{$process->id}",
            "/app/quality/items/{$process->id}?tab=flow",
            "/app/quality/items/{$process->id}?tab=flow&activity=godkjenn-lonn",
            '/app/quality?tab=overview',
            '/app/quality?tab=processes',
            '/app/quality?tab=processes&search=Konfidensiell',
        ] as $url) {
            $content = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Konfidensiell risikotittel', $content, $url);
            $this->assertStringNotContainsString('risk_processes', $content, $url);
            $this->assertStringNotContainsString('risk_activities', $content, $url);
            $this->assertStringNotContainsString("/app/risk/risks/{$risk->id}", $content, $url);
        }
    }

    /**
     * A process with a working flow: start → the given steps → end, all steps in one lane.
     *
     * @param  list<string>  $steps
     */
    private function process(Customer $customer, User $actor, string $title, array $steps): QualityItem
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);

        app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            $this->payload($steps),
            QualityProcessBlueprint::SOURCE_MANUAL,
            $actor,
        );

        return $process;
    }

    /**
     * @param  list<string>  $steps
     * @return array{lanes: list<array<string, string>>, nodes: list<array<string, string>>, edges: list<array<string, string>>}
     */
    private function payload(array $steps): array
    {
        $nodes = [['key' => 'start', 'lane' => 'lonn', 'type' => 'start', 'label' => 'Start']];

        foreach ($steps as $step) {
            $nodes[] = ['key' => Str::slug($step), 'lane' => 'lonn', 'type' => 'step', 'label' => $step];
        }

        $nodes[] = ['key' => 'slutt', 'lane' => 'lonn', 'type' => 'end', 'label' => 'Slutt'];

        $edges = [];

        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['from' => $nodes[$i - 1]['key'], 'to' => $nodes[$i]['key']];
        }

        return [
            'lanes' => [['key' => 'lonn', 'label' => 'Lønnsansvarlig']],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function linkProcess(Risk $risk, QualityItem $process): RiskProcess
    {
        return RiskProcess::query()->create(['customer_id' => $risk->customer_id, 'risk_id' => $risk->id, 'quality_item_id' => $process->id]);
    }

    private function linkActivity(Risk $risk, QualityItem $process, string $key): RiskActivity
    {
        return RiskActivity::query()->create(['customer_id' => $risk->customer_id, 'risk_id' => $risk->id, 'quality_item_id' => $process->id, 'activity_key' => $key]);
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}/context";
    }

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
