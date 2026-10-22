<?php

namespace Tests\Feature\App;

use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use App\Services\Improvements\ImprovementCaseQualityContextService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The writes in Avvik og forbedringer that are not human decisions — changing a case and its
 * årsaksanalyse, Start behandling, the tiltak and their lifecycle, the Kvalitet context — check
 * improvement.edit in the case's fagområde in their own services (phase 0 step 10), on the case under
 * its row lock, together with the lifecycle rule that an ended case is reopened before it is worked
 * on. A later entry point inherits them instead of re-implementing them.
 *
 * Not reserved for people: an integration with improvement.edit in the area may make them.
 */
class ImprovementServiceAccessTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
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

    public function test_an_editor_in_the_area_works_the_case_and_its_tiltak_through_the_services(): void
    {
        $world = $this->world();
        $editor = $world['editor'];

        app(ImprovementCaseCreator::class)->update($editor, $world['case'], $this->payload($world['area'], $editor, 'Feil i rutine, presisert'));
        app(ImprovementCaseCreator::class)->updateCause($editor, $world['case'], 'Rutinen var uklar.');
        app(ImprovementCaseLifecycleService::class)->start($world['case'], $editor);

        $actions = app(ImprovementActionLifecycleService::class);
        $action = $actions->create($world['case'], $editor, $this->fields($editor));
        $actions->update($action, $editor, ['title' => 'Skriv ny rutine'] + $this->fields($editor));
        $actions->start($action, $editor);
        $actions->complete($action->fresh(), $editor, 'Ny rutine skrevet.');
        $actions->reopen($action->fresh(), $editor, 'Mangler godkjenning.');
        $actions->cancel($action->fresh(), $editor, 'Erstattet.');
        $mistake = $actions->create($world['case'], $editor, $this->fields($editor));
        $actions->delete($mistake, $editor);

        $case = $world['case']->fresh();
        $this->assertSame(['Feil i rutine, presisert', 'Rutinen var uklar.', ImprovementCase::STATUS_IN_PROGRESS], [$case->title, $case->cause_analysis, $case->status]);
        $this->assertSame(ImprovementAction::STATUS_CANCELLED, $action->fresh()->status);
        $this->assertDatabaseMissing('improvement_actions', ['id' => $mistake->id]);
    }

    /** Not a human decision: an integration with improvement.edit in the area works a case. */
    public function test_an_integration_with_improvement_edit_may_work_a_case(): void
    {
        $world = $this->world();
        $integration = $this->editor($world['customer'], $world['area']);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        app(ImprovementCaseCreator::class)->updateCause($integration->fresh(), $world['case'], 'Fra integrasjon.');
        app(ImprovementActionLifecycleService::class)->create($world['case'], $integration->fresh(), $this->fields($world['editor']));

        $this->assertSame('Fra integrasjon.', $world['case']->fresh()->cause_analysis);
        $this->assertSame(2, ImprovementAction::query()->where('improvement_case_id', $world['case']->id)->count());
    }

    public function test_every_write_is_refused_in_the_service_without_improvement_edit_in_the_area(): void
    {
        $world = $this->world();
        $elsewhere = $this->area($world['customer'], 'HR');
        ['customer' => $other] = $this->context();

        $reader = $this->member($world['customer']);
        $this->grant($world['customer'], $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$world['area']]);
        $blind = $this->member($world['customer']);
        $this->grant($world['customer'], $blind, [CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$world['area']]);
        $outsider = $this->member($other);
        $this->grant($other, $outsider, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$this->area($other, 'Drift')]);
        $everywhere = $this->member($other);
        $this->grantAll($other, $everywhere, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT]);

        foreach ([
            'reads and decides, but does not edit' => $reader,
            'edits in another fagområde' => $this->editor($world['customer'], $elsewhere),
            'edits, but cannot see the case' => $blind,
            'System Owner without a role' => $world['owner'],
            'another customer (explicit area)' => $outsider,
            'another customer («Alle»)' => $everywhere,
        ] as $who => $person) {
            $this->assertEveryWriteRefused($world, $person, $who);
        }
    }

    /** The lifecycle rule holds in the services, not only in the controller: an ended case is reopened first. */
    public function test_an_ended_case_is_not_worked_on_through_any_service(): void
    {
        $world = $this->world();
        app(ImprovementActionLifecycleService::class)->cancel($world['action'], $world['handler'], 'Trengs ikke.');
        app(ImprovementCaseLifecycleService::class)->cancel($world['case'], $world['handler'], 'Duplikat.');

        foreach ([
            'change' => fn () => app(ImprovementCaseCreator::class)->update($world['editor'], $world['case'], $this->payload($world['area'], $world['editor'], 'Endret etter avslutning')),
            'årsaksanalyse' => fn () => app(ImprovementCaseCreator::class)->updateCause($world['editor'], $world['case'], 'Etter avslutning'),
            'new tiltak' => fn () => app(ImprovementActionLifecycleService::class)->create($world['case'], $world['editor'], $this->fields($world['editor'])),
            'context' => fn () => app(ImprovementCaseQualityContextService::class)->syncProcess($world['handlerWithQuality'], $world['case'], 1, true, []),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$write} must be refused on an ended case");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $case = $world['case']->fresh();
        $this->assertSame(['Feil i rutine', null, ImprovementCase::STATUS_CANCELLED], [$case->title, $case->cause_analysis, $case->status]);
        $this->assertSame(1, ImprovementAction::query()->where('improvement_case_id', $case->id)->count());
    }

    /** The responsible person is checked in the service: someone who cannot open the case is refused. */
    public function test_a_tiltak_owner_who_cannot_see_the_case_is_refused_in_the_service(): void
    {
        $world = $this->world();
        $stranger = $this->editor($world['customer'], $this->area($world['customer'], 'HR'));

        foreach ([
            'create' => fn () => app(ImprovementActionLifecycleService::class)->create($world['case'], $world['editor'], $this->fields($stranger)),
            'update' => fn () => app(ImprovementActionLifecycleService::class)->update($world['action'], $world['editor'], $this->fields($stranger)),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$write} must refuse the owner");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('owner_user_id', $e->errors(), $write);
            }
        }

        $this->assertSame((int) $world['handler']->id, (int) $world['action']->fresh()->owner_user_id);
    }

    /** The Kvalitet context also needs Kvalitet read access, in the service as in the controller. */
    public function test_the_kvalitet_context_needs_kvalitet_read_access_in_the_service(): void
    {
        $world = $this->world();

        $this->expectException(AuthorizationException::class);
        app(ImprovementCaseQualityContextService::class)->syncProcess($world['editor'], $world['case'], 1, true, []);
    }

    /** @param array<string, mixed> $world */
    private function assertEveryWriteRefused(array $world, User $person, string $who): void
    {
        $actions = app(ImprovementActionLifecycleService::class);

        foreach ([
            'change' => fn () => app(ImprovementCaseCreator::class)->update($person, $world['case'], $this->payload($world['area'], $world['editor'], 'Overtatt')),
            'årsaksanalyse' => fn () => app(ImprovementCaseCreator::class)->updateCause($person, $world['case'], 'Overtatt'),
            'start' => fn () => app(ImprovementCaseLifecycleService::class)->start($world['case'], $person),
            'tiltak create' => fn () => $actions->create($world['case'], $person, $this->fields($world['editor'])),
            'tiltak update' => fn () => $actions->update($world['action'], $person, ['title' => 'Overtatt'] + $this->fields($world['editor'])),
            'tiltak start' => fn () => $actions->start($world['action'], $person),
            'tiltak complete' => fn () => $actions->complete($world['action'], $person, 'Overtatt'),
            'tiltak cancel' => fn () => $actions->cancel($world['action'], $person, 'Overtatt'),
            'tiltak delete' => fn () => $actions->delete($world['action'], $person),
            'context' => fn () => app(ImprovementCaseQualityContextService::class)->syncProcess($person, $world['case'], 1, true, []),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$write} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(AuthorizationException::class, $e, "{$who}: {$write} — ".$e->getMessage());
            }
        }

        $case = $world['case']->fresh();
        $this->assertSame(['Feil i rutine', null, ImprovementCase::STATUS_OPEN], [$case->title, $case->cause_analysis, $case->status], $who);
        $action = $world['action']->fresh();
        $this->assertSame(['Endre rutinen', ImprovementAction::STATUS_PLANNED], [$action->title, $action->status], $who);
        $this->assertSame(1, ImprovementAction::query()->where('improvement_case_id', $case->id)->count(), $who);
    }

    /** @return array{title: string, description: ?string, owner_user_id: int, due_date: string} */
    private function fields(User $owner): array
    {
        return ['title' => 'Nytt tiltak', 'description' => null, 'owner_user_id' => (int) $owner->id, 'due_date' => '2030-01-31'];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('governance');
        $area = $this->area($customer, 'Drift');
        $editor = $this->editor($customer, $area);
        $handler = $this->handler($customer, $area);
        $handlerWithQuality = $this->handler($customer, $area);
        $this->grantAll($customer, $handlerWithQuality, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $case = $this->improvementCase($customer, $area, 'Feil i rutine', $editor);
        $action = $this->improvementAction($case, 'Endre rutinen', $handler);

        return compact('customer', 'owner', 'area', 'editor', 'handler', 'handlerWithQuality', 'case', 'action');
    }
}
