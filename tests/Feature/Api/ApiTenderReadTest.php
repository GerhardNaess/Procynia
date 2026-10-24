<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\SavedNoticePhaseComment;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\SavedNoticeAccessService;
use App\Support\ApiOperationCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Anbud through the API (ANB-01, ANB-02, D23–D28): the cases and their requirements' metadata,
 * read with tender.read switched on for the grant, across the identity's whole customer and never
 * another's; structured fields only, without requirement text, answers, comments, files or the old
 * internal admin fields on notices (K16); and an integration that is a reader, never a Bid Manager
 * or a decision maker, whatever its row carries.
 */
class ApiTenderReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    // Anbud is ordered deliberately per customer, so a customer without it can be tested.
    protected bool $customersHoldTenderPackage = false;

    private const CASE_KEYS = [
        'id', 'title', 'source_type', 'bid_status', 'department', 'bid_manager', 'opportunity_owner',
        'deadlines', 'signals', 'archived_at', 'outcome', 'notice', 'created_at', 'updated_at',
    ];

    private const REQUIREMENT_KEYS = [
        'id', 'identifier', 'requirement_type', 'review_status', 'work_status', 'assignee', 'coverage',
        'has_answer_draft', 'source_document',
    ];

    /** Free text that must never leave Procynia through these endpoints. */
    private const SECRETS = [
        'HEMMELIG-NOTAT', 'HEMMELIG-SAMMENDRAG', 'HEMMELIG-KOMMENTAR', 'HEMMELIG-KRAVTEKST', 'HEMMELIG-SVAR',
        'HEMMELIG-K16', 'HEMMELIG-BEGRUNNELSE', 'kontakt@oppdragsgiver.test', 'saved-notices/',
    ];

    private const K16_KEYS = ['internal_status', 'internal_comment', 'assigned_to_user_id', 'decision_by_user_id', 'status_changed_at', 'status_changed_by_user_id'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_cases_are_listed_in_structured_fields_only_across_the_whole_customer(): void
    {
        $client = $this->apiClient();
        $customer = $this->tenderCustomer();
        $owner = $this->person($customer, 'Tora Tilbud');
        $department = $this->department($customer, 'Offentlig');
        $notice = $this->notice();
        $case = $this->case($customer, $owner, [
            'notice_id' => $notice->id,
            'organizational_department_id' => $department->id,
            'bid_manager_user_id' => $owner->id,
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'deadline' => now()->addDays(3)->setTime(12, 0),
            'questions_deadline_at' => now()->addDays(30)->setTime(12, 0),
        ]);
        SavedNoticePhaseComment::query()->create([
            'saved_notice_id' => $case->id, 'user_id' => $owner->id, 'phase_status' => 'in_progress', 'comment' => 'HEMMELIG-KOMMENTAR',
        ]);
        // No department and nobody involved: still the customer's portfolio (D24).
        $private = $this->case($customer, $owner, ['source_type' => SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST, 'source' => null]);
        $archived = $this->case($customer, $owner, ['archived_at' => now(), 'history_type' => SavedNotice::HISTORY_TYPE_LOST, 'bid_status' => SavedNotice::BID_STATUS_LOST]);
        $other = $this->tenderCustomer('Annen kunde');
        $this->case($other, $this->person($other, 'Annen'));
        $this->grantReader($client, $customer);

        $response = $this->read('/api/v1/tender/cases', $customer);

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => ['*' => self::CASE_KEYS],
            'meta' => ['as_of', 'request_id', 'limit', 'next_cursor'],
        ]);
        $this->assertSame([$case->id, $private->id], array_column($response->json('data'), 'id'), 'Active cases of the own customer only.');
        $response->assertJsonPath('data.0.department', ['id' => $department->id, 'name' => 'Offentlig']);
        $response->assertJsonPath('data.0.bid_manager', ['id' => $owner->id, 'name' => 'Tora Tilbud']);
        $response->assertJsonPath('data.0.opportunity_owner', null);
        $response->assertJsonPath('data.0.signals.is_active', true);
        $response->assertJsonPath('data.0.signals.deadlines_soon.0.type', 'submission');
        $response->assertJsonCount(1, 'data.0.signals.deadlines_soon');
        $this->assertNotNull($response->json('data.0.signals.latest_activity_at'));
        $this->assertNotNull($response->json('data.0.deadlines.questions'));
        $response->assertJsonPath('data.0.notice', [
            'source' => 'doffin',
            'external_id' => $case->external_id,
            'buyer_name' => 'Oppdragsgiver kommune',
            'cpv_code' => '72000000',
            'notice_type' => 'COMPETITION',
            'published_at' => $case->publication_date->utc()->toIso8601ZuluString(),
        ]);
        $response->assertJsonPath('data.1.notice', null);
        $response->assertJsonPath('data.1.department', null);
        $this->assertNoSecrets($response);

        $archivedResponse = $this->read('/api/v1/tender/cases?archived=true', $customer)->assertOk();
        $this->assertSame([$archived->id], array_column($archivedResponse->json('data'), 'id'), 'Archived cases only with the filter.');
        $archivedResponse->assertJsonPath('data.0.outcome', 'lost');
        $archivedResponse->assertJsonPath('data.0.signals.is_active', false);
        $archivedResponse->assertJsonPath('data.0.signals.deadlines_soon', []);
    }

    public function test_the_list_filters_by_bid_status_and_continues_with_a_cursor(): void
    {
        $client = $this->apiClient();
        $customer = $this->tenderCustomer();
        $owner = $this->person($customer, 'Eier');
        $first = $this->case($customer, $owner);
        $second = $this->case($customer, $owner);
        $third = $this->case($customer, $owner, ['bid_status' => SavedNotice::BID_STATUS_SUBMITTED]);
        $this->grantReader($client, $customer);

        $page = $this->read('/api/v1/tender/cases?limit=2', $customer)->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($page->json('data'), 'id'));
        $next = $this->read('/api/v1/tender/cases?limit=2&cursor='.$page->json('meta.next_cursor'), $customer)->assertOk();
        $this->assertSame([$third->id], array_column($next->json('data'), 'id'));
        $next->assertJsonPath('meta.next_cursor', null);

        $filtered = $this->read('/api/v1/tender/cases?bid_status=submitted', $customer)->assertOk();
        $this->assertSame([$third->id], array_column($filtered->json('data'), 'id'));

        $this->read('/api/v1/tender/cases?bid_status=nonsense', $customer)->assertStatus(422);
        $this->read('/api/v1/tender/cases?archived=yes', $customer)->assertStatus(422);
        $this->read('/api/v1/tender/cases?cursor=nonsense', $customer)->assertStatus(422);
    }

    public function test_one_case_is_the_same_404_when_it_is_another_customers_or_missing(): void
    {
        $client = $this->apiClient();
        $customer = $this->tenderCustomer();
        $case = $this->case($customer, $this->person($customer, 'Eier'));
        $other = $this->tenderCustomer('Annen kunde');
        $foreign = $this->case($other, $this->person($other, 'Annen'));
        $this->grantReader($client, $customer);

        $response = $this->read("/api/v1/tender/cases/{$case->id}", $customer);
        $response->assertOk();
        $response->assertExactJsonStructure(['data' => self::CASE_KEYS, 'meta' => ['as_of', 'request_id']]);
        $response->assertJsonPath('data.id', $case->id);
        $this->assertNoSecrets($response);

        foreach ([$foreign->id, 999999999] as $id) {
            $this->read("/api/v1/tender/cases/{$id}", $customer)->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->read("/api/v1/tender/cases/{$id}/requirements", $customer)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
    }

    public function test_requirements_are_metadata_only_and_published_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->tenderCustomer();
        $owner = $this->person($customer, 'Kari Krav');
        $case = $this->case($customer, $owner);
        $document = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $case->id,
            'original_filename' => 'Kravspesifikasjon.pdf',
            'stored_path' => "saved-notices/{$case->id}/ai-documents/kravspesifikasjon.pdf",
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
        ]);
        $answered = $this->requirement($case, [
            'saved_notice_ai_document_id' => $document->id,
            'requirement_identifier' => 'K-1',
            'assigned_user_id' => $owner->id,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS,
        ]);
        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $answered->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_PARTIAL,
            'answer_text' => 'HEMMELIG-SVAR',
            'missing_summary' => 'HEMMELIG-BEGRUNNELSE',
            'sources' => [],
            'generated_at' => now(),
        ]);
        $bare = $this->requirement($case, ['requirement_identifier' => 'K-2']);
        $this->requirement($case, ['requirement_identifier' => 'K-KLADD', 'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED]);
        $this->grantReader($client, $customer);

        $response = $this->read("/api/v1/tender/cases/{$case->id}/requirements", $customer);

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => ['*' => self::REQUIREMENT_KEYS],
            'meta' => ['as_of', 'request_id', 'case_id'],
        ]);
        $response->assertJsonPath('meta.case_id', $case->id);
        $this->assertEqualsCanonicalizing([$answered->id, $bare->id], array_column($response->json('data'), 'id'), 'Published requirements only.');
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame([
            'id' => $answered->id,
            'identifier' => 'K-1',
            'requirement_type' => 'documentation',
            'review_status' => 'confirmed',
            'work_status' => 'in_progress',
            'assignee' => ['id' => $owner->id, 'name' => 'Kari Krav'],
            'coverage' => 'partial',
            'has_answer_draft' => true,
            'source_document' => ['name' => 'Kravspesifikasjon.pdf'],
        ], $rows[$answered->id]);
        $this->assertNull($rows[$bare->id]['coverage']);
        $this->assertFalse($rows[$bare->id]['has_answer_draft']);
        $this->assertNull($rows[$bare->id]['source_document']);
        $this->assertNoSecrets($response);
    }

    public function test_reading_needs_the_operation_the_module_and_an_active_identity(): void
    {
        $client = $this->apiClient();

        // Anbud ordered, but tender.read not switched on for the grant.
        $customer = $this->tenderCustomer();
        $case = $this->case($customer, $this->person($customer, 'Eier'));
        $this->grantReader($client, $customer, operations: []);
        foreach (['/api/v1/tender/cases', "/api/v1/tender/cases/{$case->id}", "/api/v1/tender/cases/{$case->id}/requirements"] as $path) {
            $this->read($path, $customer)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }

        // The operation, but the customer has not ordered Anbud.
        $without = $this->apiCustomer('Uten Anbud');
        $this->grantReader($client, $without);
        $this->read('/api/v1/tender/cases', $without)->assertForbidden();

        // An inactive identity is refused before anything is read.
        $inactive = $this->tenderCustomer('Inaktiv');
        $this->case($inactive, $this->person($inactive, 'Eier'));
        $this->grantTo($client, $inactive, $this->integrationIdentity($inactive, ['is_active' => false]), ['allowed_operations' => [ApiOperationCatalog::TENDER_READ]]);
        $response = $this->read('/api/v1/tender/cases', $inactive);
        $response->assertForbidden();
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_outside_an_api_request_the_read_path_gives_nothing(): void
    {
        $customer = $this->tenderCustomer();
        $this->case($customer, $this->person($customer, 'Eier'));
        $identity = $this->grantReader($this->apiClient(), $customer);
        $access = app(SavedNoticeAccessService::class);

        $this->assertFalse($access->integrationMayRead($identity));
        $this->assertSame(0, $access->integrationReadableQuery($identity)->count());
        // A person never reads through it, whatever they may see in the app.
        $this->assertSame(0, $access->integrationReadableQuery($this->person($customer, 'Person', User::BID_ROLE_SYSTEM_OWNER))->count());
    }

    public function test_an_integration_is_never_a_bid_manager_or_a_case_worker_whatever_its_row_carries(): void
    {
        $customer = $this->tenderCustomer();
        $owner = $this->person($customer, 'Eier');
        $case = $this->case($customer, $owner);
        $identity = $this->integrationIdentity($customer, [
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ]);
        $access = app(SavedNoticeAccessService::class);

        $this->assertFalse($identity->isBidManager());
        $this->assertSame(User::BID_ROLE_VIEWER, $identity->resolvedBidRole());
        $this->assertSame(0, $access->cockpitScopeQueryFor($identity, (int) $customer->id)->count());
        $this->assertFalse($access->mayWork($identity, $case));
        $this->assertFalse($access->mayCreateCase($identity));
        $this->assertFalse($access->canComment($identity, $case));
        $this->assertFalse($access->canArchive($identity, $case));

        $this->expectException(AuthorizationException::class);
        $access->authorizeWork($identity, $case);
    }

    public function test_me_shows_tender_as_customer_wide_once_the_operation_is_on(): void
    {
        $client = $this->apiClient();
        $customer = $this->tenderCustomer();
        $this->grantReader($client, $customer, operations: []);

        $this->read('/api/v1/me', $customer)->assertOk()
            ->assertJsonPath('data.modules.tender', ['entitled' => true, 'readable' => false, 'business_areas' => null]);

        $on = $this->tenderCustomer('Med lesing');
        $this->grantReader($client, $on);
        $this->read('/api/v1/me', $on)->assertOk()
            ->assertJsonPath('data.modules.tender', ['entitled' => true, 'readable' => true, 'business_areas' => null]);

        $without = $this->apiCustomer('Uten Anbud');
        $this->grantReader($client, $without);
        $this->read('/api/v1/me', $without)->assertOk()
            ->assertJsonPath('data.modules.tender', ['entitled' => false, 'readable' => false, 'business_areas' => null]);
    }

    public function test_the_internal_admin_fields_on_notices_are_never_serialized(): void
    {
        $notice = $this->notice();

        foreach (self::K16_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $notice->toArray());
        }

        $this->assertSame('HEMMELIG-K16', $notice->internal_comment, 'The attribute itself stays readable for Filament.');
    }

    private function tenderCustomer(string $name = 'Kunde'): Customer
    {
        $customer = $this->apiCustomer($name);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'tender');

        return $customer;
    }

    /** @param  list<string>  $operations */
    private function grantReader(ApiClient $client, Customer $customer, array $operations = [ApiOperationCatalog::TENDER_READ]): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->grantTo($client, $customer, $identity, ['allowed_operations' => $operations]);

        return $identity;
    }

    private function read(string $path, Customer $customer): TestResponse
    {
        return $this->getJson($path, $this->asCustomer($this->accessToken(), $customer));
    }

    private function person(Customer $customer, string $name, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt(Str::random(32)),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function department(Customer $customer, string $name): Department
    {
        return Department::query()->create(['customer_id' => $customer->id, 'name' => $name, 'is_active' => true]);
    }

    private function notice(): Notice
    {
        return Notice::query()->create([
            'notice_id' => 'DOFFIN-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'notice_type' => 'COMPETITION',
            'internal_status' => 'interesting',
            'internal_comment' => 'HEMMELIG-K16',
            'status_changed_at' => now(),
        ]);
    }

    private function case(Customer $customer, User $savedBy, array $attributes = []): SavedNotice
    {
        return SavedNotice::query()->create(array_merge([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $savedBy->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'source' => 'doffin',
            'external_id' => 'EXT-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Oppdragsgiver kommune',
            'cpv_code' => '72000000',
            'publication_date' => now()->subDays(10)->startOfDay(),
            'contact_person_email' => 'kontakt@oppdragsgiver.test',
            'summary' => 'HEMMELIG-SAMMENDRAG',
            'notes' => 'HEMMELIG-NOTAT',
            'bid_closure_note' => 'HEMMELIG-BEGRUNNELSE',
            'status' => 'ACTIVE',
        ], $attributes));
    }

    private function requirement(SavedNotice $case, array $attributes = []): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()->create(array_merge([
            'saved_notice_id' => $case->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
            'requirement_text' => 'HEMMELIG-KRAVTEKST',
            'original_requirement_text' => 'HEMMELIG-KRAVTEKST',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_MANUAL,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ], $attributes));
    }

    private function assertNoSecrets(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        foreach ([...self::SECRETS, ...array_map(fn (string $key): string => '"'.$key.'"', self::K16_KEYS)] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
