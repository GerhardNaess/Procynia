<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskControlService;
use App\Services\Risk\RiskCreator;
use App\Services\Risk\RiskQualityContextService;
use App\Services\Risk\RiskTreatmentService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The writes on a risk that are not human decisions — editing it (closing it included), its tiltak
 * and its links to Kvalitet — check risk.edit in the risk's fagområde in their own services
 * (phase 0 step 9), so a later entry point inherits the rule instead of re-implementing it.
 *
 * They are not reserved for people: an integration holding risk.edit in the area may make them.
 */
class RiskServiceAccessTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    public function test_an_editor_in_the_area_edits_closes_and_manages_tiltak_through_the_services(): void
    {
        $case = $this->case();

        $risk = app(RiskCreator::class)->update($case['editor'], $case['risk'], $this->fields($case, ['status' => Risk::STATUS_CLOSED]));
        $this->assertSame(Risk::STATUS_CLOSED, $risk->fresh()->status);

        $treatments = app(RiskTreatmentService::class);
        $action = $treatments->create($case['editor'], $case['risk'], $this->tiltak($case));
        $treatments->update($case['editor'], $action, $case['risk'], ['title' => 'Nødstrøm testet'] + $this->tiltak($case));
        $treatments->complete($case['editor'], $action, 'Gjort.');
        $treatments->reopen($case['editor'], $action->fresh());
        $this->assertSame('Nødstrøm testet', $action->fresh()->title);
        $this->assertSame(RiskTreatmentAction::STATUS_OPEN, $action->fresh()->status);
    }

    /** Not a human decision: an integration with risk.edit in the area edits a risk and its tiltak. */
    public function test_an_integration_with_risk_edit_only_reads(): void
    {
        // An integration holds read keys only (D13): risk.edit on its role buys nothing.
        $case = $this->case();
        $integration = $this->person($case['customer'], [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$case['area']], User::ROLE_INTEGRATION);
        $title = $case['risk']->title;

        foreach ([
            fn () => app(RiskCreator::class)->update($integration, $case['risk'], $this->fields($case, ['title' => 'Strømbrudd i datasenter'])),
            fn () => app(RiskTreatmentService::class)->create($integration, $case['risk'], $this->tiltak($case)),
        ] as $write) {
            try {
                $write();
                $this->fail('An integration wrote to a risk.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($title, $case['risk']->fresh()->title);
        $this->assertSame(1, RiskTreatmentAction::query()->where('risk_id', $case['risk']->id)->count(), 'only the fixture\'s tiltak');
        $this->assertTrue(app(RiskAccessService::class)->can($integration, CustomerPermissionCatalog::RISK_VIEW, $case['risk']));
    }

    public function test_every_write_is_refused_in_the_service_without_risk_edit_in_the_area(): void
    {
        $case = $this->case();
        $elsewhere = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);
        $other = $this->customer('Annen Kunde AS');
        $otherArea = BusinessArea::query()->create(['customer_id' => $other->id, 'name' => 'Drift']);
        $editorKeys = [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT];

        $people = [
            'reads only' => $this->person($case['customer'], [CustomerPermissionCatalog::RISK_VIEW], [$case['area']]),
            'edits in another fagområde' => $this->person($case['customer'], $editorKeys, [$elsewhere]),
            'edits, but cannot see the risk' => $this->person($case['customer'], [CustomerPermissionCatalog::RISK_EDIT], [$case['area']]),
            'another customer (explicit area)' => $this->person($other, $editorKeys, [$otherArea]),
            'another customer («Alle»)' => $this->person($other, $editorKeys, [], allAreas: true),
        ];

        foreach ($people as $name => $person) {
            $this->assertEveryWriteRefused($case, $person, $name);
        }
    }

    /** A tiltak passed together with another risk is not a way around that risk's rule. */
    public function test_a_tiltak_of_another_risk_is_refused(): void
    {
        $case = $this->case();
        $hr = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);
        $hidden = Risk::query()->create(['customer_id' => $case['customer']->id, 'business_area_id' => $hr->id, 'title' => 'Skjult', 'status' => Risk::STATUS_IDENTIFIED]);
        $hiddenAction = RiskTreatmentAction::query()->create([
            'customer_id' => $case['customer']->id, 'risk_id' => $hidden->id, 'title' => 'Skjult tiltak', 'status' => 'open', 'due_at' => now()->addMonth()->toDateString(),
        ]);

        foreach ([
            'update' => fn () => app(RiskTreatmentService::class)->update($case['editor'], $hiddenAction, $case['risk'], $this->tiltak($case)),
            'complete' => fn () => app(RiskTreatmentService::class)->complete($case['editor'], $hiddenAction, null),
            'reopen' => fn () => app(RiskTreatmentService::class)->reopen($case['editor'], $hiddenAction),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$write} must be refused");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Skjult tiltak', $hiddenAction->fresh()->title);
        $this->assertSame('open', $hiddenAction->fresh()->status);

        // Even between two risks the person may edit, a tiltak is changed only under its own risk.
        $sibling = Risk::query()->create(['customer_id' => $case['customer']->id, 'business_area_id' => $case['area']->id, 'title' => 'Søsken', 'status' => Risk::STATUS_IDENTIFIED]);

        try {
            app(RiskTreatmentService::class)->update($case['editor'], $case['action'], $sibling, ['title' => 'Flyttet'] + $this->tiltak($case));
            $this->fail('a tiltak under the wrong risk must be refused');
        } catch (AuthorizationException) {
            $this->assertSame('Reservestrøm', $case['action']->fresh()->title);
        }
    }

    /** Links to Kvalitet also need Kvalitet read access, in the service as in the controller. */
    public function test_links_to_kvalitet_need_kvalitet_read_access_in_the_service(): void
    {
        $case = $this->case();

        foreach ([
            'link control' => fn () => app(RiskControlService::class)->link($case['editor'], $case['risk'], 1),
            'unlink control' => fn () => app(RiskControlService::class)->unlink($case['editor'], $case['risk'], 1),
            'link process' => fn () => app(RiskQualityContextService::class)->link($case['editor'], $case['risk'], 1, null),
            'unlink process' => fn () => app(RiskQualityContextService::class)->unlinkProcess($case['editor'], $case['risk'], 1),
            'unlink activity' => fn () => app(RiskQualityContextService::class)->unlinkActivity($case['editor'], $case['risk'], 1),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$write} must be refused without Kvalitet read access");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('Kvalitet', $e->getMessage(), $write);
            }
        }
    }

    /** @param array<string, mixed> $case */
    private function assertEveryWriteRefused(array $case, User $person, string $who): void
    {
        $treatments = app(RiskTreatmentService::class);

        foreach ([
            'edit' => fn () => app(RiskCreator::class)->update($person, $case['risk'], $this->fields($case, ['title' => 'Overtatt'])),
            'close' => fn () => app(RiskCreator::class)->update($person, $case['risk'], $this->fields($case, ['status' => Risk::STATUS_CLOSED])),
            'tiltak create' => fn () => $treatments->create($person, $case['risk'], $this->tiltak($case)),
            'tiltak update' => fn () => $treatments->update($person, $case['action'], $case['risk'], ['title' => 'Overtatt'] + $this->tiltak($case)),
            'tiltak complete' => fn () => $treatments->complete($person, $case['action'], null),
            'tiltak reopen' => fn () => $treatments->reopen($person, $case['action']),
            'link control' => fn () => app(RiskControlService::class)->link($person, $case['risk'], 1),
            'unlink control' => fn () => app(RiskControlService::class)->unlink($person, $case['risk'], 1),
            'link process' => fn () => app(RiskQualityContextService::class)->link($person, $case['risk'], 1, null),
            'unlink process' => fn () => app(RiskQualityContextService::class)->unlinkProcess($person, $case['risk'], 1),
            'unlink activity' => fn () => app(RiskQualityContextService::class)->unlinkActivity($person, $case['risk'], 1),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$write} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(AuthorizationException::class, $e, "{$who}: {$write} — ".$e->getMessage());
                $this->assertStringContainsString('may not change this risk', $e->getMessage(), "{$who}: {$write}");
            }
        }

        $risk = $case['risk']->fresh();
        $this->assertSame(['Strømbrudd', Risk::STATUS_IDENTIFIED], [$risk->title, $risk->status], $who);
        $this->assertSame(1, RiskTreatmentAction::query()->where('risk_id', $risk->id)->count(), $who);
        $this->assertSame(['Reservestrøm', 'open'], [$case['action']->fresh()->title, $case['action']->fresh()->status], $who);
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $case, array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Strømbrudd',
            'cause' => 'Uvær',
            'event' => 'Strømmen går',
            'consequence' => 'Driften stopper',
            'description' => null,
            'business_area_id' => $case['area']->id,
            'owner_user_id' => null,
            'status' => Risk::STATUS_IDENTIFIED,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{title: string, owner_user_id: int, due_at: string}
     */
    private function tiltak(array $case): array
    {
        return ['title' => 'Reservestrøm', 'owner_user_id' => (int) $case['editor']->id, 'due_at' => now()->addMonth()->toDateString()];
    }

    /** @return array<string, mixed> */
    private function case(): array
    {
        $customer = $this->customer('Risikotilgang AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);
        $risk = Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Strømbrudd',
            'cause' => 'Uvær', 'event' => 'Strømmen går', 'consequence' => 'Driften stopper', 'status' => Risk::STATUS_IDENTIFIED,
        ]);
        $editor = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$area]);
        $action = RiskTreatmentAction::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'title' => 'Reservestrøm', 'owner_user_id' => $editor->id,
            'status' => 'open', 'due_at' => now()->addMonth()->toDateString(),
        ]);

        return compact('customer', 'area', 'risk', 'editor', 'action');
    }

    private function customer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => 'risikotilgang-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        return $customer;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<BusinessArea>  $areas
     */
    private function person(Customer $customer, array $keys, array $areas, string $role = User::ROLE_USER, bool $allAreas = false): User
    {
        $customerRole = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $customerRole->syncPermissions($keys);
        $customerRole->syncBusinessAreas($allAreas, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        $person = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risikotilgang-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $person->customerRoles()->attach($customerRole->id, ['customer_id' => $customer->id]);

        return $person;
    }
}
