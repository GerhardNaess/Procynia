<?php

namespace Tests\Feature\App;

use App\Models\ManagementReview;
use App\Models\ManagementReviewSection;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewReportBuilder;
use App\Services\ManagementReview\ManagementReviewSectionCatalog;
use App\Support\CustomerPermissionCatalog as P;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The review as a document (plan §11): the print view and the PDF, rendered from one document model.
 *
 * What these tests defend:
 *
 *  - Both carry exactly what the reader may see on screen: a section the reader may not read — and
 *    the management's judgement and comment on it — is left out entirely, with one sentence saying
 *    some parts are restricted. A PDF can never be a way around a module's access.
 *  - A draft is marked as a draft; a finalized review says its basis is the snapshot.
 *  - The PDF is a real PDF with Norwegian letters, generated on request and never stored.
 *  - Another customer's review is a 404 (see ManagementReviewTest).
 */
class ManagementReviewReportTest extends TestCase
{
    use CreatesManagementReviewScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    public function test_the_print_view_leaves_out_what_the_reader_may_not_see(): void
    {
        [$review, $supplierReader, $plainReader] = $this->finalizedWithSupplierSecret();

        $full = $this->actingAs($supplierReader)->get("/app/management-reviews/{$review->id}/report")->assertOk()->viewData('page')['props']['document'];
        $this->assertContains('suppliers', array_column($full['sections'], 'key'));
        $this->assertStringContainsString('Hemmelig Leverandør AS', json_encode($full, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Kritisk avtale mangler', json_encode($full, JSON_UNESCAPED_UNICODE));

        $restricted = $this->actingAs($plainReader)->get("/app/management-reviews/{$review->id}/report")->assertOk()->viewData('page')['props']['document'];
        $this->assertNotContains('suppliers', array_column($restricted['sections'], 'key'));
        $this->assertStringNotContainsString('Hemmelig Leverandør AS', json_encode($restricted, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Kritisk avtale mangler', json_encode($restricted, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($restricted['restricted_note']);
        $this->assertFalse($restricted['is_draft']);
    }

    public function test_the_pdf_is_a_real_pdf_and_carries_no_more_than_the_reader_may_see(): void
    {
        [$review, $supplierReader, $plainReader] = $this->finalizedWithSupplierSecret();

        $response = $this->actingAs($supplierReader)->get("/app/management-reviews/{$review->id}/report.pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $full = $this->pdfText($response->streamedContent());
        $this->assertStringContainsString('Ledelsens gjennomgåelse 2026', $full);
        $this->assertStringContainsString('Hemmelig Leverandør AS', $full);
        $this->assertStringContainsString('Tilfredsstillende', $full);

        $restricted = $this->pdfText($this->actingAs($plainReader)->get("/app/management-reviews/{$review->id}/report.pdf")->assertOk()->streamedContent());
        $this->assertStringContainsString('Ledelsens gjennomgåelse 2026', $restricted);
        $this->assertStringNotContainsString('Hemmelig Leverandør AS', $restricted);
        $this->assertStringNotContainsString('Kritisk avtale mangler', $restricted);

        // Generated on request: nothing written to storage.
        $this->assertSame([], glob(storage_path('app/**/*.pdf')) ?: []);
    }

    public function test_a_draft_document_says_it_is_a_draft_and_reads_in_english(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager);

        $document = $this->actingAs($manager)->get("/app/management-reviews/{$review->id}/report")->viewData('page')['props']['document'];
        $this->assertTrue($document['is_draft']);
        $this->assertStringContainsString('UTKAST', $this->pdfText($this->actingAs($manager)->get("/app/management-reviews/{$review->id}/report.pdf")->streamedContent()));

        app()->setLocale('en');
        $english = app(ManagementReviewReportBuilder::class)->document($manager, $review);
        $this->assertSame('DRAFT – not finalised', $english['labels']['draft_banner']);
        $this->assertSame('Draft', $english['status']);
    }

    public function test_the_document_names_every_chosen_framework_and_claims_no_coverage_it_lacks(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager, ['frameworks' => ['iso9001', 'nis2', 'dora']]);

        $document = $this->actingAs($manager)->get("/app/management-reviews/{$review->id}/report")->assertOk()->viewData('page')['props']['document'];
        $meta = collect($document['meta'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('ISO 9001 Kvalitetsledelse, NIS2 Cybersikkerhet og regulatoriske krav, DORA Digital operasjonell motstandsdyktighet', $meta['Rammeverk']);

        $frameworks = collect($document['frameworks']);
        $this->assertSame(['ISO 9001 Kvalitetsledelse (2015)', 'NIS2 Cybersikkerhet og regulatoriske krav (2022/2555)', 'DORA Digital operasjonell motstandsdyktighet (2022/2554)'], $frameworks->pluck('name')->all());
        $this->assertSame(['unverified', 'none', 'none'], $frameworks->pluck('coverage')->all());
        $this->assertSame([[], []], $frameworks->slice(1)->pluck('inputs')->values()->all());

        $pdf = $this->pdfText($this->actingAs($manager)->get("/app/management-reviews/{$review->id}/report.pdf")->assertOk()->streamedContent());
        $this->assertStringContainsString('NIS2 Cybersikkerhet og regulatoriske krav', $pdf);
        $this->assertStringContainsString('Automatisk dekningsanalyse er ikke tilgjengelig', $pdf);
        $this->assertStringContainsString('ikke faglig verifisert', $pdf);
    }

    /** @return array{0: ManagementReview, 1: User, 2: User} */
    private function finalizedWithSupplierSecret(): array
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $manager = $this->mrManager($customer, [P::SUPPLIER_VIEW]);
        $supplier = new Supplier(['customer_id' => $customer->id, 'name' => 'Hemmelig Leverandør AS', 'category' => 'it_cloud', 'deliverable_description' => 'Drift']);
        $supplier->status = Supplier::STATUS_ACTIVE;
        $supplier->save();

        $review = $this->mrReview($manager);
        $review->participants()->create(['customer_id' => $customer->id, 'name' => 'Kari Nordmann', 'user_id' => $manager->id]);
        $review->forceFill(['conclusion' => 'Styringssystemet er egnet – også for økte krav.', 'meeting_date' => '2026-07-01'])->save();

        foreach (array_keys(ManagementReviewSectionCatalog::DEFINITIONS) as $key) {
            ManagementReviewSection::query()->create([
                'customer_id' => $customer->id, 'management_review_id' => $review->id, 'section_key' => $key,
                'judgement' => 'satisfactory', 'comment' => $key === 'suppliers' ? 'Kritisk avtale mangler for én leverandør.' : null,
            ]);
        }

        $this->actingAs($manager);
        $review = app(ManagementReviewFinalizationService::class)->finalize($manager, $review->fresh());

        return [$review, $this->mrReader($customer, [P::SUPPLIER_VIEW]), $this->mrReader($customer)];
    }

    private function pdfText(string $pdf): string
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $path = tempnam(sys_get_temp_dir(), 'mr-pdf-');
        file_put_contents($path, $pdf);

        $process = new Process([(string) config('services.pdftotext.binary', 'pdftotext'), '-layout', $path, '-']);
        $process->mustRun();
        @unlink($path);

        return preg_replace('/\s+/u', ' ', $process->getOutput());
    }
}
