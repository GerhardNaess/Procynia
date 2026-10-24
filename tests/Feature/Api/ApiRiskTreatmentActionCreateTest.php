<?php

namespace Tests\Feature\Api;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ApiClient;
use App\Models\ApiClientCustomerGrant;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Api\ApiRequestContext;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskAssessmentService;
use App\Services\Risk\RiskCreator;
use App\Services\Risk\RiskLifecycleService;
use App\Services\Risk\RiskTreatmentService;
use App\Services\Security\ActorChannel;
use App\Services\Security\ApiActorChannel;
use App\Services\Security\InteractiveActorChannel;
use App\Support\ApiOperationCatalog;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * POST /api/v1/risks/{riskId}/treatment-actions — the first API write operation.
 *
 * The integration creates a real, open tiltak through RiskTreatmentService::create() when, and only
 * when, its token carries the write role, the System Owner allowed the operation for its grant, and
 * it can read the risk (customer + risk.view in the fagområde). It never gets anything more: no
 * edit, completion or reopening of tiltak, no change to the risk, no assessment or acceptance — from
 * the API or by calling the domain services directly.
 */
class ApiRiskTreatmentActionCreateTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const WRITE_ROLES = ['Procynia.Read.All', 'Procynia.Write.All'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->fakeEntraSigningKeys();
    }

    public function test_an_allowed_integration_reads_a_risk_and_creates_an_open_tiltak_through_the_domain_service(): void
    {
        ['client' => $client, 'customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area], 'Eva Eier');
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $assessment = $this->assess($risk, $owner);
        RiskAcceptance::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'assessment_id' => $assessment->id,
            'accepted_by_user_id' => $owner->id, 'accepted_at' => now(), 'rationale' => 'Akseptert',
        ]);
        $riskBefore = $risk->fresh()->getAttributes();
        $headers = $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);

        $calls = $this->recordServiceCalls();

        $this->get('/api/v1/risks', $headers)->assertOk()->assertJsonPath('data.0.id', $risk->id);

        $response = $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions", [
            'title' => '  Reservestrøm i serverrommet ',
            'owner_user_id' => $owner->id,
            'due_at' => '2026-12-01',
            // Not accepted by the API: ignored, never written.
            'outcome_note' => 'Skal ikke lagres',
            'status' => RiskTreatmentAction::STATUS_COMPLETED,
            'customer_id' => 999999,
        ], $headers);

        $response->assertCreated();
        $action = RiskTreatmentAction::query()->sole();
        $response->assertExactJson([
            'data' => [
                'id' => $action->id,
                'risk_id' => $risk->id,
                'title' => 'Reservestrøm i serverrommet',
                'status' => 'open',
                'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
                'due_at' => '2026-12-01',
                'created_at' => $action->created_at->utc()->toIso8601ZuluString(),
            ],
            'meta' => ['as_of' => $response->json('meta.as_of'), 'request_id' => $response->json('meta.request_id')],
        ]);

        $this->assertSame(1, $calls->count);
        $this->assertSame(
            [(int) $customer->id, (int) $risk->id, RiskTreatmentAction::STATUS_OPEN, $identity->id, $identity->id, null, null],
            [(int) $action->customer_id, (int) $action->risk_id, $action->status, (int) $action->created_by, (int) $action->updated_by, $action->outcome_note, $action->completed_at],
        );

        // The responsible person is told, as from the risk page.
        $notification = UserNotification::query()->where('user_id', $owner->id)->where('event_type', 'risk.action_assigned')->sole();
        $this->assertSame((int) $customer->id, (int) $notification->customer_id);
        $this->assertSame($identity->id, $notification->metadata['assigned_by_user_id']);

        // The risk itself — status, fields, assessment, acceptance — is untouched.
        $this->assertSame($riskBefore, $risk->fresh()->getAttributes());
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(1, RiskAcceptance::query()->where('risk_id', $risk->id)->whereNull('revoked_at')->count());

        // Not idempotent: the same request again is a second tiltak.
        $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions", ['title' => 'Reservestrøm i serverrommet', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'], $headers)
            ->assertCreated();
        $this->assertSame(2, RiskTreatmentAction::query()->where('risk_id', $risk->id)->count());
    }

    public function test_the_risk_page_says_which_integration_registered_a_tiltak(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $editor = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$area], 'Ola Redaktør');
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $headers = $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);

        $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions", ['title' => 'Fra agenten', 'owner_user_id' => $editor->id, 'due_at' => '2026-12-01'], $headers)->assertCreated();

        $this->app->instance(ActorChannel::class, new InteractiveActorChannel);
        app(RiskTreatmentService::class)->create($editor, $risk, ['title' => 'Fra en person', 'owner_user_id' => $editor->id, 'due_at' => '2026-12-02']);

        $this->actingAs($editor)
            ->get(route('app.risk.show', ['riskId' => $risk->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('treatment_actions.0.title', 'Fra agenten')
                ->where('treatment_actions.0.registered_by_integration', 'Siggjo dashboards')
                ->where('treatment_actions.1.title', 'Fra en person')
                ->where('treatment_actions.1.registered_by_integration', null));
    }

    public function test_without_the_write_role_or_the_allowed_operation_nothing_is_created(): void
    {
        ['client' => $client, 'customer' => $customer, 'area' => $area, 'grant' => $grant] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $body = ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'];
        $url = "/api/v1/risks/{$risk->id}/treatment-actions";

        // Read role only: the API is read, writing is refused before anything else.
        $this->postJson($url, $body, $this->asCustomer($this->accessToken(), $customer))
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->postJson($url, $body, $this->asCustomer($this->accessToken(['roles' => ['Procynia.Read.All', 'Procynia.Write']]), $customer))
            ->assertForbidden();
        // The write role alone is no way in.
        $this->postJson($url, $body, $this->asCustomer($this->accessToken(['roles' => ['Procynia.Write.All']]), $customer))
            ->assertForbidden();

        // Write role, but the System Owner has not allowed the operation.
        $grant->forceFill(['allowed_operations' => []])->save();
        $this->postJson($url, $body, $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer))
            ->assertForbidden();

        // Something outside the catalog written into the grant opens nothing.
        $grant->forceFill(['allowed_operations' => ['risk.edit', 'risk.treatment_action.update']])->save();
        $this->postJson($url, $body, $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer))
            ->assertForbidden();

        $this->assertSame(0, RiskTreatmentAction::query()->count());
        $this->assertSame(0, UserNotification::query()->count());
    }

    public function test_a_revoked_grant_or_a_disabled_client_is_refused(): void
    {
        ['client' => $client, 'customer' => $customer, 'area' => $area, 'grant' => $grant] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $body = ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'];
        $url = "/api/v1/risks/{$risk->id}/treatment-actions";
        $headers = fn (): array => $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);

        $client->forceFill(['is_enabled' => false])->save();
        $this->postJson($url, $body, $headers())->assertUnauthorized();

        $client->forceFill(['is_enabled' => true])->save();
        $grant->forceFill(['revoked_at' => now()])->save();
        $this->postJson($url, $body, $headers())->assertForbidden();

        $this->assertSame(0, RiskTreatmentAction::query()->count());
    }

    public function test_another_customers_risk_or_a_risk_outside_the_fagomrader_is_not_found(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], null);
        $outside = $this->risk($customer, $this->businessArea($customer, 'Økonomi'), 'Utenfor fagområdet');
        $other = $this->apiCustomer('Annen kunde');
        $otherArea = $this->businessArea($other, 'Beredskap');
        $foreign = $this->risk($other, $otherArea, 'Annen kundes risiko');
        $headers = $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);

        foreach ([$outside, $foreign] as $risk) {
            $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions", ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'], $headers)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');
        }

        $this->postJson('/api/v1/risks/999999999/treatment-actions', ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'], $headers)
            ->assertNotFound();

        // The other customer cannot be chosen: there is no grant for it.
        $this->postJson("/api/v1/risks/{$foreign->id}/treatment-actions", ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'], $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $other))
            ->assertForbidden();

        $this->assertSame(0, RiskTreatmentAction::query()->count());
    }

    public function test_the_responsible_person_and_the_fields_are_validated_as_on_the_risk_page(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $other = $this->apiCustomer('Annen kunde');
        $headers = $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);
        $url = "/api/v1/risks/{$risk->id}/treatment-actions";

        $refusedOwners = [
            'cannot see the risk' => $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$this->businessArea($customer, 'Økonomi')])->id,
            'inactive' => $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area], 'Inaktiv', false)->id,
            'another customer' => $this->person($other, [CustomerPermissionCatalog::RISK_VIEW], null)->id,
            'the integration itself' => $identity->id,
            'nobody' => 999999999,
        ];

        foreach ($refusedOwners as $who => $ownerId) {
            $this->postJson($url, ['title' => 'Tiltak', 'owner_user_id' => $ownerId, 'due_at' => '2026-12-01'], $headers)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors(['owner_user_id'], 'error.fields');
        }

        $this->postJson($url, [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'owner_user_id', 'due_at'], 'error.fields');
        $this->postJson($url, ['title' => str_repeat('x', 256), 'owner_user_id' => $identity->id, 'due_at' => '01.12.2026'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'due_at'], 'error.fields');

        $this->assertSame(0, RiskTreatmentAction::query()->count());
    }

    public function test_a_closed_risk_takes_a_tiltak_as_it_does_on_the_risk_page(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Lukket risiko', Risk::STATUS_CLOSED);

        $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions", ['title' => 'Etterarbeid', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'], $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer))
            ->assertCreated();

        $this->assertSame(Risk::STATUS_CLOSED, $risk->fresh()->status);
    }

    public function test_the_integration_gets_no_other_write_on_the_risk_even_with_edit_keys_in_its_role(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity, 'client' => $client] = $this->writer();
        // A role that would let a person do everything: an integration keeps only risk.view.
        $this->giveRole($identity, [
            CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE, CustomerPermissionCatalog::RISK_EDIT,
            CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT, CustomerPermissionCatalog::RISK_DELETE,
        ]);
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $assessment = $this->assess($risk, $owner);
        $headers = $this->asCustomer($this->accessToken(['roles' => self::WRITE_ROLES]), $customer);

        $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions", ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01'], $headers)->assertCreated();
        $action = RiskTreatmentAction::query()->sole();
        $riskBefore = $risk->fresh()->getAttributes();
        $actionBefore = $action->fresh()->getAttributes();

        // No other method or route is there to call.
        $this->putJson("/api/v1/risks/{$risk->id}/treatment-actions", [], $headers)->assertStatus(405);
        $this->deleteJson("/api/v1/risks/{$risk->id}/treatment-actions", [], $headers)->assertStatus(405);
        $this->postJson('/api/v1/risks', [], $headers)->assertStatus(405);
        $this->patchJson("/api/v1/risks/{$risk->id}", [], $headers)->assertNotFound();
        $this->patchJson("/api/v1/risks/{$risk->id}/treatment-actions/{$action->id}", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/risks/{$risk->id}/treatment-actions/{$action->id}/complete", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/risks/{$risk->id}/assessments", [], $headers)->assertNotFound();

        // And the domain services refuse the identity directly, inside an API request with the
        // operation allowed and the write role present.
        $this->inApiRequest($client, $identity);
        $access = app(RiskAccessService::class);
        $this->assertTrue($access->canCreateTreatmentAction($identity, $risk));
        $this->assertFalse($access->canEdit($identity, $risk));
        $this->assertFalse($access->canAssess($identity, $risk));
        $this->assertFalse($access->canAccept($identity, $risk));
        $this->assertFalse($access->canDelete($identity, $risk));

        $refusals = [
            'edit tiltak' => fn () => app(RiskTreatmentService::class)->update($identity, $action, $risk, ['title' => 'Endret', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-02']),
            'complete tiltak' => fn () => app(RiskTreatmentService::class)->complete($identity, $action, 'Ferdig'),
            'reopen tiltak' => fn () => app(RiskTreatmentService::class)->reopen($identity, $action),
            'edit risk' => fn () => app(RiskCreator::class)->update($identity, $risk, ['title' => 'Endret', 'business_area_id' => $area->id, 'status' => Risk::STATUS_CLOSED, 'cause' => 'a', 'event' => 'b', 'consequence' => 'c']),
            'delete risk' => fn () => app(RiskLifecycleService::class)->delete($identity, $risk),
            'assess' => fn () => app(RiskAssessmentService::class)->record($identity, $risk, ['inherent_likelihood' => 1, 'inherent_consequence' => 1], 'Ny vurdering'),
            'accept' => fn () => app(RiskAcceptanceService::class)->accept($identity, $risk, $assessment->id, 'Akseptert', null),
        ];

        foreach ($refusals as $what => $attempt) {
            try {
                $attempt();
                $this->fail("The integration could {$what}.");
            } catch (AuthorizationException $refused) {
                if (in_array($what, ['assess', 'accept'], true)) {
                    $this->assertInstanceOf(HumanDecisionRefused::class, $refused, "{$what} was not refused as a human decision.");
                }
            }
        }

        $this->assertSame($riskBefore, $risk->fresh()->getAttributes());
        $this->assertSame($actionBefore, $action->fresh()->getAttributes());
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $risk->id)->count());
    }

    public function test_a_direct_service_call_by_the_integration_outside_the_api_is_refused(): void
    {
        ['customer' => $customer, 'area' => $area, 'identity' => $identity] = $this->writer();
        $owner = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $this->app->instance(ActorChannel::class, new InteractiveActorChannel);

        $this->expectException(AuthorizationException::class);

        try {
            app(RiskTreatmentService::class)->create($identity, $risk, ['title' => 'Tiltak', 'owner_user_id' => $owner->id, 'due_at' => '2026-12-01']);
        } finally {
            $this->assertSame(0, RiskTreatmentAction::query()->count());
        }
    }

    public function test_people_keep_their_rights_unchanged(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->writer();
        $editor = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$area]);
        $reader = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $body = ['title' => 'Tiltak', 'owner_user_id' => $reader->id, 'due_at' => '2026-12-01'];
        $access = app(RiskAccessService::class);

        // A reader cannot create a tiltak, on the page or in the service — no grant changes that.
        $this->assertFalse($access->canCreateTreatmentAction($reader, $risk));
        $this->actingAs($reader)->post(route('app.risk.actions.store', ['riskId' => $risk->id]), $body)->assertForbidden();

        try {
            app(RiskTreatmentService::class)->create($reader, $risk, $body);
            $this->fail('A reader created a tiltak.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // An editor creates one as before, with the person as creator and no integration origin.
        $this->assertTrue($access->canCreateTreatmentAction($editor, $risk));
        $this->actingAs($editor)->post(route('app.risk.actions.store', ['riskId' => $risk->id]), $body)->assertRedirect();
        $action = RiskTreatmentAction::query()->sole();
        $this->assertSame([$editor->id, $editor->id, 'open'], [(int) $action->created_by, (int) $action->updated_by, $action->status]);
        $this->assertNull(app(RiskTreatmentService::class)->actionsFor($risk)[0]['registered_by_integration']);
    }

    /** @return array{client: ApiClient, customer: Customer, area: BusinessArea, identity: User, grant: ApiClientCustomerGrant} */
    private function writer(): array
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer('Nordlys AS');
        $area = $this->businessArea($customer, 'Beredskap');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $grant = $this->grantTo($client, $customer, $identity, ['allowed_operations' => [ApiOperationCatalog::RISK_TREATMENT_ACTION_CREATE]]);

        return ['client' => $client, 'customer' => $customer, 'area' => $area, 'identity' => $identity, 'grant' => $grant];
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

    private function risk(Customer $customer, BusinessArea $area, string $title, string $status = Risk::STATUS_IDENTIFIED): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => $status,
        ]);
    }

    private function assess(Risk $risk, User $assessor): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_by' => $assessor->id,
            'assessed_at' => now()->subDay(),
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 3,
            'inherent_consequence' => 3,
        ]);
    }

    /** Counts calls to RiskTreatmentService::create(), which still does the work. */
    private function recordServiceCalls(): object
    {
        $calls = new class
        {
            public int $count = 0;
        };

        $this->app->bind(RiskTreatmentService::class, fn ($app) => new class($app->make(RiskAccessService::class), $calls) extends RiskTreatmentService
        {
            public function __construct(RiskAccessService $access, private readonly object $calls)
            {
                parent::__construct($access);
            }

            public function create(User $actor, Risk $risk, array $data): RiskTreatmentAction
            {
                $this->calls->count++;

                return parent::create($actor, $risk, $data);
            }
        });

        return $calls;
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
