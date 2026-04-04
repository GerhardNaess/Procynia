<?php

namespace Tests\Unit;

use App\Http\Controllers\App\NoticeController;
use App\Models\User;
use App\Services\Cpv\CustomerNoticeCpvSearchService;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
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
        Schema::dropIfExists('saved_notices');
        Mockery::close();

        parent::tearDown();
    }

    public function test_index_returns_a_notice_payload_the_frontend_can_render(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService();
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
                'publication_period' => '',
                'status' => 'ACTIVE',
                'relevance' => '',
                'bid_status' => '',
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

        DB::table('watch_profile_inbox_records')->insert([
            [
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
            [
                'watch_profile_id' => 2,
                'customer_id' => 1,
                'user_id' => null,
                'department_id' => 8,
                'doffin_notice_id' => '2026-100002',
                'title' => 'Test 2',
                'discovered_at' => now()->subHours(4),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = Request::create('/app/notices', 'GET', [
            'q' => 'Domstoladministrasjonen',
            'cpv' => '90910000,72222300',
            'keywords' => 'renhold, tingrett',
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
            new SavedNoticeAccessService(),
        );
        $response = $controller->index($request)->toResponse($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('App/Notices/Index', $page['component']);
        $this->assertSame('live', $page['props']['mode']);
        $this->assertSame('90910000,72222300', $page['props']['filters']['cpv']);
        $this->assertSame('renhold, tingrett', $page['props']['filters']['keywords']);
        $this->assertSame('ACTIVE', $page['props']['filters']['status']);
        $this->assertSame(0, $page['props']['worklist']['saved_count']);
        $this->assertSame(0, $page['props']['worklist']['history_count']);
        $this->assertSame(2, $page['props']['monitoring']['new_hits_last_day_count']);
        $this->assertSame('Nattlig Doffin-discovery kjører hver dag kl. 01:15.', $page['props']['monitoring']['next_update_text']);
        $this->assertSame('Rengjøring', $page['props']['cpvSelector']['selected'][0]['label']);
        $this->assertSame('IT-tjenester', $page['props']['cpvSelector']['selected'][1]['label']);
        $this->assertSame(1, $page['props']['notices']['meta']['total']);
        $this->assertSame(1, $page['props']['notices']['meta']['numHitsTotal']);
        $this->assertSame(1, $page['props']['notices']['meta']['numHitsAccessible']);
        $this->assertFalse($page['props']['notices']['meta']['is_capped']);
        $this->assertSame('2026-105164', $page['props']['notices']['data'][0]['notice_id']);
        $this->assertSame('Domstoladministrasjonen', $page['props']['notices']['data'][0]['buyer_name']);
        $this->assertSame('https://doffin.no/notices/2026-105164', $page['props']['notices']['data'][0]['external_url']);
    }

    public function test_index_exposes_true_doffin_total_and_clamps_live_pagination_to_the_accessible_window(): void
    {
        $customerContext = Mockery::mock(CustomerContext::class);
        $cpvSearchService = new CustomerNoticeCpvSearchService();
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
            new SavedNoticeAccessService(),
        );
        $response = $controller->index($request)->toResponse($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

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
        $cpvSearchService = new CustomerNoticeCpvSearchService();
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
            new SavedNoticeAccessService(),
        );
        $response = $controller->index($request)->toResponse($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $page['props']['monitoring']['new_hits_last_day_count']);
        $this->assertSame('Nattlig Doffin-discovery kjører hver dag kl. 01:15.', $page['props']['monitoring']['next_update_text']);
    }
}
