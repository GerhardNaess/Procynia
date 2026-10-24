<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * GET /api/v1/deviations: the integration identity reads exactly the avvik ImprovementCaseAccess-
 * Service lets it read — its customer, improvement.view and its fagområder — in structured fields
 * only. Simulated Entra tokens and synthetic data; nothing reaches Entra or an AI provider.
 */
class ApiDeviationReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ROW_KEYS = [
        'id', 'title', 'status', 'business_area', 'owner', 'occurred_on', 'due_on', 'is_overdue',
        'closed_at', 'follow_up', 'created_at', 'updated_at',
    ];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        $this->fakeEntraSigningKeys();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_avvik_is_presented_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $owner = $this->person($customer, 'Eva Eier');
        $reporter = $this->person($customer, 'Rolf Rapportør');
        $case = $this->deviation($customer, $area, 'Feil i rutine', [
            'owner_user_id' => $owner->id,
            'reported_by_user_id' => $reporter->id,
            'description' => 'FRITEKST-BESKRIVELSE',
            'cause_analysis' => 'FRITEKST-ÅRSAK',
            'occurred_at' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        $this->action($case, 'FRITEKST-TILTAK', now()->subDay()->toDateString(), $owner);
        $this->action($case, 'Annet tiltak', now()->addMonth()->toDateString(), $owner);
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/deviations', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [self::ROW_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $response->assertJsonPath('data.0', [
            'id' => $case->id,
            'title' => 'Feil i rutine',
            'status' => 'open',
            'business_area' => ['id' => $area->id, 'name' => 'Drift'],
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
            'occurred_on' => now()->subDays(20)->toDateString(),
            'due_on' => now()->subDays(2)->toDateString(),
            'is_overdue' => true,
            'closed_at' => null,
            'follow_up' => ['actions_total' => 2, 'actions_open' => 2, 'attention' => ['case_overdue', 'action_overdue']],
            'created_at' => $case->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $case->updated_at->utc()->toIso8601ZuluString(),
        ]);

        $body = (string) $response->getContent();
        foreach (['FRITEKST', 'Rolf', 'Rapportør', '/app/', 'description', 'cause', 'closing_note', 'reported_by'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_only_avvik_are_listed_never_forbedringer(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $deviation = $this->deviation($customer, $area, 'Avvik');
        $this->deviation($customer, $area, 'Forbedring', ['type' => ImprovementCase::TYPE_IMPROVEMENT]);
        $this->grantReader($client, $customer);

        $this->assertSame([$deviation->id], $this->ids($this->get('/api/v1/deviations', $this->asCustomer($this->accessToken(), $customer))->json()));
    }

    public function test_an_ended_avvik_has_no_follow_up_and_is_never_overdue(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $closed = $this->deviation($customer, $area, 'Lukket', ['due_date' => now()->subMonth()->toDateString()]);
        $closed->forceFill([
            'status' => ImprovementCase::STATUS_CLOSED,
            'closed_at' => now()->subDay(),
            'closing_note' => 'FRITEKST-AVSLUTNING',
        ])->save();
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/deviations', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'closed')
            ->assertJsonPath('data.0.is_overdue', false)
            ->assertJsonPath('data.0.follow_up.attention', [])
            ->assertJsonPath('data.0.closed_at', $closed->fresh()->closed_at->utc()->toIso8601ZuluString());
        $this->assertStringNotContainsString('FRITEKST', (string) $response->getContent());
    }

    public function test_the_identity_reads_only_its_fagomrader(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $drift = $this->businessArea($customer, 'Drift');
        $hr = $this->businessArea($customer, 'HR');
        $visible = $this->deviation($customer, $drift, 'Synlig');
        $this->deviation($customer, $hr, 'Skjult');
        $this->grantReader($client, $customer, [$drift]);
        $token = $this->accessToken();

        $this->assertSame([$visible->id], $this->ids($this->get('/api/v1/deviations', $this->asCustomer($token, $customer))->json()));
        $this->assertSame([], $this->ids($this->get('/api/v1/deviations?business_area_id='.$hr->id, $this->asCustomer($token, $customer))->json()));
        $this->get('/api/v1/me', $this->asCustomer($token, $customer))
            ->assertJsonPath('data.modules.deviations', ['entitled' => true, 'readable' => true, 'business_areas' => [['id' => $drift->id, 'name' => 'Drift']]]);
    }

    public function test_one_application_reads_each_customers_avvik_and_nothing_across(): void
    {
        $client = $this->apiClient();
        $first = $this->apiCustomer('Første AS');
        $second = $this->apiCustomer('Andre AS');
        $firstArea = $this->businessArea($first, 'Drift');
        $secondArea = $this->businessArea($second, 'Drift');
        $firstIds = [$this->deviation($first, $firstArea, 'A1')->id, $this->deviation($first, $firstArea, 'A2')->id];
        $secondIds = [$this->deviation($second, $secondArea, 'B1')->id, $this->deviation($second, $secondArea, 'B2')->id];
        $this->grantReader($client, $first);
        $this->grantReader($client, $second);
        $token = $this->accessToken();

        $this->assertSame($firstIds, $this->ids($this->get('/api/v1/deviations', $this->asCustomer($token, $first))->json()));
        $this->assertSame($secondIds, $this->ids($this->get('/api/v1/deviations', $this->asCustomer($token, $second))->json()));

        // Another customer's fagområde as a filter finds nothing.
        $this->assertSame([], $this->ids($this->get('/api/v1/deviations?business_area_id='.$secondArea->id, $this->asCustomer($token, $first))->json()));

        // A cursor taken from one customer's list only continues the other customer's own list.
        $cursor = $this->get('/api/v1/deviations?limit=1', $this->asCustomer($token, $second))->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $continued = $this->ids($this->get('/api/v1/deviations?cursor='.$cursor, $this->asCustomer($token, $first))->json());
        $this->assertSame([], array_values(array_intersect($continued, $secondIds)));

        // A customer the application holds no grant for is refused, whatever its id.
        $third = $this->apiCustomer('Tredje AS');
        $this->deviation($third, $this->businessArea($third, 'Drift'), 'C1');
        $this->get('/api/v1/deviations', $this->asCustomer($token, $third))->assertForbidden();
    }

    public function test_without_improvement_view_or_the_module_the_list_is_forbidden(): void
    {
        $client = $this->apiClient();

        $customer = $this->apiCustomer();
        $this->deviation($customer, $this->businessArea($customer, 'Drift'), 'Hemmelig');
        $identity = $this->integrationIdentity($customer);
        // Every improvement key but improvement.view, and read access to another module.
        $this->giveRole($identity, [
            CustomerPermissionCatalog::IMPROVEMENT_EDIT,
            CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
            CustomerPermissionCatalog::IMPROVEMENT_DELETE,
            CustomerPermissionCatalog::RISK_VIEW,
        ]);
        $this->grantTo($client, $customer, $identity);

        $response = $this->get('/api/v1/deviations', $this->asCustomer($this->accessToken(), $customer));
        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());

        // A customer without the module — nothing ordered — even with improvement.view.
        $withoutModule = $this->apiCustomer('Uten moduler', withRisk: false);
        $this->deviation($withoutModule, $this->businessArea($withoutModule, 'Drift'), 'Hemmelig');
        $this->grantReader($client, $withoutModule);

        $this->get('/api/v1/deviations', $this->asCustomer($this->accessToken(), $withoutModule))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $withoutModule))
            ->assertJsonPath('data.modules.deviations', ['entitled' => false, 'readable' => false, 'business_areas' => []]);
    }

    public function test_filters_narrow_the_list(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $drift = $this->businessArea($customer, 'Drift');
        $hr = $this->businessArea($customer, 'HR');
        $overdue = $this->deviation($customer, $drift, 'Forfalt', ['due_date' => now()->subDay()->toDateString()]);
        $dueToday = $this->deviation($customer, $drift, 'Frist i dag', ['due_date' => now()->toDateString()]);
        $noDue = $this->deviation($customer, $hr, 'Uten frist');
        $inProgress = $this->deviation($customer, $hr, 'Under arbeid');
        $inProgress->forceFill(['status' => ImprovementCase::STATUS_IN_PROGRESS])->save();
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->assertSame([$overdue->id], $this->ids($this->get('/api/v1/deviations?overdue=true', $headers)->json()));
        $this->assertSame([$dueToday->id, $noDue->id, $inProgress->id], $this->ids($this->get('/api/v1/deviations?overdue=false', $headers)->json()));
        $this->assertSame([$inProgress->id], $this->ids($this->get('/api/v1/deviations?status=in_progress', $headers)->json()));
        $this->assertSame([$noDue->id, $inProgress->id], $this->ids($this->get('/api/v1/deviations?business_area_id='.$hr->id, $headers)->json()));
    }

    public function test_the_list_is_paged_with_an_opaque_cursor(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $ids = array_map(fn (int $n): int => $this->deviation($customer, $area, "Avvik {$n}")->id, range(1, 5));
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->get('/api/v1/deviations?limit=2'.($cursor !== null ? '&cursor='.$cursor : ''), $headers);
            $response->assertOk()->assertJsonPath('meta.limit', 2);
            $seen = [...$seen, ...$this->ids($response->json())];
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame($ids, $seen);
        $this->assertSame(3, $pages);
    }

    public function test_invalid_query_values_are_refused_in_the_api_format(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach ([
            'limit=0' => 'limit',
            'limit=201' => 'limit',
            'status=deleted' => 'status',
            'overdue=yes' => 'overdue',
            'business_area_id=abc' => 'business_area_id',
            'cursor=not*valid' => 'cursor',
            'cursor='.rtrim(base64_encode('{"after":5,"customer_id":1}'), '=') => 'cursor',
        ] as $query => $field) {
            $response = $this->get('/api/v1/deviations?'.$query, $headers);
            $response->assertStatus(422);
            $response->assertJsonPath('error.code', 'validation_failed');
            $response->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }
    }

    public function test_no_write_no_decision_and_no_outbound_call_but_the_signing_keys(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $case = $this->deviation($customer, $this->businessArea($customer, 'Drift'), 'Uendret');
        $this->grantReader($client, $customer, permissions: [
            CustomerPermissionCatalog::IMPROVEMENT_VIEW,
            CustomerPermissionCatalog::IMPROVEMENT_EDIT,
            CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        ]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/deviations', $headers)->assertOk();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/v1/deviations', ['title' => 'Endret'], $headers)
                ->assertStatus(405)
                ->assertJsonPath('error.code', 'method_not_allowed');

            // GET /deviations/{id} reads one avvik; nothing else is accepted there.
            $this->{$method.'Json'}("/api/v1/deviations/{$case->id}", ['closing_note' => 'Lukket av maskin'], $headers)
                ->assertStatus(405)
                ->assertJsonPath('error.code', 'method_not_allowed');

            foreach (['/close', '/cancel', '/start', '/reopen'] as $suffix) {
                $this->{$method.'Json'}("/api/v1/deviations/{$case->id}{$suffix}", ['closing_note' => 'Lukket av maskin'], $headers)->assertNotFound();
            }
        }

        $this->assertSame(['Uendret', ImprovementCase::STATUS_OPEN], [$case->fresh()->title, $case->fresh()->status]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/discovery/v2.0/keys'));
    }

    public function test_the_request_log_counts_the_rows_and_holds_no_content(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $this->deviation($customer, $area, 'En');
        $this->deviation($customer, $area, 'To');
        $this->grantReader($client, $customer);

        $this->get('/api/v1/deviations?status=open', $this->asCustomer($this->accessToken(), $customer))->assertOk();

        $line = collect($this->logged)->firstWhere('context.event', 'api_request');
        $this->assertSame('api.v1.deviations.index', $line['context']['route']);
        $this->assertSame(2, $line['context']['rows']);
        $this->assertStringNotContainsString('Drift', (string) json_encode($this->logged));
    }

    /**
     * @param  list<BusinessArea>|null  $areas
     * @param  list<string>  $permissions
     */
    private function grantReader(ApiClient $client, Customer $customer, ?array $areas = null, array $permissions = [CustomerPermissionCatalog::IMPROVEMENT_VIEW]): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, $permissions, $areas);
        $this->grantTo($client, $customer, $identity);

        return $identity;
    }

    /** @param  array<string, mixed>  $overrides */
    private function deviation(Customer $customer, BusinessArea $area, string $title, array $overrides = []): ImprovementCase
    {
        return ImprovementCase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => ImprovementCase::TYPE_DEVIATION,
            'title' => $title,
            'description' => 'Beskrivelse',
        ], $overrides));
    }

    private function action(ImprovementCase $case, string $title, string $dueDate, User $owner): ImprovementAction
    {
        return ImprovementAction::query()->create([
            'customer_id' => $case->customer_id,
            'improvement_case_id' => $case->id,
            'title' => $title,
            'owner_user_id' => $owner->id,
            'due_date' => $dueDate,
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

    /**
     * @param  array<string, mixed>  $json
     * @return list<int>
     */
    private function ids(array $json): array
    {
        return array_map(fn (array $row): int => (int) $row['id'], $json['data'] ?? []);
    }
}
