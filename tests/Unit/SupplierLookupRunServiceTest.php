<?php

namespace Tests\Unit;

use App\Jobs\Doffin\PrepareSupplierLookupRun;
use App\Models\SupplierLookupRun;
use App\Models\SupplierLookupRunNotice;
use App\Models\User;
use App\Services\Doffin\DoffinNoticeParser;
use App\Services\Doffin\DoffinPersistenceService;
use App\Services\Doffin\DoffinPublicClient;
use App\Services\Doffin\DoffinSupplierLookupService;
use App\Services\Doffin\SupplierLookupRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SupplierLookupRunServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_start_run_creates_a_queued_run_and_dispatches_the_prepare_job(): void
    {
        Queue::fake();

        $service = $this->makeService();
        $creator = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);

        $run = $service->startRun([
            'from' => '2026-03-01',
            'to' => '2026-03-29',
            'supplier_name' => 'Target Supplier AS',
            'types' => ['RESULT'],
        ], $creator);

        $this->assertSame(SupplierLookupRun::STATUS_QUEUED, $run->status);
        $this->assertSame('Target Supplier AS', $run->supplier_query);
        $this->assertSame($creator->id, $run->created_by);
        $this->assertNotSame('', $run->uuid);

        Queue::assertPushed(PrepareSupplierLookupRun::class, function (PrepareSupplierLookupRun $job) use ($run): bool {
            return $job->runId === $run->id;
        });
    }

    public function test_prepare_run_creates_notice_items_and_dispatches_a_batch(): void
    {
        Bus::fake();

        $supplierLookupService = Mockery::mock(DoffinSupplierLookupService::class);
        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService($supplierLookupService, $publicClient);
        $run = SupplierLookupRun::query()->create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'status' => SupplierLookupRun::STATUS_QUEUED,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'supplier_query' => 'Target Supplier AS',
            'notice_type_filters' => ['RESULT'],
        ]);

        $supplierLookupService->shouldReceive('resolveCandidate')
            ->once()
            ->with('Target Supplier AS')
            ->andReturn([
                'selected_candidate' => [
                    'id' => 'winner-123',
                    'value' => 'Target Supplier AS',
                ],
                'winner_candidates' => [
                    ['id' => 'winner-123', 'value' => 'Target Supplier AS'],
                ],
            ]);

        $publicClient->shouldReceive('search')
            ->once()
            ->andReturn([
                'numHitsTotal' => 2,
                'numHitsAccessible' => 2,
                'hits' => [
                    ['id' => '2026-100001'],
                    ['id' => '2026-100002'],
                ],
            ]);

        $service->prepareRun($run);

        $run->refresh();

        $this->assertSame(SupplierLookupRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, $run->total_items);
        $this->assertSame('winner-123', $run->resolved_winner_id);
        $this->assertSame('Target Supplier AS', $run->resolved_winner_label);
        $this->assertDatabaseCount('supplier_lookup_run_notices', 2);

        Bus::assertBatched(function ($batch) use ($run): bool {
            return $batch->name === "supplier_lookup_run_{$run->uuid}"
                && count($batch->jobs) === 2;
        });
    }

    public function test_process_notice_success_updates_progress_eta_and_matches(): void
    {
        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService(
            publicClient: $publicClient,
        );
        $run = SupplierLookupRun::query()->create([
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'status' => SupplierLookupRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'supplier_query' => 'Target Supplier AS',
            'resolved_winner_label' => 'Target Supplier AS',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 1,
            'started_at' => now()->subSeconds(10),
        ]);

        SupplierLookupRunNotice::query()->create([
            'supplier_lookup_run_id' => $run->id,
            'notice_id' => '2026-100001',
            'status' => SupplierLookupRunNotice::STATUS_QUEUED,
        ]);

        $publicClient->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-100001')
            ->andReturn($this->successfulNoticeDetail('2026-100001', 'Target Supplier AS'));

        $service->processNotice($run, '2026-100001');

        $run->refresh();

        $this->assertSame(1, $run->processed_items);
        $this->assertSame(1, $run->matched_items);
        $this->assertSame(0, $run->failed_items);
        $this->assertSame('100.00', $run->getRawOriginal('progress_percent'));
        $this->assertSame(0, $run->estimated_seconds_remaining);
        $this->assertDatabaseHas('doffin_notices', [
            'notice_id' => '2026-100001',
        ]);
        $this->assertDatabaseHas('doffin_suppliers', [
            'supplier_name' => 'Target Supplier AS',
        ]);
        $this->assertDatabaseHas('supplier_lookup_run_notices', [
            'supplier_lookup_run_id' => $run->id,
            'notice_id' => '2026-100001',
            'status' => SupplierLookupRunNotice::STATUS_COMPLETED,
            'matched' => true,
        ]);
    }

    public function test_process_notice_failure_updates_failed_items_without_failing_the_entire_run(): void
    {
        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService(
            publicClient: $publicClient,
        );
        $run = SupplierLookupRun::query()->create([
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'status' => SupplierLookupRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'supplier_query' => 'Target Supplier AS',
            'resolved_winner_label' => 'Target Supplier AS',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 1,
            'started_at' => now()->subSeconds(12),
        ]);

        SupplierLookupRunNotice::query()->create([
            'supplier_lookup_run_id' => $run->id,
            'notice_id' => '2026-100099',
            'status' => SupplierLookupRunNotice::STATUS_QUEUED,
        ]);

        $publicClient->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-100099')
            ->andThrow(new RuntimeException('Doffin request failed.'));

        $service->processNotice($run, '2026-100099');

        $run->refresh();

        $this->assertSame(SupplierLookupRun::STATUS_RUNNING, $run->status);
        $this->assertSame(1, $run->processed_items);
        $this->assertSame(0, $run->matched_items);
        $this->assertSame(1, $run->failed_items);
        $this->assertDatabaseHas('supplier_lookup_run_notices', [
            'supplier_lookup_run_id' => $run->id,
            'notice_id' => '2026-100099',
            'status' => SupplierLookupRunNotice::STATUS_FAILED,
            'matched' => false,
        ]);
    }

    public function test_complete_run_marks_the_run_as_completed(): void
    {
        $service = $this->makeService();
        $run = SupplierLookupRun::query()->create([
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'status' => SupplierLookupRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'supplier_query' => 'Target Supplier AS',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 1,
            'processed_items' => 1,
            'started_at' => now()->subSeconds(20),
        ]);

        SupplierLookupRunNotice::query()->create([
            'supplier_lookup_run_id' => $run->id,
            'notice_id' => '2026-100001',
            'status' => SupplierLookupRunNotice::STATUS_COMPLETED,
            'matched' => true,
            'processed_at' => now()->subSeconds(5),
        ]);

        $completed = $service->completeRun($run->id, 'batch-uuid-1');

        $this->assertInstanceOf(SupplierLookupRun::class, $completed);
        $this->assertSame(SupplierLookupRun::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame(0, $completed->estimated_seconds_remaining);
    }

    public function test_status_payload_for_uuid_returns_the_expected_structure(): void
    {
        $service = $this->makeService();
        $run = SupplierLookupRun::query()->create([
            'uuid' => '55555555-5555-4555-8555-555555555555',
            'status' => SupplierLookupRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'supplier_query' => 'Target Supplier AS',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 12,
            'processed_items' => 4,
            'matched_items' => 3,
            'failed_items' => 1,
            'progress_percent' => 33.33,
            'estimated_seconds_remaining' => 80,
            'started_at' => now()->subMinutes(2),
        ]);

        $payload = $service->statusPayloadForUuid($run->uuid);

        $this->assertIsArray($payload);
        $this->assertSame('55555555-5555-4555-8555-555555555555', $payload['run_uuid']);
        $this->assertSame('running', $payload['status']);
        $this->assertSame(12, $payload['total_items']);
        $this->assertSame(4, $payload['processed_items']);
        $this->assertSame(3, $payload['matched_items']);
        $this->assertSame(1, $payload['failed_items']);
        $this->assertSame(80, $payload['estimated_seconds_remaining']);
    }

    private function makeService(
        ?DoffinSupplierLookupService $supplierLookupService = null,
        ?DoffinPublicClient $publicClient = null,
        ?DoffinNoticeParser $noticeParser = null,
        ?DoffinPersistenceService $persistenceService = null,
    ): SupplierLookupRunService {
        return new SupplierLookupRunService(
            $supplierLookupService ?? Mockery::mock(DoffinSupplierLookupService::class),
            $publicClient ?? Mockery::mock(DoffinPublicClient::class),
            $noticeParser ?? app(DoffinNoticeParser::class),
            $persistenceService ?? app(DoffinPersistenceService::class),
        );
    }

    private function successfulNoticeDetail(string $noticeId, string $supplierName): array
    {
        return [
            'id' => $noticeId,
            'noticeType' => 'RESULT',
            'heading' => 'Test contract',
            'publicationDate' => '2026-03-05',
            'issueDate' => '2026-03-01T10:00:00Z',
            'buyer' => [
                [
                    'id' => '123456789',
                    'name' => 'Buyer AS',
                ],
            ],
            'allCpvCodes' => ['90910000'],
            'placeOfPerformance' => ['Oslo'],
            'estimatedValue' => [
                'amount' => 100000,
                'currencyCode' => 'NOK',
                'fullLocalizedText' => '100000 NOK',
            ],
            'awardedNames' => [$supplierName],
            'eform' => [
                [
                    'value' => 'ORG-0001',
                    'sections' => [
                        ['label' => 'Offisielt navn', 'value' => $supplierName],
                        ['label' => 'Organisasjonsnummer', 'value' => '987654321'],
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0001'],
                    ],
                ],
            ],
        ];
    }
}
