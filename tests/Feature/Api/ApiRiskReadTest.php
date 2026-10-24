<?php

namespace Tests\Feature\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\Risk;
use App\Models\RiskAssessment;
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
 * GET /api/v1/risks: the integration identity reads exactly what RiskAccessService lets it read —
 * its customer, the risk.view permission and its fagområder — in structured fields only.
 */
class ApiRiskReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ROW_KEYS = [
        'id', 'title', 'status', 'treatment_strategy', 'business_area', 'owner', 'inherent', 'residual',
        'last_assessed_on', 'review_interval_months', 'next_review_on', 'is_review_overdue', 'attention', 'updated_at',
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

    public function test_a_risk_is_presented_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $owner = $this->person($customer, 'Eva Eier');
        $risk = Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'title' => 'Strømbrudd i datasenter',
            'cause' => 'FRITEKST-ÅRSAK',
            'event' => 'FRITEKST-HENDELSE',
            'consequence' => 'FRITEKST-KONSEKVENS',
            'description' => 'FRITEKST-UTFYLLENDE',
            'status' => Risk::STATUS_IN_TREATMENT,
            'treatment_strategy' => Risk::TREATMENT_REDUCE,
            'review_interval_months' => 3,
        ]);
        $this->assess($risk, $owner, now()->subMonths(4), [4, 4], [2, 3], 'FRITEKST-BEGRUNNELSE');
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [self::ROW_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $response->assertJsonPath('data.0', [
            'id' => $risk->id,
            'title' => 'Strømbrudd i datasenter',
            'status' => 'in_treatment',
            'treatment_strategy' => 'reduce',
            'business_area' => ['id' => $area->id, 'name' => 'Beredskap'],
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
            'inherent' => ['likelihood' => 4, 'consequence' => 4, 'score' => 16, 'level' => $response->json('data.0.inherent.level')],
            'residual' => ['likelihood' => 2, 'consequence' => 3, 'score' => 6, 'level' => $response->json('data.0.residual.level')],
            'last_assessed_on' => now()->subMonths(4)->toDateString(),
            'review_interval_months' => 3,
            'next_review_on' => now()->subMonths(4)->addMonthsNoOverflow(3)->toDateString(),
            'is_review_overdue' => true,
            // RiskAttentionService's codes, as on the register's «Trenger oppmerksomhet».
            'attention' => ['review_overdue'],
            'updated_at' => $risk->updated_at->utc()->toIso8601ZuluString(),
        ]);
        $this->assertNotNull($response->json('data.0.inherent.level'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('FRITEKST', $body);
        $this->assertStringNotContainsString('/app/', $body);
    }

    public function test_an_unassessed_risk_has_no_scoring_and_is_not_overdue(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $this->risk($customer, $this->businessArea($customer, 'Drift'), 'Ny risiko');
        $this->grantReader($client, $customer);

        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer))
            ->assertOk()
            ->assertJsonPath('data.0.owner', null)
            ->assertJsonPath('data.0.inherent', null)
            ->assertJsonPath('data.0.residual', null)
            ->assertJsonPath('data.0.next_review_on', null)
            ->assertJsonPath('data.0.is_review_overdue', false)
            ->assertJsonPath('data.0.attention', ['not_assessed']);
    }

    public function test_the_identity_reads_only_its_fagomrader(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $beredskap = $this->businessArea($customer, 'Beredskap');
        $okonomi = $this->businessArea($customer, 'Økonomi');
        $visible = $this->risk($customer, $beredskap, 'Synlig');
        $this->risk($customer, $okonomi, 'Skjult');
        $this->grantReader($client, $customer, [$beredskap]);
        $token = $this->accessToken();

        $this->assertSame([$visible->id], $this->ids($this->get('/api/v1/risks', $this->asCustomer($token, $customer))->json()));
        $this->assertSame(
            [],
            $this->ids($this->get('/api/v1/risks?business_area_id='.$okonomi->id, $this->asCustomer($token, $customer))->json()),
        );
    }

    public function test_one_application_reads_each_customers_risks_and_nothing_across(): void
    {
        $client = $this->apiClient();
        $first = $this->apiCustomer('Første AS');
        $second = $this->apiCustomer('Andre AS');
        $firstArea = $this->businessArea($first, 'Beredskap');
        $secondArea = $this->businessArea($second, 'Beredskap');
        $firstRisks = [$this->risk($first, $firstArea, 'A1')->id, $this->risk($first, $firstArea, 'A2')->id];
        $secondRisks = [$this->risk($second, $secondArea, 'B1')->id, $this->risk($second, $secondArea, 'B2')->id];
        $this->grantReader($client, $first);
        $this->grantReader($client, $second);
        $token = $this->accessToken();

        $this->assertSame($firstRisks, $this->ids($this->get('/api/v1/risks', $this->asCustomer($token, $first))->json()));
        $this->assertSame($secondRisks, $this->ids($this->get('/api/v1/risks', $this->asCustomer($token, $second))->json()));

        // Another customer's fagområde as a filter finds nothing.
        $this->assertSame(
            [],
            $this->ids($this->get('/api/v1/risks?business_area_id='.$secondArea->id, $this->asCustomer($token, $first))->json()),
        );

        // A cursor taken from one customer's list only continues the other customer's own list.
        $cursor = $this->get('/api/v1/risks?limit=1', $this->asCustomer($token, $second))->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $continued = $this->ids($this->get('/api/v1/risks?cursor='.$cursor, $this->asCustomer($token, $first))->json());
        $this->assertSame([], array_values(array_intersect($continued, $secondRisks)));
    }

    public function test_without_risk_view_or_the_module_the_list_is_forbidden(): void
    {
        $client = $this->apiClient();

        $customer = $this->apiCustomer();
        $this->risk($customer, $this->businessArea($customer, 'Beredskap'), 'Hemmelig');
        $identity = $this->integrationIdentity($customer);
        // Every risk key but risk.view: nothing to read with.
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_EDIT, CustomerPermissionCatalog::RISK_CREATE]);
        $this->grantTo($client, $customer, $identity);

        $response = $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer));
        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());

        $withoutModule = $this->apiCustomer('Uten Risiko', withRisk: false);
        $this->risk($withoutModule, $this->businessArea($withoutModule, 'Beredskap'), 'Hemmelig');
        $this->grantReader($client, $withoutModule);

        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $withoutModule))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_the_list_is_paged_with_an_opaque_cursor(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $ids = array_map(fn (int $n): int => $this->risk($customer, $area, "Risiko {$n}")->id, range(1, 5));
        $this->grantReader($client, $customer);
        $token = $this->accessToken();

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->get('/api/v1/risks?limit=2'.($cursor !== null ? '&cursor='.$cursor : ''), $this->asCustomer($token, $customer));
            $response->assertOk()->assertJsonPath('meta.limit', 2);
            $seen = [...$seen, ...$this->ids($response->json())];
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame($ids, $seen);
        $this->assertSame(3, $pages);
    }

    public function test_invalid_query_values_are_refused(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $this->grantReader($client, $customer);
        $token = $this->accessToken();

        foreach ([
            'limit=0' => 'limit',
            'limit=201' => 'limit',
            'status=deleted' => 'status',
            'business_area_id=abc' => 'business_area_id',
            'cursor=not*valid' => 'cursor',
            'cursor='.rtrim(base64_encode('{"after":"1 OR 1=1"}'), '=') => 'cursor',
            'cursor='.rtrim(base64_encode('{"after":5,"customer_id":1}'), '=') => 'cursor',
        ] as $query => $field) {
            $response = $this->get('/api/v1/risks?'.$query, $this->asCustomer($token, $customer));
            $response->assertStatus(422);
            $response->assertJsonPath('error.code', 'validation_failed');
            $response->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $this->risk($customer, $area, 'Åpen');
        $closed = $this->risk($customer, $area, 'Lukket', Risk::STATUS_CLOSED);
        $this->grantReader($client, $customer);

        $this->assertSame(
            [$closed->id],
            $this->ids($this->get('/api/v1/risks?status=closed', $this->asCustomer($this->accessToken(), $customer))->json()),
        );
    }

    public function test_the_api_cannot_write_and_makes_no_outbound_call_but_the_signing_keys(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $risk = $this->risk($customer, $this->businessArea($customer, 'Beredskap'), 'Uendret');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/risks', $headers)->assertOk();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/v1/risks', ['title' => 'Endret'], $headers)
                ->assertStatus(405)
                ->assertJsonPath('error.code', 'method_not_allowed');
            // GET /risks/{id} reads one risk; nothing else is accepted there.
            $this->{$method.'Json'}('/api/v1/risks/'.$risk->id, ['title' => 'Endret'], $headers)
                ->assertStatus(405)
                ->assertJsonPath('error.code', 'method_not_allowed');
        }

        $this->assertSame('Uendret', $risk->fresh()->title);
        $this->assertSame(1, Risk::query()->where('customer_id', $customer->id)->count());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/discovery/v2.0/keys'));
    }

    public function test_the_request_log_counts_the_rows_returned(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $this->risk($customer, $area, 'En');
        $this->risk($customer, $area, 'To');
        $this->grantReader($client, $customer);

        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer))->assertOk();

        $line = collect($this->logged)->firstWhere('context.event', 'api_request');
        $this->assertSame('api.v1.risks.index', $line['context']['route']);
        $this->assertSame(2, $line['context']['rows']);
        $this->assertStringNotContainsString('Beredskap', (string) json_encode($this->logged));
    }

    /** @param  list<BusinessArea>|null  $areas */
    private function grantReader($client, Customer $customer, ?array $areas = null): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW], $areas);
        $this->grantTo($client, $customer, $identity);

        return $identity;
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

    /**
     * @param  array{0: int, 1: int}  $inherent
     * @param  array{0: int, 1: int}|null  $residual
     */
    private function assess(Risk $risk, User $assessor, $at, array $inherent, ?array $residual, string $rationale): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_by' => $assessor->id,
            'assessed_at' => $at,
            'rationale' => $rationale,
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => $inherent[0],
            'inherent_consequence' => $inherent[1],
            'residual_likelihood' => $residual[0] ?? null,
            'residual_consequence' => $residual[1] ?? null,
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
