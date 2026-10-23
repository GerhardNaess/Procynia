<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ComplianceFramework;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementApplicabilityChange;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Compliance\Library\ComplianceApplicabilityService;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Compliance\Library\ComplianceFrameworkLibraryImporter;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A35 (docs/external-api-phase-0-plan.md §2i): Relevans and Kontrollvalg — that a requirement, or
 * an Annex A control, does not apply — are a person's decision, and every one is kept.
 *
 * The key is unchanged (compliance.edit), so who may decide and the screen are as before. The
 * service now passes HumanDecisionGuard (HumanDecision::COMPLIANCE_APPLICABILITY) and writes each
 * choice that changes something — from, to, the begrunnelse, who and when — to an append-only
 * history in the same transaction.
 */
class ComplianceRelevanceDecisionTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    /** The screen works as before, and each choice is kept; repeating the same choice adds nothing. */
    public function test_every_relevance_and_control_choice_is_kept_with_who_and_when(): void
    {
        ['editor' => $editor, 'requirement' => $requirement, 'control' => $control] = $this->world();

        $this->actingAs($editor)->post("/app/compliance/requirements/{$requirement->id}/applicability", [
            'applicability' => ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE,
            'rationale' => 'Vi har ingen slik virksomhet.',
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($editor)->post("/app/compliance/requirements/{$requirement->id}/applicability", [
            'applicability' => ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE,
            'rationale' => 'Vi har ingen slik virksomhet.',
        ])->assertRedirect();
        app(ComplianceApplicabilityService::class)->set($requirement->fresh(), $editor, ComplianceRequirement::APPLICABILITY_APPLIES, null);

        $this->assertSame(
            [
                [ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Vi har ingen slik virksomhet.'],
                [ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, ComplianceRequirement::APPLICABILITY_APPLIES, null],
            ],
            $requirement->applicabilityChanges()->get()->map(fn (ComplianceRequirementApplicabilityChange $change): array => [$change->from_applicability, $change->to_applicability, $change->rationale])->all(),
        );
        $this->assertSame([(int) $editor->id], $requirement->applicabilityChanges()->pluck('changed_by_user_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
        $this->assertNotNull($requirement->applicabilityChanges()->first()->changed_at);

        // Kontrollvalg in Annex A is the same decision.
        app(ComplianceApplicabilityService::class)->set($control, $editor, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Ingen egen programvareutvikling.');
        $this->assertSame(1, $control->applicabilityChanges()->count());
        $this->assertSame('Ingen egen programvareutvikling.', $control->applicabilityChanges()->sole()->rationale);
    }

    /** No integration, no API call — whatever key it holds. */
    public function test_only_a_person_decides_relevance(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirement' => $requirement] = $this->world();
        $integration = $this->complianceEditor($customer);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $this->assertRefused($requirement, $integration->fresh(), HumanDecisionRefused::class, 'integration');

        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });
        $this->assertRefused($requirement, $editor, HumanDecisionRefused::class, 'API, on behalf of a person');
    }

    /** compliance.edit in the requirement's own customer, with the module — in the service. */
    public function test_relevance_takes_compliance_edit_in_the_requirements_customer(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirement' => $requirement] = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->complianceContext();
        $this->complianceGrant($other, $otherOwner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT]);

        $this->assertRefused($requirement, $this->complianceReader($customer), AuthorizationException::class, 'reader');
        $this->assertRefused($requirement, $this->complianceEditor($other), AuthorizationException::class, 'other customer');
        $this->assertRefused($requirement, $otherOwner, AuthorizationException::class, 'other System Owner');

        $customer->packageEntitlements()->delete();
        $this->assertRefused($requirement, $editor->fresh(), AuthorizationException::class, 'no module');
    }

    /** The history cannot be rewritten, and a requirement with it is retired, never deleted. */
    public function test_the_history_is_kept(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirement' => $requirement] = $this->world();
        app(ComplianceApplicabilityService::class)->set($requirement, $editor, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Gjelder ikke.');
        $change = $requirement->applicabilityChanges()->sole();

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_applicability_changes')->where('id', $change->id)->update(['to_applicability' => 'applies']), 'rewriting a decision');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_applicability_changes')->where('id', $change->id)->delete(), 'deleting a decision');

        $this->assertFalse($requirement->fresh()->isDeletable());
        $deleter = $this->complianceManager($customer);
        $this->expectException(ValidationException::class);
        app(ComplianceRequirementLifecycleService::class)->delete($requirement->fresh(), $deleter);
    }

    public function test_deleting_the_customer_takes_the_history_along(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirement' => $requirement] = $this->world();
        app(ComplianceApplicabilityService::class)->set($requirement, $editor, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Gjelder ikke.');

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertSame(0, DB::table('compliance_requirement_applicability_changes')->where('customer_id', $customer->id)->count());
    }

    /** @param  class-string<\Throwable>  $refusal */
    private function assertRefused(ComplianceRequirement $requirement, User $actor, string $refusal, string $who): void
    {
        $before = [$requirement->fresh()->applicability, ComplianceRequirementApplicabilityChange::query()->count()];

        try {
            app(ComplianceApplicabilityService::class)->set($requirement->fresh(), $actor, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Uten rett.');
            $this->fail("{$who} must be refused");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($refusal, $e, "{$who}: ".$e->getMessage());
        }

        $this->assertSame($before, [$requirement->fresh()->applicability, ComplianceRequirementApplicabilityChange::query()->count()], $who);
    }

    private function assertDatabaseRefuses(callable $statement, string $what): void
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

    /**
     * ISO 9001 and ISO/IEC 27001 activated: a conditional ISO 9001 requirement that may be Ikke
     * relevant, and the Annex A control A.8.25 (secure development).
     *
     * @return array{customer: Customer, editor: User, requirement: ComplianceRequirement, control: ComplianceRequirement}
     */
    private function world(): array
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $requirements = collect(['iso9001:2015' => 'iso9001_2015.php', 'iso27001:2022' => 'iso27001_2022.php'])
            ->flatMap(function (string $file, string $key) use ($editor) {
                app(ComplianceFrameworkLibraryImporter::class)->import(ComplianceFrameworkLibraryImporter::definitionFromFile(database_path("data/compliance_frameworks/{$file}")));
                $source = app(ComplianceFrameworkActivationService::class)->activate(ComplianceFramework::query()->where('key', $key)->sole(), $editor);

                return ComplianceRequirement::query()->with('frameworkNode')->where('source_id', $source->id)->get();
            });
        $requirement = $requirements->first(fn (ComplianceRequirement $r): bool => ! $r->frameworkNode->isControl() && $r->frameworkNode->mayBeNotApplicable());
        $control = $requirements->first(fn (ComplianceRequirement $r): bool => $r->frameworkNode->isControl() && $r->reference === 'A.8.25');

        $this->assertNotNull($requirement, 'ISO 9001 has a conditional requirement');
        $this->assertNotNull($control, 'ISO/IEC 27001 has A.8.25');
        $this->assertSame(0, ComplianceRequirementApplicabilityChange::query()->whereIn('requirement_id', [$requirement->id, $control->id])->count(), 'activation is no decision');

        return ['customer' => $customer, 'editor' => $editor, 'requirement' => $requirement, 'control' => $control];
    }
}
