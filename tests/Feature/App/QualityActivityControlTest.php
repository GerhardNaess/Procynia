<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityActivityControl;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessRevision;
use App\Models\User;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Controls on the activities of a process.
 *
 * A control added on an activity is an ordinary `control` quality item, criterion included, placed
 * on the activity by its key. The flow payload and the revisions are never written; quality.edit
 * adds and removes, quality.view reads; removing leaves the control in the register.
 */
class QualityActivityControlTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_editor_adds_a_control_to_an_activity_without_touching_the_flow(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $process = $this->process($customer);
        $blueprint = $this->blueprintFor($customer, $process);
        $payloadBefore = $blueprint->fresh()->payload;

        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/activities/controls", [
                'activity_key' => 'vurder',
                'title' => 'Fire øyne på alvorlighetsgrad',
                'criterion' => 'En annen enn saksbehandler bekrefter alvorlighetsgraden.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $link = QualityActivityControl::query()->where('quality_item_id', $process->id)->sole();
        $this->assertSame('vurder', $link->activity_key);
        $this->assertSame((int) $customer->id, (int) $link->customer_id);

        // The control is a register item of its own, with the description as its criterion.
        $control = $link->control()->with('controlDetail')->first();
        $this->assertSame(QualityItem::TYPE_CONTROL, $control->quality_type);
        $this->assertSame('Fire øyne på alvorlighetsgrad', $control->title);
        $this->assertSame('En annen enn saksbehandler bekrefter alvorlighetsgraden.', $control->controlDetail->criterion);

        // The flow and its revisions are untouched.
        $this->assertEquals($payloadBefore, $blueprint->fresh()->payload);
        $this->assertSame(0, QualityProcessRevision::query()->where('quality_item_id', $process->id)->count());

        // The page shows it on the activity it was placed on, and only there.
        $nodes = collect($this->actingAs($editor)->get("/app/quality/items/{$process->id}?tab=flow")
            ->assertOk()->viewData('page')['props']['blueprint']['nodes'])->keyBy('key');

        $this->assertSame(['Fire øyne på alvorlighetsgrad'], array_column($nodes['vurder']['controls'], 'title'));
        $this->assertSame((int) $control->id, $nodes['vurder']['controls'][0]['control_item_id']);
        $this->assertSame([], $nodes['start']['controls']);
    }

    public function test_quality_view_reads_controls_but_cannot_add_or_remove_them(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Stikkprøve');

        $nodes = collect($this->actingAs($reader)->get("/app/quality/items/{$process->id}?tab=flow")
            ->assertOk()->viewData('page')['props']['blueprint']['nodes'])->keyBy('key');
        $this->assertSame(['Stikkprøve'], array_column($nodes['vurder']['controls'], 'title'));

        $this->actingAs($reader)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'vurder', 'title' => 'Ny'])
            ->assertForbidden();
        $this->actingAs($reader)->delete("/app/quality/activity-controls/{$link->id}")->assertForbidden();

        $this->assertSame(1, QualityActivityControl::query()->where('quality_item_id', $process->id)->count());
    }

    public function test_removing_takes_the_control_off_the_activity_and_keeps_it_in_the_register(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Stikkprøve');

        $this->actingAs($editor)->delete("/app/quality/activity-controls/{$link->id}")->assertRedirect();

        $this->assertNull($link->fresh());
        $this->assertNotNull(QualityItem::query()->find($link->control_item_id));
    }

    public function test_a_control_needs_a_name_and_a_saved_activity_on_a_process(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);

        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'vurder', 'title' => ''])
            ->assertSessionHasErrors('title');

        // A key the saved flow does not have — an activity that exists only in the editor.
        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'ukjent', 'title' => 'Kontroll'])
            ->assertNotFound();

        $policy = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_POLICY,
            'title' => 'Policy',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        $this->actingAs($editor)
            ->post("/app/quality/items/{$policy->id}/activities/controls", ['activity_key' => 'vurder', 'title' => 'Kontroll'])
            ->assertSessionHasErrors('quality_type');

        $this->assertSame(0, QualityActivityControl::query()->count());
        $this->assertSame(0, QualityItem::query()->where('customer_id', $customer->id)->where('quality_type', QualityItem::TYPE_CONTROL)->count());
    }

    public function test_controls_do_not_cross_the_tenant_boundary(): void
    {
        $customer = $this->customer();
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Stikkprøve');

        $other = $this->customer();
        $outsider = $this->member($other, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($outsider)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'vurder', 'title' => 'Inntrenger'])
            ->assertNotFound();
        $this->actingAs($outsider)->delete("/app/quality/activity-controls/{$link->id}")->assertNotFound();

        $this->assertNotNull($link->fresh());
    }

    public function test_the_controls_tab_is_a_register_of_every_control_and_where_it_is_applied(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $process = $this->process($customer);
        $blueprint = $this->blueprintFor($customer, $process);
        $placed = $this->placeControl($customer, $process, 'vurder', 'Fire øyne');
        $unplaced = $this->placeControl($customer, $process, 'vurder', 'Tatt av igjen');
        $unplaced->delete();
        $orphaned = $this->placeControl($customer, $process, 'start', 'På en aktivitet som forsvinner');

        // The activity the third control sat on is removed from the flow; the link row remains.
        $payload = $blueprint->fresh()->payload;
        $payload['nodes'] = array_values(array_filter($payload['nodes'], fn (array $node): bool => $node['key'] !== 'start'));
        $payload['edges'] = array_values(array_filter($payload['edges'], fn (array $edge): bool => $edge['from'] !== 'start'));
        QualityProcessBlueprint::query()->whereKey($blueprint->id)->update(['payload' => json_encode($payload)]);

        $other = $this->customer();
        $this->placeControl($other, $this->blueprintedProcess($other), 'vurder', 'Annen kunde');

        $props = $this->actingAs($reader)->get('/app/quality?tab=controls')->assertOk()->viewData('page')['props'];

        $titles = array_column($props['items'], 'title', 'id');
        $this->assertEqualsCanonicalizing(['Fire øyne', 'Tatt av igjen', 'På en aktivitet som forsvinner'], array_values($titles));

        $register = $props['control_register'];

        $placement = $register[$placed->control_item_id]['placements'][0];
        $this->assertSame((int) $process->id, $placement['process_id']);
        $this->assertSame('Avvikshåndtering', $placement['process_title']);
        $this->assertSame('Vurder avviket', $placement['activity_label']);
        $this->assertSame('Kvalitetsleder', $placement['activity_role']);
        $this->assertTrue($placement['activity_exists']);
        $this->assertSame("/app/quality/items/{$process->id}?tab=flow&activity=vurder", $placement['url']);

        // Taken off every activity: still in the register, with nowhere to point.
        $this->assertSame([], $register[$unplaced->control_item_id]['placements']);

        // Its activity left the flow: still listed, marked as gone rather than hidden.
        $gone = $register[$orphaned->control_item_id]['placements'][0];
        $this->assertFalse($gone['activity_exists']);
        $this->assertNull($gone['activity_label']);
    }

    public function test_a_control_page_lists_the_activities_it_is_used_in_and_the_flow_opens_on_one(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Fire øyne');

        $props = $this->actingAs($reader)->get("/app/quality/items/{$link->control_item_id}")
            ->assertOk()->viewData('page')['props'];
        $this->assertSame(['Vurder avviket'], array_column($props['control_placements'], 'activity_label'));

        $url = $props['control_placements'][0]['url'];
        $this->assertSame('vurder', $this->actingAs($reader)->get($url)->assertOk()->viewData('page')['props']['focus_activity_key']);

        // Only the flow tab opens an activity.
        $this->assertNull($this->actingAs($reader)->get("/app/quality/items/{$process->id}?activity=vurder")
            ->viewData('page')['props']['focus_activity_key']);
    }

    // ---------------------------------------------------------------------
    // Fixtures

    private function blueprintedProcess(Customer $customer): QualityItem
    {
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);

        return $process;
    }
    // ---------------------------------------------------------------------

    private function placeControl(Customer $customer, QualityItem $process, string $activityKey, string $title): QualityActivityControl
    {
        $owner = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => $activityKey, 'title' => $title])
            ->assertSessionHasNoErrors();

        return QualityActivityControl::query()
            ->where('quality_item_id', $process->id)
            ->where('activity_key', $activityKey)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function member(Customer $customer, array $permissionKeys): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kontroll-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function process(Customer $customer): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Avvikshåndtering',
            'status' => QualityItem::STATUS_DRAFT,
        ]);
    }

    private function blueprintFor(Customer $customer, QualityItem $item): QualityProcessBlueprint
    {
        return app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $item,
            [
                'lanes' => [['key' => 'rolle-1', 'label' => 'Kvalitetsleder']],
                'nodes' => [
                    ['key' => 'start', 'lane' => 'rolle-1', 'type' => 'start', 'label' => 'Start'],
                    ['key' => 'vurder', 'lane' => 'rolle-1', 'type' => 'step', 'label' => 'Vurder avviket'],
                    ['key' => 'slutt', 'lane' => 'rolle-1', 'type' => 'end', 'label' => 'Slutt'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'vurder'],
                    ['from' => 'vurder', 'to' => 'slutt'],
                ],
            ],
            QualityProcessBlueprint::SOURCE_MANUAL,
        );
    }

    private function customer(): Customer
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
            'name' => 'Kvalitet Kontroll AS',
            'slug' => 'kvalitet-kontroll-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'quality'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        return $customer;
    }
}
