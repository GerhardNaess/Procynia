<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessRevision;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageDeletionService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\QualityFlowVersion;
use Tests\TestCase;

/**
 * An approved process version is never lost, phase 0 step 12 (docs/external-api-phase-0-plan.md §2f).
 *
 * The approved versions of a process flow are the record of what the virksomhet decided. They used
 * to cascade away with the process, past the model's own guards. Now a process that has been
 * approved is retired («Utgått»), not deleted — in the menu, the controller, the service and the
 * database — and the versions themselves can be neither changed nor removed. Only the customer's
 * own deletion takes them along.
 */
class QualityProcessVersionIntegrityTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Queue::fake();
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

    /** The service refuses before anything is touched — the Wiki knowledge included. */
    public function test_an_approved_process_is_not_deleted_by_the_service(): void
    {
        ['customer' => $customer, 'owner' => $owner, 'approved' => $approved] = $this->world();
        $this->mock(EnterpriseWikiPageDeletionService::class, fn ($mock) => $mock->shouldNotReceive('deletePages'));

        try {
            app(QualityItemService::class)->deleteItem((int) $customer->id, $approved, $owner, true);
            $this->fail('an approved process is retired, not deleted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('item', $e->errors());
        }

        $this->assertNotNull(QualityItem::query()->find($approved->id));
        $this->assertSame(2, QualityProcessRevision::query()->where('quality_item_id', $approved->id)->count());
    }

    /** What the menu offers instead: «Utgått», and the versions stay. */
    public function test_an_approved_process_is_retired_and_keeps_its_versions(): void
    {
        ['customer' => $customer, 'owner' => $owner, 'approved' => $approved] = $this->world();

        app(QualityItemService::class)->updateItem((int) $customer->id, $approved, ['status' => QualityItem::STATUS_RETIRED], $owner);

        $this->assertSame(QualityItem::STATUS_RETIRED, $approved->fresh()->status);
        $this->assertSame([1, 2], QualityProcessRevision::query()->where('quality_item_id', $approved->id)->orderBy('revision_number')->pluck('revision_number')->all());
    }

    /** The page knows before it asks, and the controller answers the dialog if asked anyway. */
    public function test_the_page_offers_no_delete_and_the_controller_refuses_one(): void
    {
        ['owner' => $owner, 'approved' => $approved, 'draft' => $draft] = $this->world();

        $rows = collect($this->actingAs($owner)->get('/app/quality?tab=processes')->viewData('page')['props']['items'])->keyBy('id');
        $this->assertTrue($rows[$approved->id]['in_use']);
        $this->assertFalse($rows[$draft->id]['in_use']);

        $this->actingAs($owner)->delete("/app/quality/items/{$approved->id}?tab=processes")
            ->assertRedirect('/app/quality?tab=processes')
            ->assertSessionHas('error', __('procynia.quality.errors.in_use_not_deletable'));
        $this->assertNotNull(QualityItem::query()->find($approved->id));

        // A process that was never approved is deleted as before.
        $this->actingAs($owner)->delete("/app/quality/items/{$draft->id}")->assertSessionHas('success');
        $this->assertNull(QualityItem::query()->find($draft->id));
    }

    /** «Forkast arbeidsversjon» discards the working version; the approved ones are not it. */
    public function test_discarding_the_working_version_keeps_the_approved_ones(): void
    {
        ['customer' => $customer, 'owner' => $owner, 'approved' => $approved] = $this->world();

        app(QualityProcessBlueprintService::class)->delete((int) $customer->id, $approved, $owner);

        $this->assertSame(2, QualityProcessRevision::query()->where('quality_item_id', $approved->id)->count());
    }

    /** The database holds the rule for SQL and cascades, where the models' guards never run. */
    public function test_the_database_keeps_every_approved_version(): void
    {
        ['approved' => $approved] = $this->world();
        $revision = QualityProcessRevision::query()->where('quality_item_id', $approved->id)->orderBy('id')->firstOrFail();

        $this->assertRefused(fn () => DB::table('quality_items')->where('id', $approved->id)->delete(), 'deleting an approved process');
        $this->assertRefused(fn () => DB::table('quality_process_revisions')->where('id', $revision->id)->delete(), 'deleting an approved version');
        $this->assertRefused(fn () => DB::table('quality_process_revisions')->where('id', $revision->id)->update(['payload' => json_encode(['lanes' => []])]), 'rewriting an approved flow');
        $this->assertRefused(fn () => DB::table('quality_process_revisions')->where('id', $revision->id)->update(['approved_by_name' => 'Noen andre']), 'renaming the approver');
        $this->assertRefused(fn () => DB::table('quality_process_revisions')->where('id', $revision->id)->update(['approved_by_user_id' => null, 'approved_at' => now()->subYear()]), 'moving the approval in time');

        $this->assertSame(2, QualityProcessRevision::query()->where('quality_item_id', $approved->id)->count());
    }

    /** A deleted user is let go of by the foreign key; who approved stays readable by name. */
    public function test_deleting_the_approver_keeps_the_version_and_their_name(): void
    {
        ['customer' => $customer, 'approved' => $approved] = $this->world();
        $approver = $this->owner($customer, 'Godkjenner Som Slutter');
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $approved, $this->flow('Tredje utgave'), QualityProcessBlueprint::SOURCE_MANUAL, $approver, basedOn: QualityFlowVersion::of($approved));
        app(QualityProcessBlueprintService::class)->approve((int) $customer->id, $approved, $approver);

        DB::table('users')->where('id', $approver->id)->delete();

        $revision = QualityProcessRevision::query()->where('quality_item_id', $approved->id)->where('revision_number', 3)->sole();
        $this->assertNull($revision->approved_by_user_id);
        $this->assertSame('Godkjenner Som Slutter', $revision->approved_by_name);
    }

    /** The one deletion that takes the versions along: the customer's own. */
    public function test_deleting_the_customer_takes_the_approved_versions_along(): void
    {
        ['customer' => $customer, 'approved' => $approved] = $this->world();

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertNull(QualityItem::query()->find($approved->id));
        $this->assertSame(0, QualityProcessRevision::query()->where('quality_item_id', $approved->id)->count());
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("the database must refuse {$what}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, mixed> a process approved twice, and one that never was */
    private function world(): array
    {
        $customer = $this->customer();
        $owner = $this->owner($customer, 'System Owner');
        $blueprints = app(QualityProcessBlueprintService::class);

        $approved = $this->process($customer, 'Innkjøp');
        $blueprints->store((int) $customer->id, $approved, $this->flow('Første utgave'), QualityProcessBlueprint::SOURCE_MANUAL, $owner, basedOn: QualityFlowVersion::of($approved));
        $blueprints->approve((int) $customer->id, $approved, $owner);
        $blueprints->store((int) $customer->id, $approved, $this->flow('Andre utgave'), QualityProcessBlueprint::SOURCE_MANUAL, $owner, basedOn: QualityFlowVersion::of($approved));
        $blueprints->approve((int) $customer->id, $approved, $owner);

        $draft = $this->process($customer, 'Kladd');
        $blueprints->store((int) $customer->id, $draft, $this->flow('Bare en kladd'), QualityProcessBlueprint::SOURCE_MANUAL, $owner, basedOn: QualityFlowVersion::of($draft));

        return ['customer' => $customer, 'owner' => $owner, 'approved' => $approved->fresh(), 'draft' => $draft->fresh()];
    }

    /** @return array<string, mixed> */
    private function flow(string $step): array
    {
        return [
            'lanes' => [['key' => 'innkjop', 'label' => 'Innkjøper']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'innkjop', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'steg', 'lane' => 'innkjop', 'type' => 'step', 'label' => $step],
                ['key' => 'slutt', 'lane' => 'innkjop', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'steg'],
                ['from' => 'steg', 'to' => 'slutt'],
            ],
        ];
    }

    private function process(Customer $customer, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);
    }

    private function owner(Customer $customer, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Versjoner AS',
            'slug' => 'kvalitet-versjoner-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        return $customer;
    }
}
