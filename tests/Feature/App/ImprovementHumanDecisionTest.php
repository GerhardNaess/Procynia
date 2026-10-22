<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Models\User;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The human decisions in Avvik og forbedringer — Lukk, Avbryt and Gjenåpne a case, Effektverifisering
 * of a tiltak, and deleting a case — are refused in the services themselves, phase 0 step 10
 * (docs/external-api-phase-0-plan.md §4.4).
 *
 * An integration, any request through the API (delegated mode included) and a person without the
 * permission in the case's fagområde are turned away there, whatever the entry point, and nothing
 * is written. The controllers keep their own checks first, so what a person sees is unchanged.
 */
class ImprovementHumanDecisionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_EDIT,
        CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        CustomerPermissionCatalog::IMPROVEMENT_DELETE,
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
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /**
     * The (permission, area) pairs are read from the roles directly (BusinessAreaGrants), so the rule
     * that an integration never holds a human-only key has to hold in the access service too.
     */
    public function test_an_integration_holds_no_human_only_improvement_key_through_its_roles(): void
    {
        ['customer' => $customer] = $this->context();
        $area = $this->area($customer, 'Drift');
        $case = $this->improvementCase($customer, $area, 'Feil i rutine');
        $decider = $this->member($customer);
        $integration = $this->member($customer);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $role = $this->grantAll($customer, $decider, self::EVERYTHING);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
        $access = app(ImprovementCaseAccessService::class);

        foreach ([CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE] as $key) {
            $this->assertSame([], $access->areaIdsFor($integration, $key), $key);
            $this->assertFalse($access->can($integration, $key, $case), $key);
            $this->assertFalse($access->reachesAllAreas($integration, $key), $key);
            $this->assertTrue($access->can($decider, $key, $case), $key);
        }

        // Keys a machine may hold reach its fagområder as before.
        foreach ([CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT] as $key) {
            $this->assertTrue($access->can($integration, $key, $case), $key);
        }
    }

    /** A person with improvement.close and .delete in the area takes every decision, through the services. */
    public function test_a_person_with_the_keys_in_the_area_takes_every_decision(): void
    {
        $case = $this->world();
        $lifecycle = app(ImprovementCaseLifecycleService::class);

        $verification = app(ImprovementActionLifecycleService::class)->verify($case['action'], $case['decider'], ImprovementActionVerification::RESULT_EFFECTIVE, 'Ingen nye feil på en måned.');
        $this->assertSame((int) $case['decider']->id, (int) $verification->verified_by_user_id);

        $lifecycle->close($case['case'], $case['decider'], 'Rutinen er endret og virker.');
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $case['case']->fresh()->status);
        $this->assertSame((int) $case['decider']->id, (int) $case['case']->fresh()->closed_by_user_id);

        $lifecycle->reopen($case['case'], $case['decider'], 'Feilen kom tilbake.');
        $lifecycle->cancel($case['case'], $case['decider'], 'Dekkes av en annen sak.');
        $this->assertSame(ImprovementCase::STATUS_CANCELLED, $case['case']->fresh()->status);

        // Every change is history, under the person who made it.
        $this->assertSame(
            [[ImprovementCase::STATUS_OPEN, ImprovementCase::STATUS_CLOSED], [ImprovementCase::STATUS_CLOSED, ImprovementCase::STATUS_OPEN], [ImprovementCase::STATUS_OPEN, ImprovementCase::STATUS_CANCELLED]],
            ImprovementCaseStatusChange::query()->where('improvement_case_id', $case['case']->id)->orderBy('id')->get()->map(fn ($c): array => [$c->from_status, $c->to_status])->all(),
        );
        $this->assertSame([(int) $case['decider']->id], ImprovementCaseStatusChange::query()->where('improvement_case_id', $case['case']->id)->distinct()->pluck('changed_by_user_id')->map(fn ($id): int => (int) $id)->all());

        $mistake = $this->improvementCase($case['customer'], $case['area'], 'Registrert ved en feil');
        $lifecycle->delete($mistake, $case['decider']);
        $this->assertDatabaseMissing('improvement_cases', ['id' => $mistake->id]);
    }

    public function test_no_decision_is_taken_by_an_integration(): void
    {
        $case = $this->world();
        $integration = $this->member($case['customer']);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $integration->customerRoles()->attach($case['role']->id, ['customer_id' => $case['customer']->id]);

        $this->assertEveryDecisionRefused($case, $integration->fresh(), HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
    {
        $case = $this->world();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($case, $case['decider'], HumanDecisionRefused::class);
    }

    public function test_an_inactive_person_takes_no_decision(): void
    {
        $case = $this->world();
        $case['decider']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($case, $case['decider']->fresh(), HumanDecisionRefused::class);
    }

    /** The service refuses on its own. Each person lacks exactly one thing the decisions need. */
    public function test_a_person_without_the_key_in_the_cases_area_is_refused_by_the_service(): void
    {
        $case = $this->world();
        $elsewhere = $this->area($case['customer'], 'HR');
        $editorOnly = $this->editor($case['customer'], $case['area']);
        $otherArea = $this->member($case['customer']);
        $this->grant($case['customer'], $otherArea, self::EVERYTHING, [$elsewhere]);
        $blind = $this->member($case['customer']);
        $this->grant($case['customer'], $blind, [CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE], [$case['area']]);
        $split = $this->member($case['customer']);
        $this->grant($case['customer'], $split, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$case['area']]);
        $this->grant($case['customer'], $split, self::EVERYTHING, [$elsewhere]);

        foreach ([
            'edits, decides nothing' => $editorOnly,
            'decides, but in another fagområde' => $otherArea,
            'holds the keys, but cannot see the case' => $blind,
            'view here, decisions elsewhere' => $split,
            'System Owner without a role' => $case['owner'],
        ] as $name => $person) {
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $name);
        }
    }

    /** Customer separation: a decider in their own customer is nobody in another's. */
    public function test_another_customer_can_neither_read_nor_decide_on_a_case(): void
    {
        $case = $this->world();
        ['customer' => $other] = $this->context();
        $outsider = $this->member($other);
        $this->grant($other, $outsider, self::EVERYTHING, [$this->area($other, 'Drift')]);
        $everywhere = $this->member($other);
        $this->grantAll($other, $everywhere, self::EVERYTHING);

        foreach (['explicit area' => $outsider, '«Alle»' => $everywhere] as $name => $person) {
            $this->assertNull(app(ImprovementCaseAccessService::class)->findVisible($person, (int) $case['case']->id), $name);
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $name);

            $base = "/app/improvements/{$case['case']->id}";
            $this->actingAs($person)->get($base)->assertNotFound();
            $this->actingAs($person)->post("{$base}/close", ['closing_note' => 'Fremmed'])->assertNotFound();
            $this->actingAs($person)->post("{$base}/cancel", ['reason' => 'Fremmed'])->assertNotFound();
            $this->actingAs($person)->delete($base)->assertNotFound();
            $this->actingAs($person)->post("{$base}/actions/{$case['action']->id}/verify", ['result' => ImprovementActionVerification::RESULT_EFFECTIVE, 'note' => 'Fremmed'])->assertNotFound();
        }
    }

    /** The services judge the case as it stands now, not the copy the caller loaded a moment ago. */
    public function test_a_case_moved_out_of_the_deciders_area_is_judged_where_it_is_now(): void
    {
        $case = $this->world(allAreas: false);
        $stale = ImprovementCase::query()->findOrFail($case['case']->id);
        $elsewhere = $this->area($case['customer'], 'HR');
        DB::table('improvement_cases')->where('id', $case['case']->id)->update(['business_area_id' => $elsewhere->id]);

        $this->assertEveryDecisionRefused(['case' => $stale] + $case, $case['decider'], AuthorizationException::class);
    }

    /**
     * Every human decision in Avvik og forbedringer, called directly on its service with this actor:
     * each is refused with $refusal, and the case, its history and its verifications are as they were.
     *
     * @param  array<string, mixed>  $case
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $case, User $actor, string $refusal, string $who = ''): void
    {
        $lifecycle = app(ImprovementCaseLifecycleService::class);

        foreach ([
            'close' => fn () => $lifecycle->close($case['case'], $actor, 'Uten rett.'),
            'cancel' => fn () => $lifecycle->cancel($case['case'], $actor, 'Uten rett.'),
            'reopen' => fn () => $lifecycle->reopen($case['case'], $actor, 'Uten rett.'),
            'delete' => fn () => $lifecycle->delete($case['case'], $actor),
            'verify' => fn () => app(ImprovementActionLifecycleService::class)->verify($case['action'], $actor, ImprovementActionVerification::RESULT_EFFECTIVE, 'Uten rett.'),
        ] as $decision => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e->getMessage());
            }
        }

        $fresh = ImprovementCase::query()->find($case['case']->id);
        $this->assertNotNull($fresh, $who);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $fresh->status, $who);
        $this->assertSame(0, ImprovementCaseStatusChange::query()->where('improvement_case_id', $fresh->id)->count(), $who);
        $this->assertSame(0, ImprovementActionVerification::query()->where('improvement_action_id', $case['action']->id)->count(), $who);
    }

    /** @return array<string, mixed> an open case with one completed tiltak, and a decider holding every key */
    private function world(bool $allAreas = true): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $area = $this->area($customer, 'Drift');
        $decider = $this->member($customer);
        $role = $allAreas ? $this->grantAll($customer, $decider, self::EVERYTHING) : $this->grant($customer, $decider, self::EVERYTHING, [$area]);
        $case = $this->improvementCase($customer, $area, 'Feil i rutine', $decider);
        $action = $this->improvementAction($case, 'Endre rutinen', $decider);
        app(ImprovementActionLifecycleService::class)->complete($action, $decider, 'Rutinen er endret.');

        return compact('customer', 'owner', 'area', 'decider', 'role', 'case') + ['action' => $action->fresh()];
    }
}
