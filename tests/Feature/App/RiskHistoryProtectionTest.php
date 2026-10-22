<?php

namespace Tests\Feature\App;

use App\Exceptions\RiskHistoryProtected;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\RiskTestDataPurge;
use Tests\TestCase;

/**
 * Decision B3 (docs/external-api-phase-0-plan.md §4.6): a risk with an assessment, an acceptance
 * or a tiltak is never deleted — it is closed. Refused by RiskLifecycleService, over HTTP, by the
 * model and by the database, which also keeps assessments and acceptances append-only on their
 * own. The customer's own deletion still takes everything along, and a risk without history is
 * deleted as before.
 */
class RiskHistoryProtectionTest extends TestCase
{
    use UsesProjectPostgresConnection;

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
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /** Existing behaviour kept: a risk registered by mistake, with nothing on it, is deleted. */
    public function test_a_risk_without_history_is_deleted_as_before(): void
    {
        $case = $this->case();
        $viaService = $this->risk($case, 'Registrert ved en feil');

        $props = $this->actingAs($case['deleter'])->get("/app/risk/risks/{$case['risk']->id}")->assertOk()->viewData('page')['props']['permissions'];
        $this->assertTrue($props['can_delete']);
        $this->assertFalse($props['delete_blocked_by_history']);

        $this->actingAs($case['deleter'])->delete("/app/risk/risks/{$case['risk']->id}")
            ->assertRedirect('/app/risk')
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('risks', ['id' => $case['risk']->id]);

        app(RiskLifecycleService::class)->delete($case['deleter'], $viaService);
        $this->assertDatabaseMissing('risks', ['id' => $viaService->id]);
    }

