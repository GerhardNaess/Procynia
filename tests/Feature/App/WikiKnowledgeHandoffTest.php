<?php

namespace Tests\Feature\App;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\AiModelPrice;
use App\Models\AiUsageAttempt;
use App\Models\BusinessArea;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\ImprovementCase;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use App\Support\Ai\RunsInAiCallContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The shared «Lag kunnskapsartikkel» handoff for Etterlevelse, Leverandøroppfølging, Avvik og
 * forbedringer and Mål (Risiko has its own, older test: RiskWikiKnowledgeTest).
 *
 * The same matrix for every module: the module's own write permission AND wiki.source.manage, a
 * foreign or hidden record is a 404, the source becomes an ordinary Wiki document with an origin row
 * and an ordinary queued run, nothing is published — and the run's AI usage is attributed to the
 * module record that caused it.
 */
class WikiKnowledgeHandoffTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('local');
        Queue::fake();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function sources(): array
    {
        return [
            'compliance audit' => ['compliance_audit'],
            'supplier' => ['supplier'],
            'improvement case' => ['improvement_case'],
            'objective' => ['objective'],
        ];
    }

    #[DataProvider('sources')]
    public function test_a_permitted_user_hands_the_record_over_as_an_ordinary_wiki_source(string $sourceType): void
    {
        $customer = $this->customer();
        $scenario = $this->scenario($sourceType, $customer);
        $user = $this->member($customer, [...$scenario['edit'], CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE], $scenario['areas']);

        $panel = $this->actingAs($user)->get($scenario['show'])->assertOk()->viewData('page')['props']['knowledge_handoff'];
        $this->assertTrue($panel['can_create']);
        $this->assertSame($scenario['url'], $panel['submit_url']);
        $offered = array_column($panel['draft']['sections'], 'key');
        $this->assertNotSame([], $offered);

        $this->actingAs($user)->post($scenario['url'], [
            'title' => 'Læring fra '.$sourceType,
            'learning' => 'Dette bør andre vite.',
            'sections' => $offered,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        // An ordinary Wiki source with the lesson and the chosen module content.
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();
        $this->assertStringContainsString('Dette bør andre vite.', (string) $document->extracted_text);
        $this->assertStringContainsString($scenario['marker'], (string) $document->extracted_text);

        // Provenance back to the module record, for this customer.
        $origin = EnterpriseWikiDocumentOrigin::query()->where('enterprise_wiki_document_id', $document->id)->sole();
        $this->assertSame($scenario['module'], $origin->source_module);
        $this->assertSame($sourceType, $origin->source_type);
        $this->assertSame((int) $scenario['record']->getKey(), $origin->source_id);
        $this->assertSame((int) $customer->id, $origin->customer_id);
        $this->assertSame((int) $user->id, $origin->created_by_user_id);

        // The ordinary run on the Wiki's own queue — nothing written to a page, nothing published.
        $run = EnterpriseWikiIngestRun::query()->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)->where('source_id', $document->id)->sole();
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, fn (RunEnterpriseWikiDocumentFlow $job): bool => (int) $job->runId === (int) $run->id);
        $this->assertSame(0, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());

        // The module lists what it handed over.
        $entries = $this->actingAs($user)->get($scenario['show'])->viewData('page')['props']['knowledge_handoff']['entries'];
        $this->assertCount(1, $entries);
        $this->assertSame('source', $entries[0]['kind']);
    }

    #[DataProvider('sources')]
    public function test_the_wiki_permission_alone_or_the_module_permission_alone_is_not_enough(string $sourceType): void
    {
        $customer = $this->customer();
        $scenario = $this->scenario($sourceType, $customer);

        $moduleOnly = $this->member($customer, [...$scenario['edit'], CustomerPermissionCatalog::WIKI_VIEW], $scenario['areas']);
        $this->assertFalse($this->actingAs($moduleOnly)->get($scenario['show'])->viewData('page')['props']['knowledge_handoff']['can_create']);
        $this->actingAs($moduleOnly)->post($scenario['url'], $this->payload())->assertForbidden();

        $readerWithWiki = $this->member($customer, [...$scenario['view'], CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE], $scenario['areas']);
        $panel = $this->actingAs($readerWithWiki)->get($scenario['show'])->viewData('page')['props']['knowledge_handoff'];
        $this->assertFalse($panel['can_create']);
        $this->assertNull($panel['draft']);
        $this->actingAs($readerWithWiki)->post($scenario['url'], $this->payload())->assertForbidden();

        $this->assertNothingHandedOver($customer);
    }

    #[DataProvider('sources')]
    public function test_a_record_of_another_customer_is_a_404(string $sourceType): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $foreign = $this->scenario($sourceType, $other);
        $own = $this->scenario($sourceType, $customer);
        $user = $this->member($customer, [...$own['edit'], CustomerPermissionCatalog::WIKI_SOURCE_MANAGE], $own['areas']);

        $this->actingAs($user)->post($foreign['url'], $this->payload())->assertNotFound();

        $this->assertNothingHandedOver($customer);
        $this->assertNothingHandedOver($other);
    }

    public function test_deleting_the_source_record_removes_only_the_provenance(): void
    {
        $customer = $this->customer();
        $scenario = $this->scenario('improvement_case', $customer);
        $user = $this->member($customer, [...$scenario['edit'], CustomerPermissionCatalog::WIKI_SOURCE_MANAGE], $scenario['areas']);
        $this->actingAs($user)->post($scenario['url'], $this->payload())->assertSessionHasNoErrors();
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();

        $scenario['record']->delete();

        $this->assertSame(0, EnterpriseWikiDocumentOrigin::query()->where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $document->id]);
    }

    public function test_the_wiki_run_ai_usage_is_attributed_to_the_module_record_that_caused_it(): void
    {
        config(['services.openai.api_key' => 'test-key', 'services.openai.base_url' => 'https://openai.test/v1']);
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]], 200)]);
        AiModelPrice::query()->firstOrCreate(
            ['provider' => 'openai', 'model' => 'gpt-5', 'is_active' => true],
            ['currency' => 'usd', 'input_price_per_1m_tokens' => 1.25, 'cached_input_price_per_1m_tokens' => 0.125,
                'output_price_per_1m_tokens' => 10.00, 'valid_from' => now()->subDay()->toDateString(), 'last_verified_at' => now()],
        );

        $customer = $this->customer();
        // A plan that includes AI, so the call reaches the provider and is recorded.
        $customer->forceFill(['subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 10])->save();
        $scenario = $this->scenario('supplier', $customer);
        $user = $this->member($customer, [...$scenario['edit'], CustomerPermissionCatalog::WIKI_SOURCE_MANAGE], $scenario['areas']);
        $this->actingAs($user)->post($scenario['url'], $this->payload())->assertSessionHasNoErrors();
        $run = EnterpriseWikiIngestRun::query()->where('customer_id', $customer->id)->sole();
        auth()->logout();

        // What every Wiki job does at its boundary (RunsInAiCallContext), then one provider call made
        // by a Wiki client deep inside it.
        $job = new class
        {
            use RunsInAiCallContext;

            public function call(int $runId): void
            {
                $this->withinAiCallContext(
                    $this->enterpriseWikiRunAiCallContext($runId, 'wiki.document_flow'),
                    fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'wiki.generate_page'),
                );
            }
        };
        $job->call((int) $run->id);

        $attempt = AiUsageAttempt::query()->where('enterprise_wiki_ingest_run_id', $run->id)->sole();
        $this->assertSame((int) $customer->id, $attempt->customer_id);
        $this->assertSame((int) $user->id, $attempt->user_id);
        $this->assertSame('customer', $attempt->attribution);
        $this->assertSame('wiki', $attempt->feature);
        $this->assertSame('wiki.generate_page', $attempt->operation_key);
        $this->assertSame('supplier', $attempt->resource_type);
        $this->assertSame((int) $scenario['record']->getKey(), $attempt->resource_id);
        $this->assertSame(0, app(AiCallContextScope::class)->current()->customerId ?? 0);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * One record of the given type with something worth handing over, and the permissions that
     * view and edit it.
     *
     * @return array{record: Model, module: string, show: string, url: string, edit: list<string>, view: list<string>, areas: list<BusinessArea>, marker: string}
     */
    private function scenario(string $sourceType, Customer $customer): array
    {
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Område '.Str::random(5)]);

        return match ($sourceType) {
            'compliance_audit' => (function () use ($customer): array {
                $audit = ComplianceAudit::query()->create([
                    'customer_id' => $customer->id, 'title' => 'Internrevisjon ISO 27001', 'audit_type' => ComplianceAudit::TYPE_INTERNAL,
                    'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->addWeek()->toDateString(),
                    'scope_description' => 'Tilgangsstyring i drift',
                ]);
                ComplianceAuditFinding::query()->create([
                    'customer_id' => $customer->id, 'audit_id' => $audit->id, 'finding_type' => ComplianceAuditFinding::TYPE_OBSERVATION,
                    'title' => 'Tilgangsgjennomgang mangler signatur', 'description' => 'Kvartalsvis gjennomgang er ikke signert.',
                ]);

                return [
                    'record' => $audit, 'module' => 'compliance',
                    'show' => "/app/compliance/audits/{$audit->id}", 'url' => "/app/compliance/audits/{$audit->id}/knowledge-handoff",
                    'edit' => [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_AUDIT],
                    'view' => [CustomerPermissionCatalog::COMPLIANCE_VIEW], 'areas' => [],
                    'marker' => 'Tilgangsgjennomgang mangler signatur',
                ];
            })(),
            'supplier' => (function () use ($customer): array {
                $supplier = Supplier::query()->create([
                    'customer_id' => $customer->id, 'name' => 'Driftspartner AS', 'category' => 'it_cloud',
                    'deliverable_description' => 'Drift av lønnssystemet',
                ]);

                return [
                    'record' => $supplier, 'module' => 'supplier',
                    'show' => "/app/supplier-management/{$supplier->id}", 'url' => "/app/supplier-management/{$supplier->id}/knowledge-handoff",
                    'edit' => [CustomerPermissionCatalog::SUPPLIER_VIEW, CustomerPermissionCatalog::SUPPLIER_ASSURE],
                    'view' => [CustomerPermissionCatalog::SUPPLIER_VIEW], 'areas' => [],
                    'marker' => 'Drift av lønnssystemet',
                ];
            })(),
            'improvement_case' => (function () use ($customer, $area): array {
                $case = ImprovementCase::query()->create([
                    'customer_id' => $customer->id, 'business_area_id' => $area->id, 'type' => ImprovementCase::TYPE_DEVIATION,
                    'title' => 'Feil lønnsutbetaling', 'description' => 'Dobbel utbetaling i mars.',
                    'cause_analysis' => 'Manuell import uten avstemming.',
                ]);

                return [
                    'record' => $case, 'module' => 'improvements',
                    'show' => "/app/improvements/{$case->id}", 'url' => "/app/improvements/{$case->id}/knowledge-handoff",
                    'edit' => [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT],
                    'view' => [CustomerPermissionCatalog::IMPROVEMENT_VIEW], 'areas' => [$area],
                    'marker' => 'Manuell import uten avstemming.',
                ];
            })(),
            'objective' => (function () use ($customer, $area): array {
                $objective = Objective::query()->create([
                    'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Færre lønnsfeil',
                    'description' => 'Halvere antall lønnsfeil innen året er omme.', 'target_date' => now()->addYear()->toDateString(),
                ]);

                return [
                    'record' => $objective, 'module' => 'objectives',
                    'show' => "/app/objectives/{$objective->id}", 'url' => "/app/objectives/{$objective->id}/knowledge-handoff",
                    'edit' => [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT],
                    'view' => [CustomerPermissionCatalog::OBJECTIVE_VIEW], 'areas' => [$area],
                    'marker' => 'Halvere antall lønnsfeil',
                ];
            })(),
        };
    }

    /** @return array{title: string, learning: string, sections: list<string>} */
    private function payload(): array
    {
        return ['title' => 'Generell læring', 'learning' => 'Avstem før utbetaling.', 'sections' => []];
    }

    private function assertNothingHandedOver(Customer $customer): void
    {
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, EnterpriseWikiDocumentOrigin::query()->where('customer_id', $customer->id)->count());
        Queue::assertNotPushed(RunEnterpriseWikiDocumentFlow::class);
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function member(Customer $customer, array $permissionKeys, array $areas = []): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'handoff-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Kunnskap AS '.Str::random(5),
            'slug' => 'kunnskap-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');
        foreach (app(ModuleEntitlementService::class)->bundlePackages('grc') as $package) {
            app(ModuleEntitlementService::class)->activatePackage($customer, $package);
        }

        return $customer;
    }
}
