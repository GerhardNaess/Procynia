<?php

namespace Tests\Unit;

use App\Http\Controllers\App\NoticeController;
use App\Models\User;
use App\Services\Cpv\CustomerNoticeCpvSearchService;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class NoticeControllerLiveSearchContractTest extends TestCase
{
    protected function tearDown(): void
    {
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

        $watchProfiles = Mockery::mock();
        $watchProfiles->shouldReceive('where')->twice()->andReturnSelf();
        $watchProfiles->shouldReceive('with')->once()->andReturnSelf();
        $watchProfiles->shouldReceive('orderBy')->once()->andReturnSelf();
        $watchProfiles->shouldReceive('get')->once()->andReturn(collect());

        $watchProfileAlias = Mockery::mock('alias:App\Models\WatchProfile');
        $watchProfileAlias->shouldReceive('query')->once()->andReturn($watchProfiles);

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

        $controller = new NoticeController($customerContext, $cpvSearchService, $liveSearchService, $documentService);
        $response = $controller->index($request)->toResponse($request);
        $page = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('App/Notices/Index', $page['component']);
        $this->assertSame('90910000,72222300', $page['props']['filters']['cpv']);
        $this->assertSame('renhold, tingrett', $page['props']['filters']['keywords']);
        $this->assertSame('ACTIVE', $page['props']['filters']['status']);
        $this->assertSame('Rengjøring', $page['props']['cpvSelector']['selected'][0]['label']);
        $this->assertSame('IT-tjenester', $page['props']['cpvSelector']['selected'][1]['label']);
        $this->assertSame(1, $page['props']['notices']['meta']['total']);
        $this->assertSame('2026-105164', $page['props']['notices']['data'][0]['notice_id']);
        $this->assertSame('Domstoladministrasjonen', $page['props']['notices']['data'][0]['buyer_name']);
        $this->assertSame('https://doffin.no/notices/2026-105164', $page['props']['notices']['data'][0]['external_url']);
    }
}
