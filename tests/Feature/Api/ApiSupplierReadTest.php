<?php

namespace Tests\Feature\Api;

use App\Models\ApiClientCustomerGrant;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging through the API (LEV-01–03): the register, one supplier with its control
 * requirements, aktsomhet, follow-up plan and links, and the kontrollkrav catalogue — what
 * SupplierAccessService lets the identity read (its customer, the module and supplier.view through a
 * role of its own), in structured fields only, without contact details, notes or files.
 */
class ApiSupplierReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ROW_KEYS = [
        'id', 'name', 'organization_number', 'category', 'status', 'criticality', 'review_interval_months',
        'last_assessed_on', 'next_review_on', 'owner', 'requirement_status', 'attention', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_the_register_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithSuppliers();
        $owner = $this->person($customer, 'Eva Eier');
        $supplier = $this->supplier($customer, $owner, 'Drift AS', classification: ['criticality' => Supplier::CRITICALITY_CRITICAL, 'review_interval_months' => 12]);
        $supplier->forceFill(['organization_number' => '987654321'])->save();
        $this->supplier($this->customerWithSuppliers('Annen kunde'), null, 'Annen kundes leverandør');
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/suppliers', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure(['data' => ['*' => self::ROW_KEYS], 'meta' => ['as_of', 'request_id', 'limit', 'next_cursor']]);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0', [
            'id' => $supplier->id,
            'name' => 'Drift AS',
            'organization_number' => '987654321',
            'category' => 'it_cloud',
            'status' => 'active',
            'criticality' => 'critical',
            'review_interval_months' => 12,
            'last_assessed_on' => null,
            'next_review_on' => null,
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
            'requirement_status' => null,
            // SupplierAttentionService's keys: a critical supplier never assessed.
            'attention' => $response->json('data.0.attention'),
            'created_at' => $supplier->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $supplier->fresh()->updated_at->utc()->toIso8601ZuluString(),
        ]);
        $this->assertContains('not_assessed', $response->json('data.0.attention'));

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Kari', 'kari@', '+47', 'contact', 'note', 'deliverable', 'Annen kunde', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }

        $this->assertSame([$supplier->id], array_column($this->get('/api/v1/suppliers?criticality=critical', $this->asCustomer($this->accessToken(), $customer))->json('data'), 'id'));
        $this->assertSame([], $this->get('/api/v1/suppliers?status=ended', $this->asCustomer($this->accessToken(), $customer))->json('data'));
        $this->get('/api/v1/suppliers?criticality=extreme', $this->asCustomer($this->accessToken(), $customer))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_one_supplier_with_requirements_follow_up_and_links_and_the_catalogue(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithSuppliers();
        $owner = $this->person($customer, 'Eva Eier');
        $supplier = $this->supplier($customer, $owner, 'Drift AS', classification: ['criticality' => Supplier::CRITICALITY_IMPORTANT, 'review_interval_months' => 12]);
        $mandatory = SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'control_interval_months' => null, 'applies_when' => [],
            'description' => 'FRITEKST-BESKRIVELSE', 'guidance' => 'FRITEKST-VEILEDNING',
        ]);
        $ongoing = SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Sikkerhetsrapport', 'theme' => 'information_security', 'level' => 'important',
            'control_point' => 'ongoing', 'control_interval_months' => 6, 'applies_when' => [],
        ]);
        $document = SupplierDocument::query()->create([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'document_type' => 'audit_report',
            'title' => 'FRITEKST-DOKUMENT', 'valid_until' => null, 'comment' => 'FRITEKST-KOMMENTAR',
        ]);
        $this->evaluate($supplier, $ongoing, 'documented', now()->subMonths(7)->toDateString(), [$document]);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $response = $this->get("/api/v1/suppliers/{$supplier->id}", $headers);

        $response->assertOk();
        $response->assertExactJsonStructure(['data' => [...self::ROW_KEYS, 'requirements', 'due_diligence', 'follow_up_plan', 'links'], 'meta' => ['as_of', 'request_id']]);
        $response->assertJsonPath('data.requirement_status', 'mandatory_open');
        $requirements = collect($response->json('data.requirements'))->keyBy('id');
        $this->assertSame([
            'id' => $mandatory->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'control_interval_months' => null, 'accepted_document_types' => [],
            'status' => 'active', 'supplier_specific' => false, 'compliance_requirement_id' => null,
            'display_status' => 'not_evaluated', 'evaluated' => false, 'current' => null,
            'follow_up' => ['next_control_on' => null, 'control_overdue' => false, 'document_renewal_due' => false, 'accepted_until' => null, 'acceptance_expired' => false],
        ], $requirements[$mandatory->id]);
        $this->assertSame(['status' => 'documented', 'evaluated_on' => now()->subMonths(7)->toDateString(), 'accepted_until' => null], $requirements[$ongoing->id]['current']);
        // Controlled seven months ago with a six-month interval: the next control is overdue.
        $this->assertTrue($requirements[$ongoing->id]['follow_up']['control_overdue']);
        $this->assertNotEmpty($response->json('data.follow_up_plan'));
        $this->assertSame(['kind', 'date', 'is_overdue', 'requirement_id', 'document_type'], array_keys($response->json('data.follow_up_plan.0')));
        // The identity reads no other module: the links say nothing about them.
        $response->assertJsonPath('data.links', ['risk_ids' => null, 'improvement_case_ids' => null, 'compliance_requirement_ids' => null]);

        $catalogue = $this->get('/api/v1/suppliers/control-requirements', $headers)->assertOk();
        // Kontrollkrav's own order: by theme, then level and title.
        $this->assertSame([$ongoing->id, $mandatory->id], array_column($catalogue->json('data'), 'id'));
        $this->assertSame(1, $catalogue->json('data.0.applies_to_count'));

        foreach ([$response, $catalogue] as $read) {
            $body = (string) $read->getContent();

            foreach (['FRITEKST', 'rationale', 'Kontrollert', 'guidance', 'description', 'wiki', 'Gjelder alle', '/app/'] as $absent) {
                $this->assertStringNotContainsString($absent, $body);
            }
        }
    }

    public function test_another_customers_supplier_is_not_found_and_reading_needs_the_module_and_the_explicit_grant(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithSuppliers();
        $foreign = $this->supplier($this->customerWithSuppliers('Annen kunde'), null, 'Annen kundes leverandør');
        $grant = $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach (["/api/v1/suppliers/{$foreign->id}", '/api/v1/suppliers/999999999'] as $url) {
            $this->get($url, $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        $urls = ['/api/v1/suppliers', '/api/v1/suppliers/control-requirements'];

        $withoutKey = $this->customerWithSuppliers('Uten nøkkel');
        $identity = $this->integrationIdentity($withoutKey);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->grantTo($client, $withoutKey, $identity);
        $withoutModule = $this->apiCustomer('Uten modul');
        $this->grantReader($client, $withoutModule);

        foreach ($urls as $url) {
            $this->get($url, $this->asCustomer($this->accessToken(), $withoutKey))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->get($url, $this->asCustomer($this->accessToken(), $withoutModule))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->get('/api/v1/suppliers', $headers)->assertForbidden();
        $client->forceFill(['is_enabled' => false])->save();
        $this->get('/api/v1/suppliers', $this->asCustomer($this->accessToken(), $withoutModule))->assertUnauthorized();
    }

    public function test_attention_includes_suppliers_and_reading_writes_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithSuppliers();
        $owner = $this->person($customer, 'Eva Eier');
        $flagged = $this->supplier($customer, null, 'Uten ansvarlig');
        $this->supplier($customer, $owner, 'Avsluttet', Supplier::STATUS_ENDED);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        $attention = $this->get('/api/v1/attention', $headers)->assertOk();
        $attention->assertJsonPath('data.modules.suppliers', true);
        $items = collect($attention->json('data.items'))->where('type', 'supplier')->values();
        $this->assertSame([$flagged->id], $items->pluck('id')->all());
        $this->assertContains('missing_owner', $items[0]['attention']);
        $this->assertNull($items[0]['business_area']);
        $this->get('/api/v1/me', $headers)->assertJsonPath('data.modules.suppliers', ['entitled' => true, 'readable' => true, 'business_areas' => null]);

        foreach (['suppliers', "suppliers/{$flagged->id}", 'suppliers/control-requirements'] as $path) {
            $this->get("/api/v1/{$path}", $headers)->assertOk();

            foreach (['post', 'put', 'patch', 'delete'] as $method) {
                $this->{$method.'Json'}("/api/v1/{$path}", ['status' => 'ended'], $headers)->assertStatus(405);
            }
        }

        $this->assertSame($before, $this->domainState($customer));
    }

    private function customerWithSuppliers(string $name = 'Kunde'): Customer
    {
        $customer = $this->apiCustomer($name);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'supplier');

        return $customer;
    }

    /** An integration identity with supplier.view through a role of its own (an explicit-grant domain). */
    private function grantReader($client, Customer $customer): ApiClientCustomerGrant
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::SUPPLIER_VIEW]);

        return $this->grantTo($client, $customer, $identity);
    }

    /** @param  array<string, mixed>  $classification */
    private function supplier(Customer $customer, ?User $owner, string $name, string $status = Supplier::STATUS_ACTIVE, array $classification = []): Supplier
    {
        $supplier = new Supplier([
            'customer_id' => $customer->id,
            'name' => $name,
            'category' => 'it_cloud',
            'deliverable_description' => 'FRITEKST-LEVERANSE',
            'owner_user_id' => $owner?->id,
            'contact_name' => 'Kari Kontakt',
            'contact_email' => 'kari@drift.example',
            'contact_phone' => '+47 900 00 000',
            'note' => 'FRITEKST-NOTAT',
        ]);
        $supplier->status = $status;
        $supplier->forceFill($classification === [] ? [] : $classification + [
            'processes_personal_data' => false, 'has_system_access' => false, 'supports_critical_delivery' => false, 'hard_to_replace' => false,
        ]);
        $supplier->save();

        return $supplier;
    }

    /** @param  list<SupplierDocument>  $documents */
    private function evaluate(Supplier $supplier, SupplierControlRequirement $requirement, string $status, string $on, array $documents): void
    {
        $id = DB::table('supplier_requirement_evaluations')->insertGetId([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'requirement_id' => $requirement->id,
            'status' => $status, 'rationale' => 'Kontrollert.', 'accepted_until' => null, 'evaluated_on' => $on,
            'recorded_at' => now(), 'requirement_title' => $requirement->title, 'requirement_level' => $requirement->level,
            'requirement_theme' => $requirement->theme, 'applicability_reason' => 'Gjelder alle leverandører', 'supplier_name' => $supplier->name,
        ]);

        foreach ($documents as $document) {
            DB::table('supplier_requirement_evaluation_documents')->insert([
                'customer_id' => $supplier->customer_id, 'evaluation_id' => $id, 'supplier_document_id' => $document->id,
                'document_type' => $document->document_type, 'document_title' => $document->title, 'document_valid_until' => $document->valid_until,
            ]);
        }
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

    /** @return array<string, mixed> every Leverandøroppfølging row of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['suppliers', 'supplier_documents', 'supplier_control_requirements', 'supplier_requirement_evaluations', 'supplier_assessments', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
