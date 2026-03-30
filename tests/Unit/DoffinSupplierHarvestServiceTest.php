<?php

namespace Tests\Unit;

use App\Jobs\Doffin\PrepareDoffinSupplierHarvestRun;
use App\Models\DoffinSupplierHarvestRun;
use App\Models\DoffinSupplierHarvestRunNotice;
use App\Models\User;
use App\Services\Doffin\DoffinNoticeParser;
use App\Services\Doffin\DoffinPersistenceService;
use App\Services\Doffin\DoffinPublicClient;
use App\Services\Doffin\DoffinSupplierHarvestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DoffinSupplierHarvestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_start_run_creates_a_queued_harvest_run_and_dispatches_the_prepare_job(): void
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
            'types' => ['RESULT'],
        ], $creator);

        $this->assertSame(DoffinSupplierHarvestRun::STATUS_QUEUED, $run->status);
        $this->assertSame($creator->id, $run->created_by);
        $this->assertSame(['RESULT'], $run->notice_type_filters);
        $this->assertNotSame('', $run->uuid);

        Queue::assertPushed(PrepareDoffinSupplierHarvestRun::class, function (PrepareDoffinSupplierHarvestRun $job) use ($run): bool {
            return $job->runId === $run->id;
        });
    }

    public function test_prepare_run_creates_notice_rows_and_dispatches_a_batch(): void
    {
        Bus::fake();

        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService(publicClient: $publicClient);
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'status' => DoffinSupplierHarvestRun::STATUS_QUEUED,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'notice_type_filters' => ['RESULT'],
        ]);

        $publicClient->shouldReceive('search')
            ->once()
            ->andReturn([
                'numHitsTotal' => 2,
                'numHitsAccessible' => 2,
                'hits' => [
                    ['id' => '2026-200001'],
                    ['id' => '2026-200002'],
                ],
            ]);

        $service->prepareRun($run);

        $run->refresh();

        $this->assertSame(DoffinSupplierHarvestRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, $run->total_items);
        $this->assertNotNull($run->started_at);
        $this->assertDatabaseCount('doffin_supplier_harvest_run_notices', 2);

        Bus::assertBatched(function ($batch) use ($run): bool {
            return $batch->name === "doffin_supplier_harvest_run_{$run->uuid}"
                && count($batch->jobs) === 2;
        });
    }

    public function test_process_notice_success_upserts_suppliers_and_updates_counters_and_eta(): void
    {
        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService(publicClient: $publicClient);
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'status' => DoffinSupplierHarvestRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 2,
            'started_at' => now()->subSeconds(10),
        ]);

        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200001',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);
        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200002',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);

        $publicClient->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-200001')
            ->andReturn($this->successfulNoticeDetail('2026-200001', 'Supplier One AS'));

        $service->processNotice($run, '2026-200001');

        $run->refresh();

        $this->assertSame(1, $run->processed_items);
        $this->assertSame(1, $run->harvested_suppliers);
        $this->assertSame(0, $run->failed_items);
        $this->assertSame('50.00', $run->getRawOriginal('progress_percent'));
        $this->assertIsInt($run->estimated_seconds_remaining);
        $this->assertGreaterThan(0, $run->estimated_seconds_remaining);
        $this->assertDatabaseHas('doffin_suppliers', [
            'supplier_name' => 'Supplier One AS',
            'organization_number' => '987654321',
        ]);
        $this->assertDatabaseHas('doffin_notice_suppliers', [
            'doffin_notice_id' => 1,
            'doffin_supplier_id' => 1,
        ]);
        $this->assertDatabaseHas('doffin_supplier_harvest_run_notices', [
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200001',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_COMPLETED,
            'supplier_count' => 1,
        ]);
    }

    public function test_processing_the_same_supplier_again_does_not_create_duplicates(): void
    {
        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService(publicClient: $publicClient);
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'status' => DoffinSupplierHarvestRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 2,
            'started_at' => now()->subSeconds(10),
        ]);

        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200011',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);
        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200012',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);

        $publicClient->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-200011')
            ->andReturn($this->successfulNoticeDetailWithoutOrganizationNumber('2026-200011', 'Supplier Without Org'));
        $publicClient->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-200012')
            ->andReturn($this->successfulNoticeDetailWithoutOrganizationNumber('2026-200012', 'Supplier Without Org'));

        $service->processNotice($run, '2026-200011');
        $service->processNotice($run, '2026-200012');

        $this->assertSame(1, \App\Models\DoffinSupplier::query()->count());
        $this->assertDatabaseHas('doffin_suppliers', [
            'supplier_name' => 'Supplier Without Org',
            'organization_number' => null,
            'normalized_name' => 'supplier without org',
        ]);
    }

    public function test_process_notice_failure_updates_failed_counters_and_error_message(): void
    {
        $publicClient = Mockery::mock(DoffinPublicClient::class);
        $service = $this->makeService(publicClient: $publicClient);
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'status' => DoffinSupplierHarvestRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 1,
            'started_at' => now()->subSeconds(12),
        ]);

        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200099',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);

        $publicClient->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-200099')
            ->andThrow(new RuntimeException('Doffin request failed.'));

        $service->processNotice($run, '2026-200099');

        $run->refresh();

        $this->assertSame(DoffinSupplierHarvestRun::STATUS_RUNNING, $run->status);
        $this->assertSame(1, $run->processed_items);
        $this->assertSame(0, $run->harvested_suppliers);
        $this->assertSame(1, $run->failed_items);
        $this->assertDatabaseHas('doffin_supplier_harvest_run_notices', [
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200099',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_FAILED,
            'supplier_count' => 0,
            'error_message' => 'Doffin request failed.',
        ]);
    }

    public function test_complete_run_marks_the_run_as_completed(): void
    {
        $service = $this->makeService();
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => '55555555-5555-4555-8555-555555555555',
            'status' => DoffinSupplierHarvestRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 1,
            'processed_items' => 1,
            'harvested_suppliers' => 1,
            'started_at' => now()->subSeconds(20),
        ]);

        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200001',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_COMPLETED,
            'supplier_count' => 1,
            'processed_at' => now()->subSeconds(5),
        ]);

        $completed = $service->completeRun($run->id, 'batch-uuid-1');

        $this->assertInstanceOf(DoffinSupplierHarvestRun::class, $completed);
        $this->assertSame(DoffinSupplierHarvestRun::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame(0, $completed->estimated_seconds_remaining);
    }

    public function test_refresh_run_progress_returns_null_eta_when_no_items_have_been_processed(): void
    {
        $service = $this->makeService();
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => '66666666-6666-4666-8666-666666666666',
            'status' => DoffinSupplierHarvestRun::STATUS_RUNNING,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-07',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 2,
            'started_at' => now()->subSeconds(10),
        ]);

        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200201',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);
        DoffinSupplierHarvestRunNotice::query()->create([
            'doffin_supplier_harvest_run_id' => $run->id,
            'notice_id' => '2026-200202',
            'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
        ]);

        $refreshed = $service->refreshRunProgress($run);

        $this->assertSame(0, $refreshed->processed_items);
        $this->assertNull($refreshed->estimated_seconds_remaining);
        $this->assertSame('0.00', $refreshed->getRawOriginal('progress_percent'));
    }

    private function makeService(
        ?DoffinPublicClient $publicClient = null,
        ?DoffinNoticeParser $noticeParser = null,
        ?DoffinPersistenceService $persistenceService = null,
    ): DoffinSupplierHarvestService {
        return new DoffinSupplierHarvestService(
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

    private function successfulNoticeDetailWithoutOrganizationNumber(string $noticeId, string $supplierName): array
    {
        return [
            'id' => $noticeId,
            'noticeType' => 'RESULT',
            'heading' => 'Test contract without org',
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
                        ['label' => 'Vinner av disse delkontraktene', 'value' => 'LOT-0001'],
                    ],
                ],
            ],
        ];
    }
}
