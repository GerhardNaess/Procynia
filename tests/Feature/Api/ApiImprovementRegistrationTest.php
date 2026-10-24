<?php

namespace Tests\Feature\Api;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ApiClient;
use App\Models\ApiClientCustomerGrant;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Api\ApiRequestContext;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use App\Services\Security\ActorChannel;
use App\Services\Security\ApiActorChannel;
use App\Services\Security\InteractiveActorChannel;
use App\Support\ApiOperationCatalog;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * AVF-04 and AVF-05: an integration registers an avvik or a forbedringsforslag, and adds a tiltak to
 * a case — each a named operation the System Owner switches on for the grant, on top of the write
 * app role and improvement.view in the fagområde. The work is done by ImprovementCaseCreator::create()
 * and ImprovementActionLifecycleService::create(), with the web page's rules and notifications. It
 * gets nothing more: no edit, start, close, cancel, reopen, delete or verification — from the API or
 * by calling the domain services directly.
 */
class ApiImprovementRegistrationTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const WRITE_ROLES = ['Procynia.Read.All', 'Procynia.Write.All'];

    private const BOTH = [ApiOperationCatalog::IMPROVEMENT_CASE_CREATE, ApiOperationCatalog::IMPROVEMENT_ACTION_CREATE];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->fakeEntraSigningKeys();
    }

    public function test_an_allowed_integration_registers_an_open_deviation_shown_as_the_api_reads_it(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area], 'Eva Eier');
        $headers = $this->headers($customer);

        $response = $this->postJson('/api/v1/deviations', [
            'title' => '  Feil i leveranse ',
            'description' => 'Varene kom uten sertifikat.',
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'occurred_on' => now()->subDay()->toDateString(),
            'due_on' => '2026-12-01',
            // Not accepted by the API: ignored, never written.
            'type' => ImprovementCase::TYPE_IMPROVEMENT,
            'status' => ImprovementCase::STATUS_CLOSED,
            'customer_id' => 999999,
            'cause_analysis' => 'Skal ikke lagres',
        ], $headers);

        $response->assertCreated();
        $case = ImprovementCase::query()->sole();

        $this->assertSame(
            [(int) $customer->id, (int) $area->id, ImprovementCase::TYPE_DEVIATION, ImprovementCase::STATUS_OPEN, 'Feil i leveranse', 'Varene kom uten sertifikat.', null],
            [(int) $case->customer_id, (int) $case->business_area_id, $case->type, $case->status, $case->title, $case->description, $case->cause_analysis],
        );
        $this->assertSame([$identity->id, $identity->id, $identity->id, $owner->id], [(int) $case->reported_by_user_id, (int) $case->created_by, (int) $case->updated_by, (int) $case->owner_user_id]);

        // The answer is the case as GET /deviations/{id} shows it.
        $read = $this->get("/api/v1/deviations/{$case->id}", $headers)->assertOk();
        $this->assertSame($read->json('data'), $response->json('data'));
        $response->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.owner', ['id' => $owner->id, 'name' => 'Eva Eier'])
            ->assertJsonPath('data.due_on', '2026-12-01')
            ->assertJsonPath('data.actions', [])
            ->assertJsonMissingPath('data.description');

        // The responsible person is told, as from the case page.
        $notification = UserNotification::query()->where('user_id', $owner->id)->where('event_type', 'improvement.case_assigned')->sole();
        $this->assertSame((int) $customer->id, (int) $notification->customer_id);
        $this->assertSame($identity->id, $notification->metadata['assigned_by_user_id']);

        // Not idempotent: the same request again is a second case.
        $this->postJson('/api/v1/deviations', ['title' => 'Feil i leveranse', 'description' => 'Igjen', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id], $headers)
            ->assertCreated();
        $this->assertSame(2, ImprovementCase::query()->where('customer_id', $customer->id)->count());
    }

    public function test_an_improvement_proposal_is_registered_without_a_hendelsesdato(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);

        $response = $this->postJson('/api/v1/improvements', [
            'title' => 'Bedre mal for mottakskontroll',
            'description' => 'Forslag',
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'occurred_on' => now()->subDay()->toDateString(),
        ], $this->headers($customer))->assertCreated();

        $case = ImprovementCase::query()->sole();
        $this->assertSame([ImprovementCase::TYPE_IMPROVEMENT, ImprovementCase::STATUS_OPEN, null, null], [$case->type, $case->status, $case->occurred_at, $case->due_date]);
        $response->assertJsonPath('data.id', $case->id)->assertJsonPath('data.occurred_on', null);
        $this->get("/api/v1/deviations/{$case->id}", $this->headers($customer))->assertNotFound();
    }

    public function test_an_allowed_integration_adds_a_planned_tiltak_to_an_active_case(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area], 'Eva Eier');
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, ['status' => ImprovementCase::STATUS_IN_PROGRESS]);
        $caseBefore = $case->fresh()->getAttributes();
        $headers = $this->headers($customer);

        $response = $this->postJson("/api/v1/deviations/{$case->id}/actions", [
            'title' => ' Bytt leverandør ',
            'description' => '  ',
            'owner_user_id' => $owner->id,
            'due_on' => '2026-12-01',
            'status' => ImprovementAction::STATUS_COMPLETED,
            'completion_note' => 'Skal ikke lagres',
        ], $headers);

        $response->assertCreated();
        $action = ImprovementAction::query()->sole();
        $response->assertExactJson([
            'data' => [
                'case_id' => $case->id,
                'id' => $action->id,
                'title' => 'Bytt leverandør',
                'status' => 'planned',
                'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
                'due_on' => '2026-12-01',
                'is_overdue' => false,
                'completed_at' => null,
                'verification' => ['awaiting' => false, 'current' => null, 'earlier' => []],
            ],
            'meta' => ['as_of' => $response->json('meta.as_of'), 'request_id' => $response->json('meta.request_id')],
        ]);
        $this->assertSame(
            [(int) $customer->id, (int) $case->id, ImprovementAction::STATUS_PLANNED, null, null, $identity->id, $identity->id],
            [(int) $action->customer_id, (int) $action->improvement_case_id, $action->status, $action->description, $action->completion_note, (int) $action->created_by, (int) $action->updated_by],
        );

        $notification = UserNotification::query()->where('user_id', $owner->id)->where('event_type', 'improvement.action_assigned')->sole();
        $this->assertSame($identity->id, $notification->metadata['assigned_by_user_id']);

        // The case itself is untouched, and the tiltak is among its tiltak when read.
        $this->assertSame($caseBefore, $case->fresh()->getAttributes());
        $this->get("/api/v1/deviations/{$case->id}", $headers)->assertJsonPath('data.actions.0.id', $action->id);
    }

    public function test_the_case_page_says_which_integration_registered_a_case_or_a_tiltak(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $editor = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area], 'Ola Redaktør');
        $headers = $this->headers($customer);

        $caseId = $this->postJson('/api/v1/deviations', ['title' => 'Fra agenten', 'description' => 'Beskrivelse', 'business_area_id' => $area->id, 'owner_user_id' => $editor->id], $headers)
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/deviations/{$caseId}/actions", ['title' => 'Tiltak fra agenten', 'owner_user_id' => $editor->id, 'due_on' => '2026-12-01'], $headers)->assertCreated();

        $this->app->instance(ActorChannel::class, new InteractiveActorChannel);
        $case = ImprovementCase::query()->findOrFail($caseId);
        app(ImprovementActionLifecycleService::class)->create($case, $editor, ['title' => 'Tiltak fra en person', 'description' => null, 'owner_user_id' => $editor->id, 'due_date' => '2026-12-02']);

        $this->actingAs($editor)
            ->get(route('app.improvements.show', ['caseId' => $caseId]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('case.registered_by_integration', 'Siggjo dashboards')
                ->where('actions.0.title', 'Tiltak fra agenten')
                ->where('actions.0.registered_by_integration', 'Siggjo dashboards')
                ->where('actions.1.title', 'Tiltak fra en person')
                ->where('actions.1.registered_by_integration', null));

        // A case a person registers names no integration.
        $own = app(ImprovementCaseCreator::class)->create($editor, ['type' => ImprovementCase::TYPE_DEVIATION, 'title' => 'Fra en person', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $editor->id]);
        $this->actingAs($editor)->get(route('app.improvements.show', ['caseId' => $own->id]))
            ->assertInertia(fn ($page) => $page->where('case.registered_by_integration', null)->where('case.reported_by_name', 'Ola Redaktør'));
    }

    public function test_without_the_write_role_or_the_allowed_operation_nothing_is_registered(): void
    {
        ['customer' => $customer, 'area' => $area, 'grant' => $grant] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION);
        $caseBody = ['title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id];
        $actionBody = ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_on' => '2026-12-01'];
        $actionUrl = "/api/v1/deviations/{$case->id}/actions";

        // Read role only, or the write role alone: refused before anything else.
        foreach ([['Procynia.Read.All'], ['Procynia.Write.All'], ['Procynia.Read.All', 'Procynia.Write']] as $roles) {
            $headers = $this->asCustomer($this->accessToken(['roles' => $roles]), $customer);
            $this->postJson('/api/v1/deviations', $caseBody, $headers)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->postJson('/api/v1/improvements', $caseBody, $headers)->assertForbidden();
            $this->postJson($actionUrl, $actionBody, $headers)->assertForbidden();
        }

        // Write role, but no operation allowed — or only the other one.
        $grant->forceFill(['allowed_operations' => []])->save();
        $this->postJson('/api/v1/deviations', $caseBody, $this->headers($customer))->assertForbidden();
        $this->postJson($actionUrl, $actionBody, $this->headers($customer))->assertForbidden();

        $grant->forceFill(['allowed_operations' => [ApiOperationCatalog::IMPROVEMENT_ACTION_CREATE]])->save();
        $this->postJson('/api/v1/deviations', $caseBody, $this->headers($customer))->assertForbidden();
        $this->postJson('/api/v1/improvements', $caseBody, $this->headers($customer))->assertForbidden();

        $grant->forceFill(['allowed_operations' => [ApiOperationCatalog::IMPROVEMENT_CASE_CREATE, ApiOperationCatalog::RISK_TREATMENT_ACTION_CREATE, 'improvement.edit']])->save();
        $this->postJson($actionUrl, $actionBody, $this->headers($customer))->assertForbidden();

        $this->assertSame(1, ImprovementCase::query()->count());
        $this->assertSame(0, ImprovementAction::query()->count());
        $this->assertSame(0, UserNotification::query()->count());
    }

    public function test_a_revoked_grant_or_a_disabled_client_is_refused(): void
    {
        ['client' => $client, 'customer' => $customer, 'area' => $area, 'grant' => $grant] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION);
        $caseBody = ['title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id];

        $client->forceFill(['is_enabled' => false])->save();
        $this->postJson('/api/v1/deviations', $caseBody, $this->headers($customer))->assertUnauthorized();

        $client->forceFill(['is_enabled' => true])->save();
        $grant->forceFill(['revoked_at' => now()])->save();
        $this->postJson('/api/v1/deviations', $caseBody, $this->headers($customer))->assertForbidden();
        $this->postJson("/api/v1/deviations/{$case->id}/actions", ['title' => 'T', 'owner_user_id' => $owner->id, 'due_on' => '2026-12-01'], $this->headers($customer))->assertForbidden();

        $this->assertSame(1, ImprovementCase::query()->count());
        $this->assertSame(0, ImprovementAction::query()->count());
    }

    public function test_a_customer_without_avvik_og_forbedringer_registers_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer('Uten modul', withRisk: false);
        $area = $this->businessArea($customer, 'Beredskap');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $this->grantTo($client, $customer, $identity, ['allowed_operations' => self::BOTH]);
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION);
        $headers = $this->headers($customer);

        $this->postJson('/api/v1/deviations', ['title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id], $headers)->assertForbidden();
        $this->postJson("/api/v1/deviations/{$case->id}/actions", ['title' => 'T', 'owner_user_id' => $owner->id, 'due_on' => '2026-12-01'], $headers)->assertForbidden();

        $this->assertSame(1, ImprovementCase::query()->count());
        $this->assertSame(0, ImprovementAction::query()->count());
    }

    public function test_a_case_is_registered_only_in_a_fagomrade_of_the_identity_with_a_valid_owner(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $outsideArea = $this->businessArea($customer, 'Økonomi');
        $other = $this->apiCustomer('Annen kunde');
        $otherArea = $this->businessArea($other, 'Beredskap');
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], null);
        $headers = $this->headers($customer);

        foreach ([$outsideArea->id, $otherArea->id, 999999999] as $areaId) {
            $this->postJson('/api/v1/deviations', ['title' => 'Avvik', 'description' => 'B', 'business_area_id' => $areaId, 'owner_user_id' => $owner->id], $headers)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors(['business_area_id'], 'error.fields');
        }

        $refusedOwners = [
            'cannot read cases in the area' => $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$outsideArea])->id,
            'inactive' => $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area], 'Inaktiv', false)->id,
            'another customer' => $this->person($other, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], null)->id,
            'the integration itself' => $identity->id,
            'nobody' => 999999999,
        ];

        foreach ($refusedOwners as $who => $ownerId) {
            $this->postJson('/api/v1/deviations', ['title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $ownerId], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['owner_user_id'], 'error.fields');
        }

        $this->postJson('/api/v1/deviations', [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'business_area_id', 'owner_user_id'], 'error.fields');
        $this->postJson('/api/v1/deviations', ['title' => str_repeat('x', 256), 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id, 'occurred_on' => now()->addDay()->toDateString(), 'due_on' => '01.12.2026'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'occurred_on', 'due_on'], 'error.fields');

        $this->assertSame(0, ImprovementCase::query()->count());
    }

    public function test_a_tiltak_goes_only_on_a_visible_active_case_of_the_paths_type(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $other = $this->apiCustomer('Annen kunde');
        $headers = $this->headers($customer);
        $body = ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_on' => '2026-12-01'];

        $deviation = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION);
        $improvement = $this->case($customer, $area, ImprovementCase::TYPE_IMPROVEMENT);
        $outside = $this->case($customer, $this->businessArea($customer, 'Økonomi'), ImprovementCase::TYPE_DEVIATION);
        $foreign = $this->case($other, $this->businessArea($other, 'Beredskap'), ImprovementCase::TYPE_DEVIATION);
        // Ended by a person through the lifecycle, before any API request binds the API channel.
        $closer = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$area]);
        $lifecycle = app(ImprovementCaseLifecycleService::class);
        $ended = [
            $lifecycle->close($this->case($customer, $area, ImprovementCase::TYPE_DEVIATION), $closer, 'Lukket'),
            $lifecycle->cancel($this->case($customer, $area, ImprovementCase::TYPE_DEVIATION), $closer, 'Avbrutt'),
        ];

        // The other type's path, another fagområde, another customer or no case: not there.
        foreach (["/api/v1/improvements/{$deviation->id}/actions", "/api/v1/deviations/{$improvement->id}/actions", "/api/v1/deviations/{$outside->id}/actions", "/api/v1/deviations/{$foreign->id}/actions", '/api/v1/deviations/999999999/actions'] as $url) {
            $this->postJson($url, $body, $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        $this->postJson("/api/v1/improvements/{$improvement->id}/actions", $body, $headers)->assertCreated();

        // A closed or cancelled case takes no tiltak, as on the case page.
        foreach ($ended as $case) {
            $this->postJson("/api/v1/deviations/{$case->id}/actions", $body, $headers)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }

        // The responsible person and the fields are validated as on the case page.
        $refusedOwners = [
            $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$this->businessArea($customer, 'HR')])->id,
            $this->person($other, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], null)->id,
            $identity->id,
            999999999,
        ];

        foreach ($refusedOwners as $ownerId) {
            $this->postJson("/api/v1/deviations/{$deviation->id}/actions", ['title' => 'T', 'owner_user_id' => $ownerId, 'due_on' => '2026-12-01'], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['owner_user_id'], 'error.fields');
        }

        $this->postJson("/api/v1/deviations/{$deviation->id}/actions", [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'owner_user_id', 'due_on'], 'error.fields');

        $this->assertSame(1, ImprovementAction::query()->count());
    }

    public function test_the_integration_gets_no_other_write_even_with_every_key_in_its_role(): void
    {
        ['client' => $client, 'customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        // A role that would let a person do everything: an integration keeps only improvement.view.
        $this->giveRole($identity, [
            CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT,
            CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE,
        ]);
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $headers = $this->headers($customer);

        $caseId = $this->postJson('/api/v1/deviations', ['title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/deviations/{$caseId}/actions", ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_on' => '2026-12-01'], $headers)->assertCreated();
        $case = ImprovementCase::query()->findOrFail($caseId);
        $action = ImprovementAction::query()->sole();
        $caseBefore = $case->fresh()->getAttributes();
        $actionBefore = $action->fresh()->getAttributes();

        // No other method or route is there to call.
        $this->patchJson("/api/v1/deviations/{$caseId}", [], $headers)->assertStatus(405);
        $this->deleteJson("/api/v1/deviations/{$caseId}", [], $headers)->assertStatus(405);
        $this->putJson("/api/v1/deviations/{$caseId}/actions", [], $headers)->assertStatus(405);
        $this->postJson("/api/v1/deviations/{$caseId}/close", [], $headers)->assertNotFound();
        $this->patchJson("/api/v1/deviations/{$caseId}/actions/{$action->id}", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/deviations/{$caseId}/actions/{$action->id}/complete", [], $headers)->assertNotFound();

        // And the domain services refuse the identity directly, inside an API request with both
        // operations allowed and the write role present.
        $this->inApiRequest($client, $identity);
        $access = app(ImprovementCaseAccessService::class);
        $this->assertTrue($access->canRegisterIn($identity, (int) $area->id));
        $this->assertTrue($access->canCreateAction($identity, $case));
        $this->assertFalse($access->canEdit($identity, $case));
        $this->assertFalse($access->canClose($identity, $case));
        $this->assertFalse($access->canDelete($identity, $case));

        $cases = app(ImprovementCaseCreator::class);
        $lifecycle = app(ImprovementCaseLifecycleService::class);
        $actions = app(ImprovementActionLifecycleService::class);
        $refusals = [
            'edit case' => fn () => $cases->update($identity, $case, ['type' => ImprovementCase::TYPE_DEVIATION, 'title' => 'Endret', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id]),
            'write cause' => fn () => $cases->updateCause($identity, $case, 'Årsak'),
            'start case' => fn () => $lifecycle->start($case, $identity),
            'close case' => fn () => $lifecycle->close($case, $identity, 'Lukket'),
            'cancel case' => fn () => $lifecycle->cancel($case, $identity, 'Avbrutt'),
            'reopen case' => fn () => $lifecycle->reopen($case, $identity, 'Gjenåpnet'),
            'delete case' => fn () => $lifecycle->delete($case, $identity),
            'edit tiltak' => fn () => $actions->update($action, $identity, ['title' => 'Endret', 'description' => null, 'owner_user_id' => $owner->id, 'due_date' => '2026-12-02']),
            'start tiltak' => fn () => $actions->start($action, $identity),
            'complete tiltak' => fn () => $actions->complete($action, $identity, 'Ferdig'),
            'cancel tiltak' => fn () => $actions->cancel($action, $identity, 'Avbrutt'),
            'delete tiltak' => fn () => $actions->delete($action, $identity),
            'verify tiltak' => fn () => $actions->verify($action, $identity, 'effective', 'Virket'),
        ];

        foreach ($refusals as $what => $attempt) {
            try {
                $attempt();
                $this->fail("The integration could {$what}.");
            } catch (AuthorizationException $refused) {
                if (in_array($what, ['close case', 'cancel case', 'reopen case', 'delete case', 'verify tiltak'], true)) {
                    $this->assertInstanceOf(HumanDecisionRefused::class, $refused, "{$what} was not refused as a human decision.");
                }
            }
        }

        $this->assertSame($caseBefore, $case->fresh()->getAttributes());
        $this->assertSame($actionBefore, $action->fresh()->getAttributes());
    }

    public function test_a_direct_service_call_by_the_integration_outside_the_api_is_refused(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION);
        $this->app->instance(ActorChannel::class, new InteractiveActorChannel);

        try {
            app(ImprovementCaseCreator::class)->create($identity, ['type' => ImprovementCase::TYPE_DEVIATION, 'title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $owner->id]);
            $this->fail('The integration registered a case outside the API.');
        } catch (ValidationException $refused) {
            $this->assertArrayHasKey('business_area_id', $refused->errors());
        }

        try {
            app(ImprovementActionLifecycleService::class)->create($case, $identity, ['title' => 'Tiltak', 'description' => null, 'owner_user_id' => $owner->id, 'due_date' => '2026-12-01']);
            $this->fail('The integration added a tiltak outside the API.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, ImprovementCase::query()->count());
        $this->assertSame(0, ImprovementAction::query()->count());
    }

    public function test_people_keep_their_rights_unchanged(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $editor = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $reader = $this->person($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION);
        $access = app(ImprovementCaseAccessService::class);
        $caseBody = ['type' => ImprovementCase::TYPE_DEVIATION, 'title' => 'Avvik', 'description' => 'B', 'business_area_id' => $area->id, 'owner_user_id' => $reader->id];
        $actionBody = ['title' => 'Tiltak', 'owner_user_id' => $reader->id, 'due_date' => '2026-12-01'];

        // A reader registers nothing, on the page or in the service — no grant changes that.
        $this->assertFalse($access->canRegisterIn($reader, (int) $area->id));
        $this->assertFalse($access->canCreateAction($reader, $case));
        $this->actingAs($reader)->post(route('app.improvements.store'), $caseBody)->assertSessionHasErrors('business_area_id');
        $this->actingAs($reader)->post(route('app.improvements.actions.store', ['caseId' => $case->id]), $actionBody)->assertForbidden();

        // An editor registers both as before, as the reporter and creator, with no integration origin.
        $this->assertTrue($access->canRegisterIn($editor, (int) $area->id));
        $this->assertTrue($access->canCreateAction($editor, $case));
        $this->actingAs($editor)->post(route('app.improvements.store'), $caseBody)->assertRedirect();
        $this->actingAs($editor)->post(route('app.improvements.actions.store', ['caseId' => $case->id]), $actionBody + ['description' => '  '])->assertRedirect();

        $registered = ImprovementCase::query()->whereKeyNot($case->id)->sole();
        $action = ImprovementAction::query()->sole();
        $this->assertSame([$editor->id, $editor->id, ImprovementCase::STATUS_OPEN], [(int) $registered->reported_by_user_id, (int) $registered->created_by, $registered->status]);
        $this->assertSame([$editor->id, ImprovementAction::STATUS_PLANNED, null], [(int) $action->created_by, $action->status, $action->description]);
    }

    /** @return array{client: ApiClient, customer: Customer, area: BusinessArea, identity: User, grant: ApiClientCustomerGrant} */
    private function writer(): array
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer('Nordlys AS');
        $area = $this->businessArea($customer, 'Beredskap');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $grant = $this->grantTo($client, $customer, $identity, ['allowed_operations' => self::BOTH]);

        return ['client' => $client, 'customer' => $customer, 'area' => $area, 'identity' => $identity, 'grant' => $grant];
    }

    /** @return array<string, string> */
    private function headers(Customer $customer): array
    {
        return $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<BusinessArea>|null  $areas
     */
    private function person(Customer $customer, array $keys, ?array $areas, string $name = 'Person', bool $active = true): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => 'person-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => $active,
        ]);
        $this->giveRole($user, $keys, $areas);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function case(Customer $customer, BusinessArea $area, string $type, array $overrides = []): ImprovementCase
    {
        // Status is not fillable: a case moves only through the lifecycle, so the fixture forces it.
        $case = ImprovementCase::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => $type,
            'title' => 'Eksisterende sak',
            'description' => 'Beskrivelse',
        ]);

        return $overrides === [] ? $case : tap($case->forceFill($overrides))->save();
    }

    private function inApiRequest(ApiClient $client, User $actor): void
    {
        $this->app->instance(ActorChannel::class, new ApiActorChannel);
        $context = new ApiRequestContext('test-'.Str::random(8));
        $context->authenticated($client, true);
        $context->actor = $actor;
        $this->app->instance(ApiRequestContext::class, $context);
    }
}
