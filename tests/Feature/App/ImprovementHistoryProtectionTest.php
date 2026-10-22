<?php

namespace Tests\Feature\App;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\ImprovementTestDataPurge;
use Tests\TestCase;

/**
 * The history of Avvik og forbedringer cannot be lost, phase 0 step 10.
 *
 * Status changes and Effektverifisering were already guarded against UPDATE. Now nothing deletes
 * them on its own, and a case or tiltak that carries them is not deleted either — not past
 * isDeletable() through Eloquent, and not in SQL. The product's rule is unchanged: a case or a tiltak
 * registered by mistake, with no history, is deleted as before; the customer's own deletion still
 * takes everything along.
 */
class ImprovementHistoryProtectionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
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

    /** Unchanged: a case and a tiltak registered by mistake are deleted, through the page as before. */
    public function test_a_case_and_a_tiltak_without_history_are_deleted_as_before(): void
    {
        $world = $this->world();
        $mistake = $this->improvementCase($world['customer'], $world['area'], 'Registrert ved en feil');
        $planned = $this->improvementAction($world['case'], 'Lagt til ved en feil', $world['handler']);

        $this->actingAs($world['handler'])->delete("/app/improvements/{$world['case']->id}/actions/{$planned->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('improvement_actions', ['id' => $planned->id]);

        $this->actingAs($world['handler'])->delete("/app/improvements/{$mistake->id}")->assertRedirect('/app/improvements');
        $this->assertDatabaseMissing('improvement_cases', ['id' => $mistake->id]);
    }

    /** A case with history: refused by the service, the model and the database — and its history stays. */
    public function test_a_case_with_history_or_tiltak_is_never_deleted(): void
    {
        $world = $this->world();
        $cancelled = $this->improvementCase($world['customer'], $world['area'], 'Avbrutt');
        app(ImprovementCaseLifecycleService::class)->cancel($cancelled, $world['handler'], 'Duplikat');

        foreach (['with tiltak' => $world['case'], 'with a status change' => $cancelled] as $name => $case) {
            try {
                app(ImprovementCaseLifecycleService::class)->delete($case, $world['handler']);
                $this->fail("{$name}: the service must refuse");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }

            try {
                $case->fresh()->delete();
                $this->fail("{$name}: the model must refuse");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }

            $this->assertRefused(fn () => DB::table('improvement_cases')->where('id', $case->id)->delete(), $name);
            $this->assertDatabaseHas('improvement_cases', ['id' => $case->id]);
        }

        $this->assertSame(1, ImprovementCaseStatusChange::query()->where('improvement_case_id', $cancelled->id)->count());
    }

    /** A tiltak with history, the same: cancelled instead, never deleted. */
    public function test_a_tiltak_with_history_is_never_deleted(): void
    {
        $world = $this->world();

        try {
            app(ImprovementActionLifecycleService::class)->delete($world['action']);
            $this->fail('the service must refuse');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $world['action']->fresh()->delete();
            $this->fail('the model must refuse');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertRefused(fn () => DB::table('improvement_actions')->where('id', $world['action']->id)->delete(), 'a tiltak with history');
        $this->assertDatabaseHas('improvement_actions', ['id' => $world['action']->id]);
    }

    /** History rows are never deleted on their own — not even in SQL — nor changed. */
    public function test_history_rows_cannot_be_deleted_or_changed(): void
    {
        $world = $this->world();
        $lifecycle = app(ImprovementCaseLifecycleService::class);
        $lifecycle->start($world['case'], $world['handler']);
        $caseChange = ImprovementCaseStatusChange::query()->where('improvement_case_id', $world['case']->id)->sole();
        $actionChange = ImprovementActionStatusChange::query()->where('improvement_action_id', $world['action']->id)->sole();

        foreach ([
            'improvement_case_status_changes' => $caseChange->id,
            'improvement_action_status_changes' => $actionChange->id,
            'improvement_action_verifications' => $world['verification']->id,
        ] as $table => $id) {
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->delete(), "deleting from {$table}");
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->update(['customer_id' => $world['customer']->id + 1]), "changing {$table}");
        }

        $this->assertRefused(fn () => DB::table('improvement_action_verifications')->where('id', $world['verification']->id)->update(['result' => ImprovementActionVerification::RESULT_NOT_EFFECTIVE]), 'rewriting a verification');
    }

    /** Who did what is on every history row, and stays when the person is deleted (nameless). */
    public function test_every_history_row_names_its_actor(): void
    {
        $world = $this->world();
        app(ImprovementCaseLifecycleService::class)->start($world['case'], $world['handler']);

        $this->assertSame((int) $world['handler']->id, (int) ImprovementCaseStatusChange::query()->where('improvement_case_id', $world['case']->id)->value('changed_by_user_id'));
        $this->assertSame((int) $world['handler']->id, (int) ImprovementActionStatusChange::query()->where('improvement_action_id', $world['action']->id)->value('changed_by_user_id'));
        $this->assertSame((int) $world['handler']->id, (int) $world['verification']->verified_by_user_id);

        $world['handler']->forceDelete();

        $this->assertNull(ImprovementCaseStatusChange::query()->where('improvement_case_id', $world['case']->id)->value('changed_by_user_id'));
        $this->assertNull(DB::table('improvement_action_verifications')->where('id', $world['verification']->id)->value('verified_by_user_id'));
        $this->assertSame(1, ImprovementActionVerification::query()->where('improvement_action_id', $world['action']->id)->count());
    }

    /** The one deletion that takes history along: the customer's own. */
    public function test_deleting_the_customer_still_takes_cases_and_history_along(): void
    {
        $world = $this->world();
        app(ImprovementCaseLifecycleService::class)->start($world['case'], $world['handler']);

        DB::table('customers')->where('id', $world['customer']->id)->delete();

        $this->assertDatabaseMissing('improvement_cases', ['id' => $world['case']->id]);
        $this->assertDatabaseMissing('improvement_actions', ['id' => $world['action']->id]);
        $this->assertDatabaseMissing('improvement_action_verifications', ['id' => $world['verification']->id]);
    }

    /** E2E runs purge their own cases through a test-only step; the protection is on again after it. */
    public function test_only_test_support_purges_improvement_history(): void
    {
        $world = $this->world();

        ImprovementTestDataPurge::delete(ImprovementCase::query()->whereKey($world['case']->id));

        $this->assertDatabaseMissing('improvement_cases', ['id' => $world['case']->id]);
        $this->assertDatabaseMissing('improvement_action_verifications', ['id' => $world['verification']->id]);

        $other = $this->improvementCase($world['customer'], $world['area'], 'Etter rydding');
        app(ImprovementCaseLifecycleService::class)->start($other, $world['handler']);
        $this->assertRefused(fn () => DB::table('improvement_cases')->where('id', $other->id)->delete(), 'after the purge');
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

    /** @return array<string, mixed> an open case with a completed, verified tiltak */
    private function world(): array
    {
        ['customer' => $customer] = $this->context();
        $area = $this->area($customer, 'Drift');
        $handler = $this->handler($customer, $area);
        $case = $this->improvementCase($customer, $area, 'Feil i rutine', $handler);
        $action = $this->improvementAction($case, 'Endre rutinen', $handler);
        $actions = app(ImprovementActionLifecycleService::class);
        $actions->complete($action, $handler, 'Rutinen er endret.');
        $verification = $actions->verify($action->fresh(), $handler, ImprovementActionVerification::RESULT_EFFECTIVE, 'Virker.');

        return compact('customer', 'area', 'handler', 'case', 'verification') + ['action' => ImprovementAction::query()->findOrFail($action->id)];
    }
}
