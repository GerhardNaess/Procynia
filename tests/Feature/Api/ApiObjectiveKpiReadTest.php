<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
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
 * GET /api/v1/objectives and GET /api/v1/kpis: the integration identity reads exactly the mål and
 * KPI-er ObjectiveAccessService lets it read — its customer, objective.view and its fagområder — in
 * structured fields only, with the results and attention codes Procynia already computes. Simulated
 * Entra tokens and synthetic data; nothing reaches Entra or an AI provider.
 */
class ApiObjectiveKpiReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const OBJECTIVE_KEYS = [
        'id', 'title', 'status', 'business_area', 'owner', 'target_date', 'closed_at', 'kpis', 'attention',
        'created_at', 'updated_at',
    ];

    private const KPI_KEYS = [
        'id', 'objective_id', 'business_area', 'title', 'status', 'owner', 'unit', 'unit_label', 'currency_code',
        'target', 'baseline', 'frequency', 'reporting_grace_days', 'latest_measurement', 'result', 'measurements',
        'attention', 'created_at', 'updated_at',
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

    public function test_a_mal_is_presented_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Salg');
        $owner = $this->person($customer, 'Eva Eier');
        $objective = $this->objective($customer, $area, 'Økt kundetilfredshet', [
            'owner_user_id' => $owner->id,
            'description' => 'FRITEKST-BESKRIVELSE',
            'target_date' => now()->subDays(3)->toDateString(),
        ]);
        $this->kpi($objective, 'Aktiv KPI', ['target_min' => '95']);
        $this->kpi($objective, 'Avsluttet KPI')->forceFill(['status' => Kpi::STATUS_RETIRED])->save();
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/objectives', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [self::OBJECTIVE_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $response->assertJsonPath('data.0', [
            'id' => $objective->id,
            'title' => 'Økt kundetilfredshet',
            'status' => 'active',
            'business_area' => ['id' => $area->id, 'name' => 'Salg'],
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
            'target_date' => now()->subDays(3)->toDateString(),
            'closed_at' => null,
            // Retired KPIs count in total, never in the «på mål» indicator.
            'kpis' => ['total' => 2, 'active' => 1, 'on_target' => 0, 'needing_attention' => 0],
            'attention' => ['target_date_passed'],
            'created_at' => $objective->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $objective->updated_at->utc()->toIso8601ZuluString(),
        ]);

        $this->assertStringNotContainsString('FRITEKST', (string) $response->getContent());
        $this->assertStringNotContainsString('/app/', (string) $response->getContent());
    }

    public function test_a_closed_mal_shows_when_it_ended_but_not_why_or_by_whom(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $closer = $this->person($customer, 'Lukker Lise');
        $objective = $this->objective($customer, $this->businessArea($customer, 'Salg'), 'Nådd mål', [
            'target_date' => now()->subMonth()->toDateString(),
        ]);
        $objective->forceFill([
            'status' => Objective::STATUS_ACHIEVED,
            'closed_at' => now()->subDay(),
            'closed_by_user_id' => $closer->id,
            'closing_note' => 'FRITEKST-AVSLUTNING',
        ])->save();
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/objectives', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'achieved')
            ->assertJsonPath('data.0.owner', null)
            // A closed objective has no attention, a passed måldato included.
            ->assertJsonPath('data.0.attention', [])
            ->assertJsonPath('data.0.closed_at', $objective->fresh()->closed_at->utc()->toIso8601ZuluString());

        foreach (['FRITEKST', 'Lukker', 'Lise', 'closing_note', 'closed_by'] as $absent) {
            $this->assertStringNotContainsString($absent, (string) $response->getContent());
        }
    }

    public function test_a_kpi_carries_its_target_its_counted_measurements_and_the_results_procynia_computes(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $owner = $this->person($customer, 'Eva Eier');
        $recorder = $this->person($customer, 'Registrerer Rune');
        $start = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(3);
        $objective = $this->objective($customer, $area, 'Stabil drift', ['owner_user_id' => $owner->id]);
        $kpi = $this->kpi($objective, 'Oppetid', [
            'description' => 'FRITEKST-KPI',
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '95',
            'tolerance' => '2',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 0,
        ]);
        $this->backdate($objective, $kpi, $start);

        $first = $start;
        $second = $start->addMonthNoOverflow();
        // A correction: the later registration counts, the first is superseded and left out.
        $this->measurement($kpi, $first, '80', $recorder, $start->addDays(40), ['target_min' => '85']);
        $this->measurement($kpi, $first, '90', $recorder, $start->addDays(41), ['target_min' => '85']);
        // Registered against the target of the time (on target then), below today's (attention now).
        $this->measurement($kpi, $second, '94', $recorder, $start->addDays(70), ['target_min' => '85']);
        // A later row for the same period, withdrawn: it never counts and its reason never leaves.
        $this->measurement($kpi, $second, '50', $recorder, $start->addDays(71))->withdraw($recorder, 'FRITEKST-TILBAKETREKK');
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/kpis', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [self::KPI_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $secondPoint = [
            'period_start' => $second->toDateString(),
            'period_end' => $second->endOfMonth()->toDateString(),
            'value' => '94',
            'recorded_at' => $start->addDays(70)->utc()->toIso8601ZuluString(),
        ];
        $response->assertJsonPath('data.0', [
            'id' => $kpi->id,
            'objective_id' => $objective->id,
            'business_area' => ['id' => $area->id, 'name' => 'Drift'],
            'title' => 'Oppetid',
            'status' => 'active',
            // No owner of its own: the objective's owner answers for it.
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier', 'inherited' => true],
            'unit' => 'percent',
            'unit_label' => null,
            'currency_code' => null,
            'target' => ['type' => 'at_least', 'min' => '95', 'max' => null, 'tolerance' => '2'],
            'baseline' => null,
            'frequency' => 'monthly',
            'reporting_grace_days' => 0,
            'latest_measurement' => $secondPoint,
            'result' => 'attention',
            'measurements' => [
                [
                    'period_start' => $first->toDateString(),
                    'period_end' => $first->endOfMonth()->toDateString(),
                    'value' => '90',
                    'recorded_at' => $start->addDays(41)->utc()->toIso8601ZuluString(),
                    'result' => 'on_target',
                ],
                $secondPoint + ['result' => 'on_target'],
            ],
            // Below target now, and last month has no measurement past its deadline.
            'attention' => ['kpi_off_target', 'measurement_missing'],
            'created_at' => $kpi->fresh()->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $kpi->fresh()->updated_at->utc()->toIso8601ZuluString(),
        ]);

        foreach (['FRITEKST', 'Registrerer', 'Rune', 'comment', 'recorded_by', 'withdraw', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, (string) $response->getContent());
        }

        $this->get('/api/v1/objectives', $this->asCustomer($this->accessToken(), $customer))
            ->assertJsonPath('data.0.kpis', ['total' => 1, 'active' => 1, 'on_target' => 0, 'needing_attention' => 1])
            // A KPI finding never becomes the objective's own.
            ->assertJsonPath('data.0.attention', []);
    }

    public function test_a_kpi_with_its_own_owner_a_startverdi_and_no_measurement(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $kpiOwner = $this->person($customer, 'Kari KPI');
        $objective = $this->objective($customer, $this->businessArea($customer, 'Salg'), 'Vekst', [
            'owner_user_id' => $this->person($customer, 'Eva Eier')->id,
        ]);
        $this->kpi($objective, 'Omsetning', [
            'owner_user_id' => $kpiOwner->id,
            'unit' => Kpi::UNIT_CURRENCY,
            'currency_code' => 'NOK',
            'target_min' => '1300000',
            'baseline_value' => '1000000.5',
            'baseline_date' => '2026-01-01',
        ]);
        $this->grantReader($client, $customer);

        $this->get('/api/v1/kpis', $this->asCustomer($this->accessToken(), $customer))
            ->assertOk()
            ->assertJsonPath('data.0.owner', ['id' => $kpiOwner->id, 'name' => 'Kari KPI', 'inherited' => false])
            ->assertJsonPath('data.0.currency_code', 'NOK')
            ->assertJsonPath('data.0.target', ['type' => 'at_least', 'min' => '1300000', 'max' => null, 'tolerance' => null])
            ->assertJsonPath('data.0.baseline', ['value' => '1000000.5', 'date' => '2026-01-01'])
            ->assertJsonPath('data.0.frequency', null)
            ->assertJsonPath('data.0.latest_measurement', null)
            ->assertJsonPath('data.0.result', 'not_measured')
            ->assertJsonPath('data.0.measurements', [])
            ->assertJsonPath('data.0.attention', []);
    }

    public function test_measurements_are_bounded_to_the_latest_periods(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $objective = $this->objective($customer, $this->businessArea($customer, 'Drift'), 'Lang historikk');
        $kpi = $this->kpi($objective, 'Månedlig', ['frequency' => Kpi::FREQUENCY_MONTHLY]);
        $first = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(30);
        $this->backdate($objective, $kpi, $first);

        foreach (range(0, 29) as $offset) {
            $this->measurement($kpi, $first->addMonthsNoOverflow($offset), (string) $offset, null, $first->addMonthsNoOverflow($offset + 1));
        }
        $this->grantReader($client, $customer);

        $points = $this->get('/api/v1/kpis', $this->asCustomer($this->accessToken(), $customer))->assertOk()->json('data.0.measurements');

        $this->assertCount(24, $points);
        $this->assertSame('6', $points[0]['value']);
        $this->assertSame('29', $points[23]['value']);
        $this->assertSame($first->addMonthsNoOverflow(29)->toDateString(), $points[23]['period_start']);
    }

    public function test_the_identity_reads_only_its_fagomrader(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $salg = $this->businessArea($customer, 'Salg');
        $hr = $this->businessArea($customer, 'HR');
        $visible = $this->objective($customer, $salg, 'Synlig');
        $hidden = $this->objective($customer, $hr, 'Skjult');
        $visibleKpi = $this->kpi($visible, 'Synlig KPI');
        $this->kpi($hidden, 'Skjult KPI');
        $this->grantReader($client, $customer, [$salg]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->assertSame([$visible->id], $this->ids($this->get('/api/v1/objectives', $headers)->json()));
        $this->assertSame([$visibleKpi->id], $this->ids($this->get('/api/v1/kpis', $headers)->json()));

        // Asking for the hidden fagområde or objective finds nothing.
        $this->assertSame([], $this->ids($this->get('/api/v1/objectives?business_area_id='.$hr->id, $headers)->json()));
        $this->assertSame([], $this->ids($this->get('/api/v1/kpis?business_area_id='.$hr->id, $headers)->json()));
        $this->assertSame([], $this->ids($this->get('/api/v1/kpis?objective_id='.$hidden->id, $headers)->json()));

        $response = $this->get('/api/v1/objectives', $headers);
        $this->assertStringNotContainsString('Skjult', (string) $response->getContent());

        $this->get('/api/v1/me', $headers)
            ->assertJsonPath('data.modules.objectives', ['entitled' => true, 'readable' => true, 'business_areas' => [['id' => $salg->id, 'name' => 'Salg']]]);
    }

    public function test_one_application_reads_each_customers_mal_and_kpis_and_nothing_across(): void
    {
        $client = $this->apiClient();
        $first = $this->apiCustomer('Første AS');
        $second = $this->apiCustomer('Andre AS');
        $firstArea = $this->businessArea($first, 'Salg');
        $secondArea = $this->businessArea($second, 'Salg');
        $firstObjectives = [$this->objective($first, $firstArea, 'A1'), $this->objective($first, $firstArea, 'A2')];
        $secondObjectives = [$this->objective($second, $secondArea, 'B1'), $this->objective($second, $secondArea, 'B2')];
        $firstKpis = array_map(fn (Objective $objective): int => $this->kpi($objective, 'KPI '.$objective->title)->id, $firstObjectives);
        $secondKpis = array_map(fn (Objective $objective): int => $this->kpi($objective, 'KPI '.$objective->title)->id, $secondObjectives);
        $this->grantReader($client, $first);
        $this->grantReader($client, $second);
        $token = $this->accessToken();

        $this->assertSame(array_map(fn (Objective $o): int => $o->id, $firstObjectives), $this->ids($this->get('/api/v1/objectives', $this->asCustomer($token, $first))->json()));
        $this->assertSame(array_map(fn (Objective $o): int => $o->id, $secondObjectives), $this->ids($this->get('/api/v1/objectives', $this->asCustomer($token, $second))->json()));
        $this->assertSame($firstKpis, $this->ids($this->get('/api/v1/kpis', $this->asCustomer($token, $first))->json()));
        $this->assertSame($secondKpis, $this->ids($this->get('/api/v1/kpis', $this->asCustomer($token, $second))->json()));

        // Another customer's fagområde or objective as a filter finds nothing.
        $this->assertSame([], $this->ids($this->get('/api/v1/objectives?business_area_id='.$secondArea->id, $this->asCustomer($token, $first))->json()));
        $this->assertSame([], $this->ids($this->get('/api/v1/kpis?business_area_id='.$secondArea->id, $this->asCustomer($token, $first))->json()));
        $this->assertSame([], $this->ids($this->get('/api/v1/kpis?objective_id='.$secondObjectives[0]->id, $this->asCustomer($token, $first))->json()));

        // A cursor taken from one customer's list only continues the other customer's own list.
        foreach (['objectives' => array_map(fn (Objective $o): int => $o->id, $secondObjectives), 'kpis' => $secondKpis] as $path => $secondIds) {
            $cursor = $this->get("/api/v1/{$path}?limit=1", $this->asCustomer($token, $second))->json('meta.next_cursor');
            $this->assertNotNull($cursor);
            $continued = $this->ids($this->get("/api/v1/{$path}?cursor={$cursor}", $this->asCustomer($token, $first))->json());
            $this->assertSame([], array_values(array_intersect($continued, $secondIds)));
        }

        // A customer the application holds no grant for is refused, whatever its id.
        $third = $this->apiCustomer('Tredje AS');
        $this->kpi($this->objective($third, $this->businessArea($third, 'Salg'), 'C1'), 'KPI C1');
        $this->get('/api/v1/objectives', $this->asCustomer($token, $third))->assertForbidden();
        $this->get('/api/v1/kpis', $this->asCustomer($token, $third))->assertForbidden();
    }

    public function test_without_objective_view_or_the_module_both_lists_are_forbidden(): void
    {
        $client = $this->apiClient();

        $customer = $this->apiCustomer();
        $this->kpi($this->objective($customer, $this->businessArea($customer, 'Salg'), 'Hemmelig'), 'Hemmelig KPI');
        $identity = $this->integrationIdentity($customer);
        // Every objective key but objective.view, and read access to another module.
        $this->giveRole($identity, [
            CustomerPermissionCatalog::OBJECTIVE_EDIT,
            CustomerPermissionCatalog::OBJECTIVE_MEASURE,
            CustomerPermissionCatalog::OBJECTIVE_DELETE,
            CustomerPermissionCatalog::RISK_VIEW,
        ]);
        $this->grantTo($client, $customer, $identity);

        foreach (['objectives', 'kpis'] as $path) {
            $response = $this->get("/api/v1/{$path}", $this->asCustomer($this->accessToken(), $customer));
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
        }

        // A customer without the module — nothing ordered — even with objective.view.
        $withoutModule = $this->apiCustomer('Uten moduler', withRisk: false);
        $this->kpi($this->objective($withoutModule, $this->businessArea($withoutModule, 'Salg'), 'Hemmelig'), 'Hemmelig KPI');
        $this->grantReader($client, $withoutModule);

        foreach (['objectives', 'kpis'] as $path) {
            $this->get("/api/v1/{$path}", $this->asCustomer($this->accessToken(), $withoutModule))
                ->assertForbidden()
                ->assertJsonPath('error.code', 'forbidden');
        }
        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $withoutModule))
            ->assertJsonPath('data.modules.objectives', ['entitled' => false, 'readable' => false, 'business_areas' => []]);
    }

    public function test_filters_narrow_the_lists(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $salg = $this->businessArea($customer, 'Salg');
        $hr = $this->businessArea($customer, 'HR');
        $active = $this->objective($customer, $salg, 'Aktiv');
        $cancelled = $this->objective($customer, $hr, 'Avbrutt');
        $cancelled->forceFill(['status' => Objective::STATUS_CANCELLED, 'closed_at' => now()])->save();
        $activeKpi = $this->kpi($active, 'Aktiv KPI');
        $retiredKpi = $this->kpi($active, 'Avsluttet KPI');
        $retiredKpi->forceFill(['status' => Kpi::STATUS_RETIRED])->save();
        $hrKpi = $this->kpi($cancelled, 'HR KPI');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->assertSame([$cancelled->id], $this->ids($this->get('/api/v1/objectives?status=cancelled', $headers)->json()));
        $this->assertSame([$active->id], $this->ids($this->get('/api/v1/objectives?business_area_id='.$salg->id, $headers)->json()));

        $this->assertSame([$activeKpi->id, $retiredKpi->id], $this->ids($this->get('/api/v1/kpis?objective_id='.$active->id, $headers)->json()));
        $this->assertSame([$retiredKpi->id], $this->ids($this->get('/api/v1/kpis?status=retired', $headers)->json()));
        $this->assertSame([$hrKpi->id], $this->ids($this->get('/api/v1/kpis?business_area_id='.$hr->id, $headers)->json()));
        $this->assertSame([$activeKpi->id], $this->ids($this->get('/api/v1/kpis?status=active&business_area_id='.$salg->id, $headers)->json()));
    }

    public function test_both_lists_are_paged_with_an_opaque_cursor(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Salg');
        $objectives = array_map(fn (int $n): Objective => $this->objective($customer, $area, "Mål {$n}"), range(1, 5));
        $kpis = array_map(fn (Objective $objective): int => $this->kpi($objective, 'KPI '.$objective->title)->id, $objectives);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach (['objectives' => array_map(fn (Objective $o): int => $o->id, $objectives), 'kpis' => $kpis] as $path => $ids) {
            $seen = [];
            $cursor = null;
            $pages = 0;

            do {
                $response = $this->get("/api/v1/{$path}?limit=2".($cursor !== null ? '&cursor='.$cursor : ''), $headers);
                $response->assertOk()->assertJsonPath('meta.limit', 2);
                $seen = [...$seen, ...$this->ids($response->json())];
                $cursor = $response->json('meta.next_cursor');
                $pages++;
            } while ($cursor !== null && $pages < 10);

            $this->assertSame($ids, $seen, $path);
            $this->assertSame(3, $pages, $path);
        }
    }

    public function test_invalid_query_values_are_refused_in_the_api_format(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $foreignCursor = rtrim(base64_encode('{"after":5,"customer_id":1}'), '=');

        foreach ([
            'objectives?limit=0' => 'limit',
            'objectives?limit=201' => 'limit',
            'objectives?status=retired' => 'status',
            'objectives?business_area_id=abc' => 'business_area_id',
            'objectives?cursor=not*valid' => 'cursor',
            'objectives?cursor='.$foreignCursor => 'cursor',
            'kpis?limit=201' => 'limit',
            'kpis?status=achieved' => 'status',
            'kpis?objective_id=0' => 'objective_id',
            'kpis?business_area_id=abc' => 'business_area_id',
            'kpis?cursor=not*valid' => 'cursor',
        ] as $query => $field) {
            $response = $this->get('/api/v1/'.$query, $headers);
            $response->assertStatus(422);
            $response->assertJsonPath('error.code', 'validation_failed');
            $response->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }
    }

    public function test_no_write_no_measurement_no_decision_and_no_outbound_call_but_the_signing_keys(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $objective = $this->objective($customer, $this->businessArea($customer, 'Salg'), 'Uendret');
        $kpi = $this->kpi($objective, 'Uendret KPI');
        $this->grantReader($client, $customer, permissions: [
            CustomerPermissionCatalog::OBJECTIVE_VIEW,
            CustomerPermissionCatalog::OBJECTIVE_EDIT,
            CustomerPermissionCatalog::OBJECTIVE_MEASURE,
        ]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/objectives', $headers)->assertOk();
        $this->get('/api/v1/kpis', $headers)->assertOk();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['objectives', 'kpis'] as $path) {
                $this->{$method.'Json'}("/api/v1/{$path}", ['title' => 'Endret'], $headers)
                    ->assertStatus(405)
                    ->assertJsonPath('error.code', 'method_not_allowed');
            }

            // GET reads one objective and one KPI's history there; nothing else is accepted.
            foreach (["objectives/{$objective->id}", "kpis/{$kpi->id}", "kpis/{$kpi->id}/measurements"] as $path) {
                $this->{$method.'Json'}("/api/v1/{$path}", ['value' => '1', 'period' => '2026-09'], $headers)
                    ->assertStatus(405)
                    ->assertJsonPath('error.code', 'method_not_allowed');
            }

            foreach ([
                "objectives/{$objective->id}/close", "objectives/{$objective->id}/reopen",
                "objectives/{$objective->id}/kpis", "kpis/{$kpi->id}/retire",
            ] as $path) {
                $this->{$method.'Json'}("/api/v1/{$path}", ['value' => '1', 'period' => '2026-09'], $headers)->assertNotFound();
            }
        }

        $this->assertSame(['Uendret', Objective::STATUS_ACTIVE], [$objective->fresh()->title, $objective->fresh()->status]);
        $this->assertSame(['Uendret KPI', Kpi::STATUS_ACTIVE], [$kpi->fresh()->title, $kpi->fresh()->status]);
        $this->assertSame(0, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/discovery/v2.0/keys'));
    }

    public function test_the_request_log_counts_the_rows_and_holds_no_content(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Salg');
        $objective = $this->objective($customer, $area, 'En');
        $this->objective($customer, $area, 'To');
        $this->kpi($objective, 'Tre');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/objectives?status=active', $headers)->assertOk();
        $this->get('/api/v1/kpis', $headers)->assertOk();

        $lines = collect($this->logged)->where('context.event', 'api_request')->values();
        $this->assertSame(['api.v1.objectives.index', 2], [$lines[0]['context']['route'], $lines[0]['context']['rows']]);
        $this->assertSame(['api.v1.kpis.index', 1], [$lines[1]['context']['route'], $lines[1]['context']['rows']]);
        $this->assertStringNotContainsString('Salg', (string) json_encode($this->logged));
    }

    /**
     * @param  list<BusinessArea>|null  $areas
     * @param  list<string>  $permissions
     */
    private function grantReader(ApiClient $client, Customer $customer, ?array $areas = null, array $permissions = [CustomerPermissionCatalog::OBJECTIVE_VIEW]): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, $permissions, $areas);
        $this->grantTo($client, $customer, $identity);

        return $identity;
    }

    /** @param  array<string, mixed>  $overrides */
    private function objective(Customer $customer, BusinessArea $area, string $title, array $overrides = []): Objective
    {
        return Objective::query()->create(array_merge([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function kpi(Objective $objective, string $title, array $overrides = []): Kpi
    {
        return Kpi::query()->create(array_merge([
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => $title,
            'unit' => Kpi::UNIT_NUMBER,
            'target_min' => '1',
        ], $overrides));
    }

    /** @param  array<string, mixed>  $snapshot */
    private function measurement(Kpi $kpi, CarbonImmutable $month, string $value, ?User $recorder, CarbonImmutable $recordedAt, array $snapshot = []): KpiMeasurement
    {
        return KpiMeasurement::query()->create(array_merge([
            'customer_id' => $kpi->customer_id,
            'kpi_id' => $kpi->id,
            'period_start' => $month->startOfMonth()->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'value' => $value,
            'comment' => 'FRITEKST-KOMMENTAR',
            'target_min' => $kpi->target_min,
            'target_max' => $kpi->target_max,
            'tolerance' => $kpi->tolerance,
            'recorded_by_user_id' => $recorder?->id,
            'recorded_at' => $recordedAt,
        ], $snapshot));
    }

    /** The schedule expects periods from when the objective and the KPI were created. */
    private function backdate(Objective $objective, Kpi $kpi, CarbonImmutable $at): void
    {
        DB::table('objectives')->where('id', $objective->id)->update(['created_at' => $at]);
        DB::table('kpis')->where('id', $kpi->id)->update(['created_at' => $at]);
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