    /** Each kind of history on its own is enough, a revoked acceptance included. */
    public function test_a_risk_with_any_history_is_refused_on_every_route(): void
    {
        $case = $this->case();

        foreach ([
            'an assessment' => fn (Risk $risk) => $this->assessment($risk),
            'a revoked acceptance' => function (Risk $risk) use ($case): void {
                $acceptance = $this->acceptance($risk, $this->assessment($risk));
                app(RiskAcceptanceService::class)->revoke($case['deleter'], $acceptance);
            },
            'a tiltak' => fn (Risk $risk) => RiskTreatmentAction::query()->create([
                'customer_id' => $risk->customer_id, 'risk_id' => $risk->id, 'title' => 'Nødstrøm', 'status' => 'open', 'due_at' => now()->addMonth()->toDateString(),
            ]),
        ] as $history => $add) {
            $risk = $this->risk($case, "Med {$history}");
            $add($risk);

            $this->assertFalse($risk->isDeletable(), $history);

            // The page: no «Slett», and the reason in its place.
            $props = $this->actingAs($case['deleter'])->get("/app/risk/risks/{$risk->id}")->assertOk()->viewData('page')['props']['permissions'];
            $this->assertFalse($props['can_delete'], $history);
            $this->assertTrue($props['delete_blocked_by_history'], $history);

            // HTTP: said plainly, nothing deleted.
            $this->actingAs($case['deleter'])->delete("/app/risk/risks/{$risk->id}")
                ->assertRedirect()
                ->assertSessionHas('error', __('procynia.risk.flash.delete_blocked'));

            // The service, for any other entry point.
            try {
                app(RiskLifecycleService::class)->delete($case['deleter'], $risk);
                $this->fail("{$history}: the service must refuse");
            } catch (RiskHistoryProtected $e) {
                $this->assertSame(409, $e->getStatusCode());
            }

            // The model, for code that skips the service.
            try {
                $risk->delete();
                $this->fail("{$history}: the model must refuse");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            // The database, for SQL that skips the model.
            $this->assertRefused(fn () => DB::table('risks')->where('id', $risk->id)->delete(), $history);

            $this->assertDatabaseHas('risks', ['id' => $risk->id]);
        }
    }

    /** Closing is the alternative, and keeps everything. */
    public function test_a_risk_with_history_is_closed_instead_and_keeps_it(): void
    {
        $case = $this->case();
        $assessment = $this->assessment($case['risk']);
        $acceptance = $this->acceptance($case['risk'], $assessment);

        $this->actingAs($case['deleter'])->patch("/app/risk/risks/{$case['risk']->id}", [
            'title' => 'Strømbrudd', 'cause' => 'Uvær', 'event' => 'Strømmen går', 'consequence' => 'Driften stopper',
            'business_area_id' => $case['area']->id, 'status' => Risk::STATUS_CLOSED,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Risk::STATUS_CLOSED, $case['risk']->fresh()->status);
        $this->assertTrue($assessment->fresh()->exists);
        $this->assertTrue($acceptance->fresh()->exists);
    }

    /** Assessments and acceptances cannot be lost on their own either, nor rewritten. */
    public function test_the_database_keeps_assessments_and_acceptances_as_they_were(): void
    {
        $case = $this->case();
        $assessment = $this->assessment($case['risk']);
        $acceptance = $this->acceptance($case['risk'], $assessment);
        $assessments = fn () => DB::table('risk_assessments')->where('id', $assessment->id);
        $acceptances = fn () => DB::table('risk_acceptances')->where('id', $acceptance->id);

        $this->assertRefused(fn () => $assessments()->delete(), 'deleting an assessment');
        $this->assertRefused(fn () => $assessments()->update(['rationale' => 'Omskrevet']), 'rewriting an assessment');
        $this->assertRefused(fn () => $assessments()->update(['residual_likelihood' => 1, 'residual_consequence' => 1]), 'lowering a score');
        $this->assertRefused(fn () => $assessments()->update(['assessed_by' => $case['other']->id]), 'changing the assessor');

        $this->assertRefused(fn () => $acceptances()->delete(), 'deleting an acceptance');
        $this->assertRefused(fn () => $acceptances()->update(['rationale' => 'Omskrevet']), 'rewriting an acceptance');
        $this->assertRefused(fn () => $acceptances()->update(['valid_until' => now()->addYears(5)->toDateString()]), 'extending an acceptance');
        $this->assertRefused(fn () => $acceptances()->update(['accepted_by_user_id' => $case['other']->id]), 'changing who accepted');

        // Revoking is the one change, once.
        $acceptances()->update(['revoked_at' => now(), 'revoked_by_user_id' => $case['deleter']->id, 'updated_at' => now()]);
        $this->assertRefused(fn () => $acceptances()->update(['revoked_at' => null, 'revoked_by_user_id' => null]), 'un-revoking');
        $this->assertRefused(fn () => $acceptances()->update(['revoked_at' => now()->subYear()]), 're-dating a revocation');
        $this->assertRefused(fn () => $acceptances()->update(['revoked_by_user_id' => $case['other']->id]), 'changing who revoked');

        // A deleted person leaves the history behind, nameless (nullOnDelete).
        $case['deleter']->forceDelete();
        $this->assertNull($assessments()->value('assessed_by'));
        $this->assertNull($acceptances()->value('accepted_by_user_id'));
        $this->assertNull($acceptances()->value('revoked_by_user_id'));
        $this->assertSame('Vurdert', $assessments()->value('rationale'));
    }

    /** The one deletion that takes history along: the customer's own. */
    public function test_deleting_the_customer_still_takes_its_risks_and_history_along(): void
    {
        $case = $this->case();
        $assessment = $this->assessment($case['risk']);
        $this->acceptance($case['risk'], $assessment);

        DB::table('customers')->where('id', $case['customer']->id)->delete();

        $this->assertDatabaseMissing('risks', ['id' => $case['risk']->id]);
        $this->assertDatabaseMissing('risk_assessments', ['risk_id' => $case['risk']->id]);
        $this->assertDatabaseMissing('risk_acceptances', ['risk_id' => $case['risk']->id]);
    }

    /** E2E runs purge their own data through a test-only step that nothing in the application takes. */
    public function test_only_test_support_purges_risk_history(): void
    {
        $case = $this->case();
        $this->acceptance($case['risk'], $this->assessment($case['risk']));

        RiskTestDataPurge::delete(Risk::query()->whereKey($case['risk']->id));

        $this->assertDatabaseMissing('risks', ['id' => $case['risk']->id]);
        $this->assertDatabaseMissing('risk_assessments', ['risk_id' => $case['risk']->id]);

        // The protection is back on for everything after it.
        $other = $this->risk($case, 'Etter rydding');
        $this->assessment($other);
        $this->assertRefused(fn () => DB::table('risks')->where('id', $other->id)->delete(), 'after the purge');

        // And no application code switches it off.
        $offenders = [];
        $root = dirname(__DIR__, 3);

        foreach (['app', 'database/seeders', 'routes', 'config'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $file) {
                if ($file->getExtension() === 'php' && preg_match('/DISABLE\s+TRIGGER|session_replication_role|TestDataPurge/i', (string) file_get_contents($file->getPathname()))) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
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

    /** @return array{customer: Customer, area: BusinessArea, risk: Risk, deleter: User, other: User} */
    private function case(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Risikohistorikk AS',
            'slug' => 'risikohistorikk-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Risiko '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions([
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_EDIT,
            CustomerPermissionCatalog::RISK_ACCEPT,
            CustomerPermissionCatalog::RISK_DELETE,
        ]);
        $role->syncBusinessAreas(false, [(int) $area->id]);

        $deleter = $this->member($customer);
        $deleter->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
        $other = $this->member($customer);

        $case = compact('customer', 'area', 'deleter', 'other');
        $case['risk'] = $this->risk($case, 'Strømbrudd');

        return $case;
    }

    /** @param array<string, mixed> $case */
    private function risk(array $case, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $case['customer']->id,
            'business_area_id' => $case['area']->id,
            'title' => $title,
            'cause' => 'Uvær',
            'event' => 'Strømmen går',
            'consequence' => 'Driften stopper',
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    private function assessment(Risk $risk): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_by' => User::query()->where('customer_id', $risk->customer_id)->value('id'),
            'assessed_at' => now(),
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => 3,
            'residual_consequence' => 2,
        ]);
    }

    private function acceptance(Risk $risk, RiskAssessment $assessment): RiskAcceptance
    {
        return RiskAcceptance::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessment_id' => $assessment->id,
            'accepted_by_user_id' => User::query()->where('customer_id', $risk->customer_id)->value('id'),
            'rationale' => 'Akseptert',
            'accepted_at' => now(),
        ]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risikohistorikk-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
