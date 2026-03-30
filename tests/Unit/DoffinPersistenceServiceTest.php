<?php

namespace Tests\Unit;

use App\Models\DoffinNotice;
use App\Models\DoffinNoticeSupplier;
use App\Models\DoffinSupplier;
use App\Services\Doffin\DoffinPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoffinPersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a_notice_supplier_and_link(): void
    {
        $summary = app(DoffinPersistenceService::class)->persist(
            [$this->noticePayload()],
            [$this->supplierRecordPayload()],
        );

        $this->assertSame(1, $summary['notices_created']);
        $this->assertSame(1, $summary['suppliers_created']);
        $this->assertSame(1, $summary['links_created']);

        $notice = DoffinNotice::query()->where('notice_id', '2026-500001')->firstOrFail();
        $supplier = DoffinSupplier::query()->where('organization_number', '917719993')->firstOrFail();
        $link = DoffinNoticeSupplier::query()->firstOrFail();

        $this->assertSame('Renholdstjenester for administrasjonsbygg', $notice->heading);
        $this->assertSame(['90910000', '90911000'], $notice->cpv_codes_json);
        $this->assertSame('4Service Eir Renhold AS', $supplier->supplier_name);
        $this->assertSame([$supplier->id], $notice->suppliers()->pluck('doffin_suppliers.id')->all());
        $this->assertSame([$notice->id], $supplier->notices()->pluck('doffin_notices.id')->all());
        $this->assertSame(['LOT-0001'], $link->winner_lots_json);
    }

    public function test_it_updates_existing_notices_and_does_not_duplicate_links(): void
    {
        $service = app(DoffinPersistenceService::class);

        $service->persist([$this->noticePayload()], [$this->supplierRecordPayload()]);

        $updatedNotice = $this->noticePayload();
        $updatedNotice['heading'] = 'Oppdatert renholdstjeneste';
        $updatedNotice['awarded_names'] = ['4Service Eir Renhold AS', 'Backup Supplier AS'];

        $summary = $service->persist([$updatedNotice], [$this->supplierRecordPayload()]);

        $this->assertSame(0, $summary['notices_created']);
        $this->assertSame(1, $summary['notices_updated']);
        $this->assertSame(0, $summary['links_created']);
        $this->assertSame(0, $summary['suppliers_created']);

        $this->assertSame(1, DoffinNotice::query()->count());
        $this->assertSame(1, DoffinSupplier::query()->count());
        $this->assertSame(1, DoffinNoticeSupplier::query()->count());
        $this->assertSame(
            'Oppdatert renholdstjeneste',
            DoffinNotice::query()->where('notice_id', '2026-500001')->value('heading'),
        );
    }

    public function test_it_reuses_a_name_only_supplier_and_upgrades_it_with_an_organization_number(): void
    {
        $service = app(DoffinPersistenceService::class);

        $nameOnlyRecord = $this->supplierRecordPayload();
        $nameOnlyRecord['organization_number'] = null;
        $nameOnlyRecord['supplier_name'] = ' Supplier One AS ';

        $service->persist([$this->noticePayload()], [$nameOnlyRecord]);

        $secondNotice = $this->noticePayload();
        $secondNotice['notice_id'] = '2026-500002';
        $secondNotice['heading'] = 'Second contract';
        $secondNotice['awarded_names'] = ['supplier one as'];

        $upgradedRecord = $this->supplierRecordPayload();
        $upgradedRecord['notice_id'] = '2026-500002';
        $upgradedRecord['heading'] = 'Second contract';
        $upgradedRecord['supplier_name'] = 'supplier one as';
        $upgradedRecord['organization_number'] = '917 719 993';

        $summary = $service->persist([$secondNotice], [$upgradedRecord]);

        $this->assertSame(1, DoffinSupplier::query()->count());
        $this->assertSame(2, DoffinNotice::query()->count());
        $this->assertSame(2, DoffinNoticeSupplier::query()->count());
        $this->assertSame(1, $summary['suppliers_updated']);

        $supplier = DoffinSupplier::query()->firstOrFail();

        $this->assertSame('917719993', $supplier->organization_number);
        $this->assertSame('supplier one as', $supplier->normalized_name);
        $this->assertCount(2, $supplier->noticeSuppliers);
    }

    private function noticePayload(): array
    {
        return [
            'notice_id' => '2026-500001',
            'notice_type' => 'RESULT',
            'heading' => 'Renholdstjenester for administrasjonsbygg',
            'publication_date' => '2026-03-29',
            'issue_date' => '2026-03-28T10:15:00Z',
            'buyer_names' => ['Høgskulen på Vestlandet'],
            'buyer_ids' => ['917641404'],
            'cpv_codes' => ['90910000', '90911000'],
            'place_of_performance' => ['Vestland'],
            'estimated_value' => [
                'amount' => 88000000,
                'currency_code' => 'NOK',
                'display' => '88000000 NOK',
            ],
            'awarded_names' => ['4Service Eir Renhold AS'],
            'raw_payload_json' => [
                'id' => '2026-500001',
                'heading' => 'Renholdstjenester for administrasjonsbygg',
            ],
        ];
    }

    private function supplierRecordPayload(): array
    {
        return [
            'supplier_name' => '4Service Eir Renhold AS',
            'organization_number' => '917719993',
            'winner_lots' => ['LOT-0001'],
            'source' => 'eform',
            'notice_id' => '2026-500001',
            'notice_type' => 'RESULT',
            'heading' => 'Renholdstjenester for administrasjonsbygg',
            'publication_date' => '2026-03-29',
            'issue_date' => '2026-03-28T10:15:00Z',
            'buyer_name' => 'Høgskulen på Vestlandet',
            'buyer_org_id' => '917641404',
            'cpv_codes' => ['90910000', '90911000'],
            'place_of_performance' => ['Vestland'],
            'estimated_value' => [
                'amount' => 88000000,
                'currency_code' => 'NOK',
                'display' => '88000000 NOK',
            ],
        ];
    }
}
