<?php

namespace Tests\Feature\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
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
 * GET /api/v1/risks/{riskId} (RIS-02, RIS-03): one risk the integration identity may read, with its
 * assessment history, tiltak, acceptance of residual risk and attention codes — in structured
 * fields only, through RiskAccessService::findVisible(), and without writing anything.
 */
class ApiRiskDetailReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const DETAIL_KEYS = [
        'id', 'title', 'status', 'treatment_strategy', 'business_area', 'owner', 'inherent', 'residual',
        'last_assessed_on', 'review_interval_months', 'next_review_on', 'is_review_overdue', 'attention', 'updated_at',
        'assessments', 'treatment_actions', 'acceptance', 'controls', 'processes',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_one_risk_is_presented_with_its_history_and_follow_up_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $owner = $this->person($customer, 'Eva Eier');
        $assessor = $this->person($customer, 'Vera Vurderer');
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
            'review_interval_months' => 12,
        ]);
        $first = $this->assess($risk, $assessor, now()->subMonths(6), [4, 4], null);
        $latest = $this->assess($risk, $assessor, now()->subMonth(), [4, 4], [4, 3]);
        $revoked = RiskAcceptance::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'assessment_id' => $latest->id,
            'accepted_by_user_id' => $assessor->id, 'accepted_at' => now()->subWeeks(3), 'rationale' => 'FRITEKST-AKSEPT',
            'revoked_at' => now()->subWeeks(2), 'revoked_by_user_id' => $assessor->id,
        ]);
        $current = RiskAcceptance::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'assessment_id' => $latest->id,
            'accepted_by_user_id' => $assessor->id, 'accepted_at' => now()->subWeek(), 'rationale' => 'FRITEKST-AKSEPT',
            'valid_until' => now()->addYear()->toDateString(),
        ]);
        $overdue = $this->action($risk, $owner, 'Reservestrøm', now()->subDays(3)->toDateString());
        $done = $this->action($risk, null, 'Ny UPS', now()->subMonth()->toDateString(), [
            'status' => RiskTreatmentAction::STATUS_COMPLETED,
            'completed_at' => now()->subDays(10),
            'outcome_note' => 'FRITEKST-RESULTAT',
        ]);
        $this->grantReader($client, $customer, [$area]);

        $response = $this->get("/api/v1/risks/{$risk->id}", $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => self::DETAIL_KEYS,
            'meta' => ['as_of', 'request_id'],
        ]);
        $response->assertJsonPath('data.id', $risk->id);
        $response->assertJsonPath('data.business_area', ['id' => $area->id, 'name' => 'Beredskap']);
        $response->assertJsonPath('data.owner', ['id' => $owner->id, 'name' => 'Eva Eier']);
        $response->assertJsonPath('data.residual', ['likelihood' => 4, 'consequence' => 3, 'score' => 12, 'level' => 'high']);
        // High residual and an overdue tiltak: the same codes as «Trenger oppmerksomhet».
        $response->assertJsonPath('data.attention', ['high_residual', 'actions_overdue']);
        $response->assertJsonPath('data.assessments', [
            [
                'id' => $latest->id,
                'assessed_at' => $latest->assessed_at->utc()->toIso8601ZuluString(),
                'inherent' => ['likelihood' => 4, 'consequence' => 4, 'score' => 16, 'level' => 'high'],
                'residual' => ['likelihood' => 4, 'consequence' => 3, 'score' => 12, 'level' => 'high'],
            ],
            [
                'id' => $first->id,
                'assessed_at' => $first->assessed_at->utc()->toIso8601ZuluString(),
                'inherent' => ['likelihood' => 4, 'consequence' => 4, 'score' => 16, 'level' => 'high'],
                'residual' => null,
            ],
        ]);
        $response->assertJsonPath('data.treatment_actions', [
            [
                'id' => $overdue->id,
                'title' => 'Reservestrøm',
                'status' => 'open',
                'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
                'due_at' => now()->subDays(3)->toDateString(),
                'completed_at' => null,
                'is_overdue' => true,
            ],
            [
                'id' => $done->id,
                'title' => 'Ny UPS',
                'status' => 'completed',
                'owner' => null,
                'due_at' => now()->subMonth()->toDateString(),
                'completed_at' => $done->completed_at->utc()->toIso8601ZuluString(),
                'is_overdue' => false,
            ],
        ]);
        $response->assertJsonPath('data.acceptance', [
            'current' => [
                'id' => $current->id,
                'assessment_id' => $latest->id,
                'state' => 'current',
                'accepted_at' => $current->accepted_at->utc()->toIso8601ZuluString(),
                'valid_until' => now()->addYear()->toDateString(),
                'is_expired' => false,
                'revoked_at' => null,
            ],
            'history' => [[
                'id' => $revoked->id,
                'assessment_id' => $latest->id,
                'state' => 'revoked',
                'accepted_at' => $revoked->accepted_at->utc()->toIso8601ZuluString(),
                'valid_until' => null,
                'is_expired' => false,
                'revoked_at' => $revoked->revoked_at->utc()->toIso8601ZuluString(),
            ]],
        ]);

        // Without Kvalitet read access: null, not empty — nothing is said about Kvalitet.
        $response->assertJsonPath('data.controls', null);
        $response->assertJsonPath('data.processes', null);

        // No free text, no assessor or acceptor, no web routes.
        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Vera Vurderer', 'rationale', 'outcome_note', 'quality_context', 'supplier', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_a_risk_of_another_customer_outside_the_fagomrader_or_not_there_is_not_found(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $beredskap = $this->businessArea($customer, 'Beredskap');
        $outside = $this->risk($customer, $this->businessArea($customer, 'Økonomi'), 'Utenfor fagområdet');
        $other = $this->apiCustomer('Annen kunde');
        $foreign = $this->risk($other, $this->businessArea($other, 'Beredskap'), 'Annen kundes risiko');
        $this->grantReader($client, $customer, [$beredskap]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach ([$outside->id, $foreign->id, 999999999] as $id) {
            $response = $this->get("/api/v1/risks/{$id}", $headers);
            $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->assertStringNotContainsString('risiko', (string) $response->getContent());
        }

        // The other customer cannot be chosen: there is no grant for it.
        $this->get("/api/v1/risks/{$foreign->id}", $this->asCustomer($this->accessToken(), $other))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_without_risk_view_the_module_or_an_active_grant_the_risk_is_refused(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $risk = $this->risk($customer, $this->businessArea($customer, 'Beredskap'), 'Hemmelig');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_EDIT, CustomerPermissionCatalog::RISK_CREATE]);
        $grant = $this->grantTo($client, $customer, $identity);
        $url = "/api/v1/risks/{$risk->id}";

        $response = $this->get($url, $this->asCustomer($this->accessToken(), $customer));
        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->get($url, $this->asCustomer($this->accessToken(), $customer))->assertForbidden();

        $withoutModule = $this->apiCustomer('Uten Risiko', withRisk: false);
        $hidden = $this->risk($withoutModule, $this->businessArea($withoutModule, 'Beredskap'), 'Hemmelig');
        $this->grantReader($client, $withoutModule);
        $this->get("/api/v1/risks/{$hidden->id}", $this->asCustomer($this->accessToken(), $withoutModule))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_disabled_client_is_not_authenticated(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $risk = $this->risk($customer, $this->businessArea($customer, 'Beredskap'), 'Risiko');
        $this->grantReader($client, $customer);
        $client->forceFill(['is_enabled' => false])->save();

        $this->get("/api/v1/risks/{$risk->id}", $this->asCustomer($this->accessToken(), $customer))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_reading_a_risk_writes_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $owner = $this->person($customer, 'Eva Eier');
        $risk = $this->risk($customer, $area, 'Uendret');
        $this->assess($risk, $owner, now()->subYear(), [3, 3], [2, 2]);
        $this->action($risk, $owner, 'Tiltak', now()->subDay()->toDateString());
        $this->grantReader($client, $customer);
        $before = $this->domainState($customer);

        $this->get("/api/v1/risks/{$risk->id}", $this->asCustomer($this->accessToken(), $customer))->assertOk();
        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer))->assertOk();

        $this->assertSame($before, $this->domainState($customer));
    }

    /** @param  list<BusinessArea>|null  $areas */
    private function grantReader($client, Customer $customer, ?array $areas = null): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW], $areas);
        $this->grantTo($client, $customer, $identity);

        return $identity;
    }

    private function risk(Customer $customer, BusinessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /**
     * @param  array{0: int, 1: int}  $inherent
     * @param  array{0: int, 1: int}|null  $residual
     */
    private function assess(Risk $risk, User $assessor, $at, array $inherent, ?array $residual): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_by' => $assessor->id,
            'assessed_at' => $at,
            'rationale' => 'FRITEKST-BEGRUNNELSE',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => $inherent[0],
            'inherent_consequence' => $inherent[1],
            'residual_likelihood' => $residual[0] ?? null,
            'residual_consequence' => $residual[1] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function action(Risk $risk, ?User $owner, string $title, string $dueAt, array $overrides = []): RiskTreatmentAction
    {
        return RiskTreatmentAction::query()->create(array_merge([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'title' => $title,
            'owner_user_id' => $owner?->id,
            'due_at' => $dueAt,
            'status' => RiskTreatmentAction::STATUS_OPEN,
        ], $overrides));
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

    /** @return array<string, mixed> every Risiko row of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['risks', 'risk_assessments', 'risk_acceptances', 'risk_treatment_actions', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
