<?php

namespace Tests\Feature\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewSection;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * GET /api/v1/management-reviews (LDG-01) and GET /api/v1/management-reviews/{reviewId} (LDG-02,
 * LDG-03): the reviews the integration identity may read — its customer, the module and
 * management_review.view — with their sections and decisions in structured fields only, under the
 * review page's own gates, and without writing anything.
 */
class ApiManagementReviewReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use CreatesManagementReviewScenarios;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ROW_KEYS = [
        'id', 'title', 'status', 'period_start', 'period_end', 'meeting_date', 'scope', 'frameworks', 'owner',
        'next_review_due_on', 'finalized_at', 'decisions', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_reviews_are_listed_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $manager = $this->mrManager($customer);
        $area = $this->mrArea($customer, 'Drift');
        $review = $this->mrReview($manager, [
            'title' => 'Gjennomgang H1',
            'purpose' => 'FRITEKST-FORMÅL',
            'all_business_areas' => false,
            'business_area_ids' => [$area->id],
        ]);
        $review->forceFill(['conclusion' => 'FRITEKST-KONKLUSJON'])->save();
        $this->decision($manager, $review, 'action', $manager);
        $this->decision($manager, $review, 'decision', null);
        $other = $this->apiCustomer('Annen kunde');
        $this->mrReview($this->mrManager($other), ['title' => 'Annen kundes gjennomgang']);
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/management-reviews', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [self::ROW_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0', [
            'id' => $review->id,
            'title' => 'Gjennomgang H1',
            'status' => 'draft',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'meeting_date' => '2026-07-01',
            'scope' => ['all_business_areas' => false, 'business_areas' => [['id' => $area->id, 'name' => 'Drift']]],
            'frameworks' => [],
            'owner' => ['id' => $manager->id, 'name' => $manager->name],
            'next_review_due_on' => null,
            'finalized_at' => null,
            'decisions' => ['total' => 2, 'actions_open' => 1],
            'created_at' => $review->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $review->fresh()->updated_at->utc()->toIso8601ZuluString(),
        ]);

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Annen kunde', 'purpose', 'conclusion', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_one_review_shows_its_sections_and_decisions_under_the_review_pages_gates(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT, CustomerPermissionCatalog::RISK_VIEW], true);
        $review = $this->mrReview($manager);
        $review->participants()->create(['customer_id' => $customer->id, 'name' => 'Deltaker Dagny', 'role_label' => 'FRITEKST-ROLLE']);

        foreach (['risks' => 'needs_improvement', 'improvements' => 'satisfactory', 'resources' => 'satisfactory'] as $key => $judgement) {
            ManagementReviewSection::query()->create([
                'customer_id' => $customer->id, 'management_review_id' => $review->id, 'section_key' => $key,
                'judgement' => $judgement, 'comment' => 'FRITEKST-KOMMENTAR', 'notes' => 'FRITEKST-NOTAT',
            ]);
        }

        $action = $this->decision($manager, $review, 'action', $manager);
        $visibleCase = ImprovementCase::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'type' => ImprovementCase::TYPE_IMPROVEMENT,
            'title' => 'Sak fra gjennomgangen', 'description' => 'x', 'due_date' => now()->addMonth()->toDateString(),
        ]);
        $followed = $this->decision($manager, $review, 'action', $manager);
        app(ManagementReviewDecisionService::class)->link($manager, $followed, $visibleCase->id);
        $hiddenCase = ImprovementCase::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $this->mrArea($customer, 'Økonomi')->id, 'type' => ImprovementCase::TYPE_IMPROVEMENT,
            'title' => 'Skjult sak', 'description' => 'x',
        ]);
        $hidden = $this->decision($manager, $review, 'action', $manager);
        app(ManagementReviewDecisionService::class)->link($manager, $hidden, $hiddenCase->id);
        // The identity reads reviews and cases in Drift only — not Risiko, not Økonomi.
        $this->grantReader($client, $customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);

        $response = $this->get("/api/v1/management-reviews/{$review->id}", $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [...self::ROW_KEYS, 'participant_count', 'sections' => ['*' => ['key', 'type', 'module', 'state', 'judgement']], 'decision_list' => ['*' => [
                'id', 'kind', 'section_key', 'owner', 'due_on', 'follow_up', 'status', 'is_overdue', 'completed_at', 'improvement_case',
            ]]],
            'meta' => ['as_of', 'request_id'],
        ]);
        $response->assertJsonPath('data.participant_count', 1);

        $sections = collect($response->json('data.sections'))->keyBy('key');
        // A module section the identity cannot read: its state says so, and its judgement is withheld.
        $this->assertSame(['key' => 'risks', 'type' => 'module', 'module' => 'risk', 'state' => 'no_access', 'judgement' => null], $sections['risks']);
        $this->assertSame(['key' => 'improvements', 'type' => 'module', 'module' => 'improvements', 'state' => 'available', 'judgement' => 'satisfactory'], $sections['improvements']);
        $this->assertSame('satisfactory', $sections['resources']['judgement']);

        $decisions = collect($response->json('data.decision_list'))->keyBy('id');
        $this->assertSame([
            'id' => $action->id, 'kind' => 'action', 'section_key' => null,
            'owner' => ['id' => $manager->id, 'name' => $manager->name],
            'due_on' => now()->addWeeks(2)->toDateString(), 'follow_up' => 'own', 'status' => 'open',
            'is_overdue' => false, 'completed_at' => null, 'improvement_case' => null,
        ], $decisions[$action->id]);
        $this->assertSame('improvement_case', $decisions[$followed->id]['follow_up']);
        $this->assertSame(['id' => $visibleCase->id, 'status' => 'open', 'due_on' => now()->addMonth()->toDateString()], $decisions[$followed->id]['improvement_case']);
        // A case in a fagområde the identity cannot read is not named at all.
        $this->assertNull($decisions[$hidden->id]['improvement_case']);

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Dagny', 'Tiltak fra møtet', 'Skjult sak', 'Sak fra gjennomgangen', 'basis', 'amendments', 'history', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_another_customers_review_or_a_missing_one_is_not_found_and_the_list_pages(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $manager = $this->mrManager($customer);
        $ids = array_map(fn (int $n): int => $this->mrReview($manager, ['title' => "Gjennomgang {$n}"])->id, range(1, 3));
        $other = $this->apiCustomer('Annen kunde');
        $foreign = $this->mrReview($this->mrManager($other));
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach ([$foreign->id, 999999999] as $id) {
            $response = $this->get("/api/v1/management-reviews/{$id}", $headers);
            $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        $first = $this->get('/api/v1/management-reviews?limit=2', $headers)->assertOk();
        $second = $this->get('/api/v1/management-reviews?limit=2&cursor='.$first->json('meta.next_cursor'), $headers)->assertOk();
        $this->assertSame($ids, [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')]);
        $this->assertSame([], array_column($this->get('/api/v1/management-reviews?status=finalized', $headers)->json('data'), 'id'));
        $this->get('/api/v1/management-reviews?status=closed', $headers)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_without_management_review_view_the_module_an_active_grant_or_an_enabled_client_it_is_refused(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $review = $this->mrReview($this->mrManager($customer), ['title' => 'Hemmelig']);
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT]);
        $grant = $this->grantTo($client, $customer, $identity);

        foreach (['/api/v1/management-reviews', "/api/v1/management-reviews/{$review->id}"] as $url) {
            $response = $this->get($url, $this->asCustomer($this->accessToken(), $customer));
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
        }

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->get("/api/v1/management-reviews/{$review->id}", $this->asCustomer($this->accessToken(), $customer))->assertForbidden();

        $withoutModule = $this->apiCustomer('Uten moduler', withRisk: false);
        $this->grantReader($client, $withoutModule);
        $this->get('/api/v1/management-reviews', $this->asCustomer($this->accessToken(), $withoutModule))->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $enabled = $this->apiCustomer('Aktiv');
        $this->grantReader($client, $enabled);
        $client->forceFill(['is_enabled' => false])->save();
        $this->get('/api/v1/management-reviews', $this->asCustomer($this->accessToken(), $enabled))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_reading_reviews_writes_nothing_and_accepts_no_write(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager);
        $this->decision($manager, $review, 'action', $manager);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        $this->get('/api/v1/management-reviews', $headers)->assertOk();
        $this->get("/api/v1/management-reviews/{$review->id}", $headers)->assertOk();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['/api/v1/management-reviews', "/api/v1/management-reviews/{$review->id}"] as $url) {
                $this->{$method.'Json'}($url, ['status' => 'finalized'], $headers)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
            }
        }

        $this->assertSame($before, $this->domainState($customer));
        $this->assertSame(ManagementReview::STATUS_DRAFT, $review->fresh()->status);
    }

    /**
     * @param  list<string>  $extra
     * @param  list<BusinessArea>|null  $areas
     */
    private function grantReader($client, Customer $customer, array $extra = [], ?array $areas = null): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW]);

        if ($extra !== []) {
            $this->giveRole($identity, $extra, $areas);
        }

        $this->grantTo($client, $customer, $identity);

        return $identity;
    }

    private function decision(User $actor, ManagementReview $review, string $kind, ?User $owner): ManagementReviewDecision
    {
        return app(ManagementReviewDecisionService::class)->create($actor, $review, $kind === 'action' ? [
            'kind' => 'action', 'text' => 'Tiltak fra møtet', 'owner_user_id' => $owner?->id, 'due_date' => now()->addWeeks(2)->toDateString(),
        ] : ['kind' => 'decision', 'text' => 'FRITEKST-BESLUTNING']);
    }

    /** @return array<string, mixed> every Ledelsens gjennomgang row of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['management_reviews', 'management_review_sections', 'management_review_decisions', 'management_review_events', 'management_review_snapshot_sections', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
