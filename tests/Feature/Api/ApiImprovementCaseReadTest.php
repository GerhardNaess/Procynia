<?php

namespace Tests\Feature\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * GET /api/v1/improvements (AVF-02), GET /api/v1/deviations/{caseId} and
 * GET /api/v1/improvements/{caseId} (AVF-03): forbedringer listed like avvik, and one case of either
 * type with its tiltak and effektverifisering — through ImprovementCaseAccessService, in structured
 * fields only, each endpoint for its own type, and without writing anything.
 */
class ApiImprovementCaseReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ROW_KEYS = [
        'id', 'title', 'status', 'business_area', 'owner', 'occurred_on', 'due_on', 'is_overdue',
        'closed_at', 'follow_up', 'created_at', 'updated_at',
    ];

    private const ACTION_KEYS = ['id', 'title', 'status', 'owner', 'due_on', 'is_overdue', 'completed_at', 'verification'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_forbedringer_are_listed_in_the_same_fields_as_avvik_and_never_avvik(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $owner = $this->person($customer, 'Eva Eier');
        $reporter = $this->person($customer, 'Rolf Rapportør');
        $improvement = $this->case($customer, $area, ImprovementCase::TYPE_IMPROVEMENT, 'Bedre rutine for innkjøp', [
            'owner_user_id' => $owner->id,
            'reported_by_user_id' => $reporter->id,
            'description' => 'FRITEKST-BESKRIVELSE',
            'due_date' => now()->addMonth()->toDateString(),
        ]);
        $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, 'Et avvik');
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/improvements', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [self::ROW_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0', [
            'id' => $improvement->id,
            'title' => 'Bedre rutine for innkjøp',
            'status' => 'open',
            'business_area' => ['id' => $area->id, 'name' => 'Drift'],
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
            'occurred_on' => null,
            'due_on' => now()->addMonth()->toDateString(),
            'is_overdue' => false,
            'closed_at' => null,
            'follow_up' => ['actions_total' => 0, 'actions_open' => 0, 'attention' => []],
            'created_at' => $improvement->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $improvement->updated_at->utc()->toIso8601ZuluString(),
        ]);

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Rolf', 'Et avvik', 'description', 'reported_by', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_the_improvement_list_filters_and_pages_like_the_avvik_list(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $drift = $this->businessArea($customer, 'Drift');
        $salg = $this->businessArea($customer, 'Salg');
        $overdue = $this->case($customer, $drift, ImprovementCase::TYPE_IMPROVEMENT, 'Over frist', ['due_date' => now()->subDay()->toDateString()]);
        $closed = $this->case($customer, $salg, ImprovementCase::TYPE_IMPROVEMENT, 'Lukket');
        $closed->forceFill(['status' => ImprovementCase::STATUS_CLOSED, 'closed_at' => now(), 'closing_note' => 'Lukket'])->save();
        $third = $this->case($customer, $salg, ImprovementCase::TYPE_IMPROVEMENT, 'Tredje');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->assertSame([$overdue->id], $this->ids($this->get('/api/v1/improvements?overdue=true', $headers)->json()));
        $this->assertSame([$closed->id], $this->ids($this->get('/api/v1/improvements?status=closed', $headers)->json()));
        $this->assertSame([$closed->id, $third->id], $this->ids($this->get('/api/v1/improvements?business_area_id='.$salg->id, $headers)->json()));

        $first = $this->get('/api/v1/improvements?limit=2', $headers)->assertOk();
        $this->assertSame([$overdue->id, $closed->id], $this->ids($first->json()));
        $this->assertSame([$third->id], $this->ids($this->get('/api/v1/improvements?cursor='.$first->json('meta.next_cursor'), $headers)->json()));

        $this->get('/api/v1/improvements?status=deleted', $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['status']]]);
    }

    public function test_one_avvik_is_presented_with_its_tiltak_and_effektverifisering_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $owner = $this->person($customer, 'Eva Eier');
        $verifier = $this->person($customer, 'Vera Verifiserer');
        $case = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, 'Feil i rutine', [
            'owner_user_id' => $owner->id,
            'description' => 'FRITEKST-BESKRIVELSE',
            'cause_analysis' => 'FRITEKST-ÅRSAK',
            'occurred_at' => now()->subDays(20)->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $late = $this->action($case, 'Oppdater rutinen', now()->subDays(2)->toDateString(), $owner);
        $done = $this->action($case, 'Kurs for de ansatte', now()->subDays(5)->toDateString(), null);
        $completion = $this->complete($done, $verifier, now()->subDays(4));
        $verification = ImprovementActionVerification::query()->create([
            'customer_id' => $customer->id,
            'improvement_action_id' => $done->id,
            'completion_status_change_id' => $completion->id,
            'result' => ImprovementActionVerification::RESULT_NOT_EFFECTIVE,
            'note' => 'FRITEKST-VERIFISERING',
            'verified_by_user_id' => $verifier->id,
            'verified_at' => now()->subDays(1),
        ]);
        $this->grantReader($client, $customer, [$area]);

        $response = $this->get("/api/v1/deviations/{$case->id}", $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [...self::ROW_KEYS, 'actions' => ['*' => self::ACTION_KEYS]],
            'meta' => ['as_of', 'request_id'],
        ]);
        $response->assertJsonPath('data.id', $case->id);
        $response->assertJsonPath('data.follow_up', ['actions_total' => 2, 'actions_open' => 1, 'attention' => ['action_overdue', 'not_effective']]);
        $response->assertJsonPath('data.actions', [
            [
                'id' => $late->id,
                'title' => 'Oppdater rutinen',
                'status' => 'planned',
                'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
                'due_on' => now()->subDays(2)->toDateString(),
                'is_overdue' => true,
                'completed_at' => null,
                'verification' => ['awaiting' => false, 'current' => null, 'earlier' => []],
            ],
            [
                'id' => $done->id,
                'title' => 'Kurs for de ansatte',
                'status' => 'completed',
                'owner' => null,
                'due_on' => now()->subDays(5)->toDateString(),
                'is_overdue' => false,
                'completed_at' => $done->fresh()->completed_at->utc()->toIso8601ZuluString(),
                'verification' => [
                    'awaiting' => false,
                    'current' => ['id' => $verification->id, 'result' => 'not_effective', 'verified_at' => $verification->verified_at->utc()->toIso8601ZuluString()],
                    'earlier' => [],
                ],
            ],
        ]);

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Vera', 'description', 'cause', 'note', 'history', 'quality_context', 'origin', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_one_forbedring_is_read_on_its_own_endpoint_and_each_type_only_on_its_own(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $improvement = $this->case($customer, $area, ImprovementCase::TYPE_IMPROVEMENT, 'Forbedring');
        $deviation = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, 'Avvik');
        $completed = $this->action($improvement, 'Ferdig tiltak', now()->subDay()->toDateString(), null);
        $this->complete($completed, $this->person($customer, 'Person'), now()->subHour());
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get("/api/v1/improvements/{$improvement->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $improvement->id)
            ->assertJsonPath('data.actions.0.verification', ['awaiting' => true, 'current' => null, 'earlier' => []])
            // No ansvarlig on the case, and a completed tiltak not yet verified.
            ->assertJsonPath('data.follow_up.attention', ['case_owner_missing', 'awaiting_verification']);

        // The other type is not there, exactly like a case the identity cannot see.
        $this->get("/api/v1/deviations/{$improvement->id}", $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get("/api/v1/improvements/{$deviation->id}", $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_case_of_another_customer_outside_the_fagomrader_or_not_there_is_not_found(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $drift = $this->businessArea($customer, 'Drift');
        $other = $this->apiCustomer('Annen kunde');
        $this->grantReader($client, $customer, [$drift]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach ([ImprovementCase::TYPE_DEVIATION => 'deviations', ImprovementCase::TYPE_IMPROVEMENT => 'improvements'] as $type => $path) {
            $outside = $this->case($customer, $this->businessArea($customer, 'Økonomi '.$type), $type, 'Utenfor fagområdet');
            $foreign = $this->case($other, $this->businessArea($other, 'Drift '.$type), $type, 'Annen kundes sak');

            foreach ([$outside->id, $foreign->id, 999999999] as $id) {
                $response = $this->get("/api/v1/{$path}/{$id}", $headers);
                $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
                $this->assertStringNotContainsString('sak', (string) $response->getContent());
            }

            $this->assertSame([], $this->ids($this->get("/api/v1/{$path}", $headers)->json()));
            $this->get("/api/v1/{$path}/{$foreign->id}", $this->asCustomer($this->accessToken(), $other))->assertForbidden();
        }
    }

    public function test_without_improvement_view_the_module_an_active_grant_or_an_enabled_client_it_is_refused(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $case = $this->case($customer, $area, ImprovementCase::TYPE_IMPROVEMENT, 'Hemmelig');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::IMPROVEMENT_EDIT, CustomerPermissionCatalog::IMPROVEMENT_CLOSE]);
        $grant = $this->grantTo($client, $customer, $identity);

        foreach (['/api/v1/improvements', "/api/v1/improvements/{$case->id}"] as $url) {
            $response = $this->get($url, $this->asCustomer($this->accessToken(), $customer));
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
        }

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->get("/api/v1/improvements/{$case->id}", $this->asCustomer($this->accessToken(), $customer))->assertForbidden();

        $withoutModule = $this->apiCustomer('Uten moduler', withRisk: false);
        $hidden = $this->case($withoutModule, $this->businessArea($withoutModule, 'Drift'), ImprovementCase::TYPE_IMPROVEMENT, 'Hemmelig');
        $this->grantReader($client, $withoutModule);

        foreach (['/api/v1/improvements', "/api/v1/improvements/{$hidden->id}", "/api/v1/deviations/{$hidden->id}"] as $url) {
            $this->get($url, $this->asCustomer($this->accessToken(), $withoutModule))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }

        $enabled = $this->apiCustomer('Aktiv');
        $readable = $this->case($enabled, $this->businessArea($enabled, 'Drift'), ImprovementCase::TYPE_IMPROVEMENT, 'Sak');
        $this->grantReader($client, $enabled);
        $client->forceFill(['is_enabled' => false])->save();
        $this->get("/api/v1/improvements/{$readable->id}", $this->asCustomer($this->accessToken(), $enabled))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_reading_cases_writes_nothing_and_accepts_no_write(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $owner = $this->person($customer, 'Eva Eier');
        $improvement = $this->case($customer, $area, ImprovementCase::TYPE_IMPROVEMENT, 'Uendret', ['due_date' => now()->subDay()->toDateString()]);
        $deviation = $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, 'Uendret avvik');
        $this->complete($this->action($deviation, 'Tiltak', now()->subDays(3)->toDateString(), $owner), $owner, now()->subDay());
        $this->grantReader($client, $customer, permissions: [
            CustomerPermissionCatalog::IMPROVEMENT_VIEW,
            CustomerPermissionCatalog::IMPROVEMENT_EDIT,
            CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        ]);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        foreach (['/api/v1/improvements', "/api/v1/improvements/{$improvement->id}", '/api/v1/deviations', "/api/v1/deviations/{$deviation->id}"] as $url) {
            $this->get($url, $headers)->assertOk();
        }

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['/api/v1/improvements', "/api/v1/improvements/{$improvement->id}"] as $url) {
                $this->{$method.'Json'}($url, ['status' => 'closed'], $headers)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
            }
        }

        $this->assertSame($before, $this->domainState($customer));
    }

    /**
     * @param  list<BusinessArea>|null  $areas
     * @param  list<string>  $permissions
     */
    private function grantReader($client, Customer $customer, ?array $areas = null, array $permissions = [CustomerPermissionCatalog::IMPROVEMENT_VIEW]): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, $permissions, $areas);
        $this->grantTo($client, $customer, $identity);

        return $identity;
    }

    /** @param  array<string, mixed>  $overrides */
    private function case(Customer $customer, BusinessArea $area, string $type, string $title, array $overrides = []): ImprovementCase
    {
        return ImprovementCase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => $type,
            'title' => $title,
            'description' => 'Beskrivelse',
        ], $overrides));
    }

    private function action(ImprovementCase $case, string $title, string $dueDate, ?User $owner): ImprovementAction
    {
        return ImprovementAction::query()->create([
            'customer_id' => $case->customer_id,
            'improvement_case_id' => $case->id,
            'title' => $title,
            'description' => 'FRITEKST-TILTAKSBESKRIVELSE',
            'owner_user_id' => $owner?->id,
            'due_date' => $dueDate,
        ]);
    }

    /** Completed as the case page records it: the status, and the completion in the history. */
    private function complete(ImprovementAction $action, User $by, $at): ImprovementActionStatusChange
    {
        $action->forceFill([
            'status' => ImprovementAction::STATUS_COMPLETED,
            'completed_at' => $at,
            'completed_by_user_id' => $by->id,
            'completion_note' => 'FRITEKST-RESULTAT',
        ])->save();

        return ImprovementActionStatusChange::query()->create([
            'customer_id' => $action->customer_id,
            'improvement_action_id' => $action->id,
            'from_status' => ImprovementAction::STATUS_PLANNED,
            'to_status' => ImprovementAction::STATUS_COMPLETED,
            'note' => 'FRITEKST-NOTAT',
            'changed_by_user_id' => $by->id,
            'changed_at' => $at,
        ]);
    }

    private function person(Customer $customer, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'person-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> every Avvik og forbedringer row of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['improvement_cases', 'improvement_actions', 'improvement_action_status_changes', 'improvement_action_verifications', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<int>
     */
    private function ids(array $json): array
    {
        return array_map(fn (array $row): int => (int) $row['id'], $json['data'] ?? []);
    }
}
