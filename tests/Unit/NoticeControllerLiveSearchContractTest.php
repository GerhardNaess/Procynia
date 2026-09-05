<?php

namespace Tests\Unit;

use App\Http\Controllers\App\NoticeController;
use App\Models\User;
use App\Models\WatchProfileInboxRecord;
use App\Services\Cpv\CustomerNoticeCpvSearchService;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Services\GoNoGo\GoNoGoDefaultTemplateService;
use App\Services\SavedNoticeAccessService;
use App\Services\SavedNoticeNoGoDecisionService;
use App\Support\CustomerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoticeControllerLiveSearchContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connectionName = 'notice_contract_sqlite';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);

        Schema::dropIfExists('saved_notices');
        Schema::create('saved_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('saved_by_user_id')->nullable();
            $table->unsignedBigInteger('opportunity_owner_user_id')->nullable();
            $table->unsignedBigInteger('bid_manager_user_id')->nullable();
            $table->unsignedBigInteger('organizational_department_id')->nullable();
            $table->string('external_id');
            $table->string('title');
            $table->string('buyer_name')->nullable();
            $table->string('external_url', 2000)->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->string('status')->nullable();
            $table->string('cpv_code')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('history_type')->nullable();
            $table->timestamp('questions_deadline_at')->nullable();
            $table->timestamp('questions_rfi_deadline_at')->nullable();
            $table->timestamp('rfi_submission_deadline_at')->nullable();
            $table->timestamp('questions_rfp_deadline_at')->nullable();
            $table->timestamp('rfp_submission_deadline_at')->nullable();
            $table->timestamp('award_date_at')->nullable();
            $table->string('selected_supplier_name')->nullable();
            $table->string('contract_value')->nullable();
            $table->string('contract_period_text')->nullable();
            $table->decimal('contract_value_mnok', 12, 2)->nullable();
            $table->unsignedInteger('contract_period_months')->nullable();
            $table->timestamp('next_process_date_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'external_id']);
        });

        Schema::dropIfExists('saved_notice_user_access');
        Schema::create('saved_notice_user_access', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->string('access_role');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('departments');
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Case visibility resolves the acting user's customer to read its permission settings.
        Schema::dropIfExists('customers');
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('permission_settings')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('watch_profile_cpv_codes');
        Schema::create('watch_profile_cpv_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('watch_profile_id');
            $table->string('cpv_code');
            $table->unsignedInteger('weight')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('watch_profiles');
        Schema::create('watch_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::dropIfExists('watch_profile_inbox_records');
        Schema::create('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('watch_profile_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('doffin_notice_id');
            $table->string('title');
            $table->string('buyer_name')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->string('external_url', 2000)->nullable();
            $table->unsignedInteger('relevance_score')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->text('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('watch_profile_inbox_records');
        Schema::dropIfExists('watch_profiles');
        Schema::dropIfExists('saved_notice_user_access');
        Schema::dropIfExists('watch_profile_cpv_codes');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('users');
        Schema::dropIfExists('saved_notices');
        Mockery::close();

        parent::tearDown();
    }

    public function test_index_returns_a_notice_payload_the_frontend_can_render(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService;
        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $documentService = Mockery::mock(DoffinNoticeDocumentService::class);

        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(1);

        $liveSearchService
            ->shouldReceive('search')
            ->once()
            ->with([
                'q' => 'Domstoladministrasjonen',
                'organization_name' => '',
                'cpv' => '90910000,72222300',
                'keywords' => 'renhold, tingrett',
                'watch_list_id' => '1',
                'publication_date_from' => '2026-03-01',
                'publication_date_to' => '2026-03-31',
                'publication_period' => '',
                'status' => 'ACTIVE',
                'relevance' => '',
                'bid_status' => '',
                'history_type' => '',
                'cockpit_scope' => '',
            ], 1, 15)
            ->andReturn([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => null,
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
            ]);

        DB::table('watch_profiles')->insert([
            [
                'id' => 1,
                'customer_id' => 1,
                'user_id' => 23,
                'department_id' => null,
                'name' => 'Kunde - drift',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'customer_id' => 1,
                'user_id' => null,
                'department_id' => 8,
                'name' => 'Avdeling - bygg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            [
                'id' => 23,
                'name' => 'Customer Admin',
                'email' => 'customer.admin@procynia.local',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('departments')->insert([
            [
                'id' => 8,
                'name' => 'Avdeling bygg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('watch_profile_cpv_codes')->insert([
            [
                'watch_profile_id' => 1,
                'cpv_code' => '12345678',
                'weight' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'watch_profile_id' => 2,
                'cpv_code' => '87654321',
                'weight' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('saved_notices')->insert([
            [
                'customer_id' => 1,
                'saved_by_user_id' => 23,
                'opportunity_owner_user_id' => null,
                'bid_manager_user_id' => null,
                'organizational_department_id' => null,
                'external_id' => '2026-100002',
                'title' => 'Test 2',
                'buyer_name' => 'Oppdragsgiver 2',
                'external_url' => 'https://doffin.no/notices/2026-100002',
                'summary' => 'Lagret varsel',
                'publication_date' => now()->subDay(),
                'deadline' => now()->addDays(7),
                'status' => 'ACTIVE',
                'cpv_code' => '12345678',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('watch_profile_inbox_records')->insert([
            [
                'watch_profile_id' => 1,
                'customer_id' => 1,
                'user_id' => 23,
                'department_id' => null,
                'doffin_notice_id' => '2026-100001',
                'title' => 'Test 1',
                'buyer_name' => 'Oppdragsgiver 1',
                'publication_date' => now()->subHours(6)->toDateTimeString(),
                'deadline' => now()->addDays(10)->toDateTimeString(),
                'external_url' => 'https://doffin.no/notices/2026-100001',
                'relevance_score' => 7,
                'discovered_at' => now()->subHours(6),
                'raw_payload' => json_encode([
                    'heading' => 'Test 1',
                    'description' => 'Beskrivelse for varsel 1.',
                    'status' => 'ACTIVE',
                    'publicationDate' => now()->subDays(1)->toDateString(),
                    'deadline' => now()->addDays(10)->toDateString(),
                    'mainCpvCode' => '12345678',
                    'cpvCodes' => ['12345678'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'watch_profile_id' => 2,
                'customer_id' => 1,
                'user_id' => null,
                'department_id' => 8,
                'doffin_notice_id' => '2026-100002',
                'title' => 'Test 2',
                'buyer_name' => 'Oppdragsgiver 2',
                'publication_date' => now()->subHours(4)->toDateTimeString(),
                'deadline' => now()->addDays(7)->toDateTimeString(),
                'external_url' => 'https://doffin.no/notices/2026-100002',
                'relevance_score' => 9,
                'discovered_at' => now()->subHours(4),
                'raw_payload' => json_encode([
                    'heading' => 'Test 2',
                    'description' => 'Beskrivelse for varsel 2.',
                    'status' => 'ACTIVE',
                    'publicationDate' => now()->subDays(1)->toDateString(),
                    'deadline' => now()->addDays(7)->toDateString(),
                    'mainCpvCode' => '87654321',
                    'cpvCodes' => ['87654321'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = Request::create('/app/notices', 'GET', [
            'q' => 'Domstoladministrasjonen',
            'cpv' => '90910000,72222300',
            'keywords' => 'renhold, tingrett',
            'watch_list_id' => '1',
            'publication_date_from' => '2026-03-01',
            'publication_date_to' => '2026-03-31',
            'status' => 'ACTIVE',
        ]);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]));

        $controller = new NoticeController(
            $customerContext,
            $cpvSearchService,
            $liveSearchService,
            $documentService,
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('App/Notices/Index', $page['component']);
        $this->assertSame('live', $page['props']['mode']);
        $this->assertSame('90910000,72222300', $page['props']['filters']['cpv']);
        $this->assertSame('renhold, tingrett', $page['props']['filters']['keywords']);
        $this->assertSame('1', $page['props']['filters']['watch_list_id']);
        $this->assertSame('ACTIVE', $page['props']['filters']['status']);
        $this->assertSame(1, $page['props']['worklist']['saved_count']);
        $this->assertSame(0, $page['props']['worklist']['history_count']);
        $this->assertSame(2, $page['props']['monitoring']['new_hits_last_day_count']);
        $this->assertSame('Nattlig Doffin-discovery kjører hver dag kl. 01:15.', $page['props']['monitoring']['next_update_text']);
        $this->assertSame(2, $page['props']['watchAlerts']['meta']['total']);
        $this->assertSame('2026-100002', $page['props']['watchAlerts']['data'][0]['notice_id']);
        $this->assertSame('Avdeling - bygg', $page['props']['watchAlerts']['data'][0]['watch_profile_name']);
        $this->assertTrue($page['props']['watchAlerts']['data'][0]['is_saved']);
        $this->assertSame(route('app.notices.watch-alerts.destroy', ['watchProfileInboxRecord' => 2]), $page['props']['watchAlerts']['data'][0]['delete_url']);
        $this->assertSame('2026-100001', $page['props']['watchAlerts']['data'][1]['notice_id']);
        $this->assertSame('Kunde - drift', $page['props']['watchAlerts']['data'][1]['watch_profile_name']);
        $this->assertFalse($page['props']['watchAlerts']['data'][1]['is_saved']);
        $this->assertSame('Rengjøring', $page['props']['cpvSelector']['selected'][0]['label']);
        $this->assertSame('IT-tjenester', $page['props']['cpvSelector']['selected'][1]['label']);
        $this->assertSame(1, $page['props']['notices']['meta']['total']);
        $this->assertSame(1, $page['props']['notices']['meta']['numHitsTotal']);
        $this->assertSame(1, $page['props']['notices']['meta']['numHitsAccessible']);
        $this->assertFalse($page['props']['notices']['meta']['is_capped']);
        $this->assertSame('2026-03-01', $page['props']['filters']['publication_date_from']);
        $this->assertSame('2026-03-31', $page['props']['filters']['publication_date_to']);
        $this->assertSame('2026-105164', $page['props']['notices']['data'][0]['notice_id']);
        $this->assertSame('Domstoladministrasjonen', $page['props']['notices']['data'][0]['buyer_name']);
        $this->assertSame('https://doffin.no/notices/2026-105164', $page['props']['notices']['data'][0]['external_url']);
    }

    public function test_index_uses_publication_period_as_a_fallback_date_range_when_explicit_dates_are_missing(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService;
        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $documentService = Mockery::mock(DoffinNoticeDocumentService::class);

        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(1);

        $liveSearchService
            ->shouldReceive('search')
            ->once()
            ->with(Mockery::on(function (array $filters): bool {
                return $filters['publication_period'] === '365'
                    && $filters['publication_date_from'] === now()->subDays(365)->toDateString()
                    && $filters['publication_date_to'] === now()->toDateString();
            }), 1, 15)
            ->andReturn([
                'numHitsTotal' => 0,
                'numHitsAccessible' => 0,
                'hits' => [],
                'page' => 1,
                'perPage' => 15,
            ]);

        $request = Request::create('/app/notices', 'GET', [
            'q' => 'Domstoladministrasjonen',
            'publication_period' => '365',
            'status' => 'ACTIVE',
        ]);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]));

        $controller = new NoticeController(
            $customerContext,
            $cpvSearchService,
            $liveSearchService,
            $documentService,
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('365', $page['props']['filters']['publication_period']);
        $this->assertSame(now()->subDays(365)->toDateString(), $page['props']['filters']['publication_date_from']);
        $this->assertSame(now()->toDateString(), $page['props']['filters']['publication_date_to']);
    }

    public function test_index_exposes_true_doffin_total_and_clamps_live_pagination_to_the_accessible_window(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService;
        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $documentService = Mockery::mock(DoffinNoticeDocumentService::class);

        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(1);

        $liveSearchService
            ->shouldReceive('search')
            ->once()
            ->with(Mockery::type('array'), 66, 15)
            ->andReturn([
                'numHitsTotal' => 151555,
                'numHitsAccessible' => 1000,
                'hits' => array_fill(0, 15, [
                    'id' => '2026-105164',
                    'buyer' => [
                        [
                            'id' => 'e7c38cb469460081ad1de749d4670c71',
                            'organizationId' => '984195796',
                            'name' => 'Domstoladministrasjonen',
                        ],
                    ],
                    'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                    'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                    'status' => null,
                    'publicationDate' => '2026-03-16',
                    'deadline' => null,
                ]),
            ]);

        $request = Request::create('/app/notices', 'GET', [
            'page' => 66,
        ]);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]));

        $controller = new NoticeController(
            $customerContext,
            $cpvSearchService,
            $liveSearchService,
            $documentService,
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(151555, $page['props']['notices']['meta']['total']);
        $this->assertSame(151555, $page['props']['notices']['meta']['numHitsTotal']);
        $this->assertSame(1000, $page['props']['notices']['meta']['numHitsAccessible']);
        $this->assertTrue($page['props']['notices']['meta']['is_capped']);
        $this->assertSame(66, $page['props']['notices']['meta']['current_page']);
        $this->assertSame(66, $page['props']['notices']['meta']['last_page']);
        $this->assertSame(976, $page['props']['notices']['meta']['from']);
        $this->assertSame(990, $page['props']['notices']['meta']['to']);
        $this->assertStringContainsString('page=65', $page['props']['notices']['meta']['prev_page_url']);
        $this->assertNull($page['props']['notices']['meta']['next_page_url']);
    }

    public function test_index_returns_zero_monitoring_hits_for_the_current_customer_when_service_reports_zero(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService;
        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $documentService = Mockery::mock(DoffinNoticeDocumentService::class);

        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(7);

        $liveSearchService
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                'numHitsTotal' => 0,
                'numHitsAccessible' => 0,
                'hits' => [],
            ]);

        $request = Request::create('/app/notices', 'GET');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 99,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 7,
            'is_active' => true,
        ]));

        $controller = new NoticeController(
            $customerContext,
            $cpvSearchService,
            $liveSearchService,
            $documentService,
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $page['props']['monitoring']['new_hits_last_day_count']);
        $this->assertSame('Nattlig Doffin-discovery kjører hver dag kl. 01:15.', $page['props']['monitoring']['next_update_text']);
    }

    public function test_alerts_tab_returns_alerts_only_payload_without_running_live_search(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService;
        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $documentService = Mockery::mock(DoffinNoticeDocumentService::class);

        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(1);

        $liveSearchService
            ->shouldReceive('search')
            ->never();

        DB::table('watch_profiles')->insert([
            [
                'id' => 1,
                'customer_id' => 1,
                'user_id' => 23,
                'department_id' => null,
                'name' => 'Kunde - drift',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('watch_profile_inbox_records')->insert([
            [
                'id' => 1,
                'watch_profile_id' => 1,
                'customer_id' => 1,
                'user_id' => 23,
                'department_id' => null,
                'doffin_notice_id' => '2026-100001',
                'title' => 'Test 1',
                'buyer_name' => 'Oppdragsgiver 1',
                'publication_date' => now()->subHours(6),
                'deadline' => now()->addDays(10),
                'external_url' => 'https://doffin.no/notices/2026-100001',
                'relevance_score' => 7,
                'discovered_at' => now()->subHours(6),
                'raw_payload' => json_encode([
                    'heading' => 'Test 1',
                    'description' => 'Beskrivelse for varsel 1.',
                    'status' => 'ACTIVE',
                    'publicationDate' => now()->subDays(1)->toDateString(),
                    'deadline' => now()->addDays(10)->toDateString(),
                    'mainCpvCode' => '12345678',
                    'cpvCodes' => ['12345678'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = Request::create('/app/notices', 'GET', [
            'tab' => 'alerts',
        ]);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]));

        $controller = new NoticeController(
            $customerContext,
            $cpvSearchService,
            $liveSearchService,
            $documentService,
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('alerts', $page['props']['tab']);
        $this->assertSame([], $page['props']['notices']['data']);
        $this->assertSame(1, $page['props']['watchAlerts']['meta']['total']);
        $this->assertSame('2026-100001', $page['props']['watchAlerts']['data'][0]['notice_id']);
    }

    public function test_destroy_watch_alert_record_deletes_accessible_record(): void
    {
        DB::table('watch_profiles')->insert([
            [
                'id' => 1,
                'customer_id' => 1,
                'user_id' => 23,
                'department_id' => null,
                'name' => 'Kunde - drift',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('watch_profile_inbox_records')->insert([
            [
                'id' => 1,
                'watch_profile_id' => 1,
                'customer_id' => 1,
                'user_id' => 23,
                'department_id' => null,
                'doffin_notice_id' => '2026-100001',
                'title' => 'Test 1',
                'discovered_at' => now()->subHours(6),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $customerContext = Mockery::mock(CustomerContext::class);
        $customerContext
            ->shouldReceive('currentCustomerId')
            ->never();

        $controller = new NoticeController(
            $customerContext,
            new CustomerNoticeCpvSearchService,
            Mockery::mock(DoffinLiveSearchService::class),
            Mockery::mock(DoffinNoticeDocumentService::class),
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );

        $request = Request::create('/app/notices/watch-alerts/1', 'DELETE');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]));

        $response = $controller->destroyWatchAlertRecord($request, WatchProfileInboxRecord::query()->firstOrFail());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertDatabaseMissing('watch_profile_inbox_records', [
            'id' => 1,
        ]);
    }

    #[DataProvider('liveSearchErrorCases')]
    public function test_index_maps_controlled_live_search_errors_to_http_status_and_safe_message(array $serviceResponse, int $expectedStatus, string $expectedMessage): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(1);

        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $liveSearchService
            ->shouldReceive('search')
            ->once()
            ->andReturn($serviceResponse);

        $controller = $this->makeLiveSearchController($customerContext, $liveSearchService);
        $request = $this->makeLiveSearchRequest([
            'q' => 'Renhold',
        ]);
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($expectedStatus, $response->getStatusCode());
        $this->assertSame('App/Notices/Index', $page['component']);
        $this->assertSame('live', $page['props']['mode']);
        $this->assertSame($expectedMessage, $page['props']['notices']['error']);
        $this->assertSame($serviceResponse['error_type'], $page['props']['notices']['meta']['error_type']);
        $this->assertSame($serviceResponse['upstream_status'], $page['props']['notices']['meta']['upstream_status']);
        $this->assertFalse($page['props']['notices']['meta']['fallback_used']);
        $this->assertSame([], $page['props']['notices']['data']);
    }

    public function test_index_exposes_fallback_used_when_live_search_succeeds_without_a_buyer_lookup(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $customerContext
            ->shouldReceive('currentCustomerId')
            ->once()
            ->andReturn(1);

        $liveSearchService = Mockery::mock(DoffinLiveSearchService::class);
        $liveSearchService
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                'ok' => true,
                'items' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => null,
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
                'hits' => [
                    [
                        'id' => '2026-105164',
                        'buyer' => [
                            [
                                'id' => 'e7c38cb469460081ad1de749d4670c71',
                                'organizationId' => '984195796',
                                'name' => 'Domstoladministrasjonen',
                            ],
                        ],
                        'heading' => 'Renholdstjenester Vestre Finnmark tingrett, rettssted Alta',
                        'description' => 'Formålet med anskaffelsen er å inngå kontrakt om renholdstjenester.',
                        'status' => null,
                        'publicationDate' => '2026-03-16',
                        'deadline' => null,
                    ],
                ],
                'fallback_used' => true,
                'page' => 1,
                'perPage' => 15,
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'meta' => [
                    'fallback_used' => true,
                ],
            ]);

        $controller = $this->makeLiveSearchController($customerContext, $liveSearchService);
        $request = $this->makeLiveSearchRequest([
            'q' => 'Renhold',
        ]);
        $response = $controller->index($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($page['props']['notices']['meta']['fallback_used']);
        $this->assertSame('2026-105164', $page['props']['notices']['data'][0]['notice_id']);
    }

    public static function liveSearchErrorCases(): array
    {
        return [
            'invalid_request' => [
                [
                    'ok' => false,
                    'items' => [],
                    'hits' => [],
                    'error_type' => 'invalid_request',
                    'error_message' => 'Doffin avviste søket.',
                    'upstream_status' => 400,
                    'fallback_used' => false,
                    'page' => 1,
                    'perPage' => 15,
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'meta' => [
                        'fallback_used' => false,
                    ],
                ],
                422,
                'Søket mot Doffin ble avvist. Kontroller filtrene og prøv igjen.',
            ],
            'upstream_unavailable' => [
                [
                    'ok' => false,
                    'items' => [],
                    'hits' => [],
                    'error_type' => 'upstream_unavailable',
                    'error_message' => 'Doffin er midlertidig utilgjengelig.',
                    'upstream_status' => 503,
                    'fallback_used' => false,
                    'page' => 1,
                    'perPage' => 15,
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'meta' => [
                        'fallback_used' => false,
                    ],
                ],
                503,
                'Doffin er midlertidig utilgjengelig. Prøv igjen om litt.',
            ],
            'timeout' => [
                [
                    'ok' => false,
                    'items' => [],
                    'hits' => [],
                    'error_type' => 'timeout',
                    'error_message' => 'Doffin svarte ikke i tide.',
                    'upstream_status' => null,
                    'fallback_used' => false,
                    'page' => 1,
                    'perPage' => 15,
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'meta' => [
                        'fallback_used' => false,
                    ],
                ],
                503,
                'Doffin svarte ikke i tide. Prøv igjen om litt.',
            ],
            'connection_error' => [
                [
                    'ok' => false,
                    'items' => [],
                    'hits' => [],
                    'error_type' => 'connection_error',
                    'error_message' => 'Klarte ikke å koble til Doffin.',
                    'upstream_status' => null,
                    'fallback_used' => false,
                    'page' => 1,
                    'perPage' => 15,
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'meta' => [
                        'fallback_used' => false,
                    ],
                ],
                503,
                'Klarte ikke å koble til Doffin. Prøv igjen om litt.',
            ],
            'unexpected_response' => [
                [
                    'ok' => false,
                    'items' => [],
                    'hits' => [],
                    'error_type' => 'unexpected_response',
                    'error_message' => 'Doffin returnerte et uventet svar.',
                    'upstream_status' => 200,
                    'fallback_used' => false,
                    'page' => 1,
                    'perPage' => 15,
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'meta' => [
                        'fallback_used' => false,
                    ],
                ],
                502,
                'Doffin returnerte et uventet svar. Prøv igjen om litt.',
            ],
        ];
    }

    private function makeLiveSearchController(CustomerContext $customerContext, DoffinLiveSearchService $liveSearchService): NoticeController
    {
        return new NoticeController(
            $customerContext,
            new CustomerNoticeCpvSearchService,
            $liveSearchService,
            Mockery::mock(DoffinNoticeDocumentService::class),
            new SavedNoticeAccessService,
            new SavedNoticeNoGoDecisionService,
            new GoNoGoDefaultTemplateService,
        );
    }

    private function makeLiveSearchRequest(array $query = []): Request
    {
        $request = Request::create('/app/notices', 'GET', $query);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver(fn (): User => new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]));

        return $request;
    }
}
