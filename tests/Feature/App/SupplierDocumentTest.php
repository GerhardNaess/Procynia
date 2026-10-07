<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 5: the dokumentasjonsoversikt (docs/supplier-management-v1-plan.md
 * §4.4, §6.3, §6.4, §9.2, §10). One test per rule:
 *
 *  - a row is registered, corrected, renewed and deleted; its type and validity are checked; its
 *    status is computed on read; a renewal keeps the old row as Erstattet;
 *  - supplier.edit writes, supplier.view only reads, supplier.assess and supplier.delete write
 *    nothing; an ended supplier is read-only; another customer's supplier or document is 404 and
 *    cannot be named by a row;
 *  - a supplier with documentation is ended, never deleted.
 */
class SupplierDocumentTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
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
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_documentation_is_registered_corrected_renewed_and_deleted_with_its_validity_computed_on_read(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $url = "/app/supplier-management/{$supplier->id}/documents";

        // Each rule refused before anything is written: the type, the name, validity running backwards.
        foreach ([
            'document_type' => ['document_type' => 'contract_value'],
            'title' => ['title' => '  '],
            'valid_until' => ['valid_from' => '2026-06-01', 'valid_until' => '2026-05-31'],
        ] as $field => $override) {
            $this->actingAs($editor)->post($url, $this->documentPayload($override))->assertSessionHasErrors($field);
        }
        $this->assertFalse($supplier->documents()->exists());

        // Four rows, one per status the page can show; the location is kept as given, never fetched.
        $this->actingAs($editor)->post($url, $this->documentPayload([
            'document_type' => 'certificate', 'title' => 'ISO 27001-sertifikat 2025', 'valid_from' => '2025-10-01', 'valid_until' => '2026-10-06',
            'location' => 'https://contoso.sharepoint.com/sites/innkjop/iso.pdf',
        ]))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post($url, $this->documentPayload(['document_type' => 'agreement', 'title' => 'Rammeavtale drift', 'valid_until' => '2026-10-07']))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post($url, $this->documentPayload(['document_type' => 'confidentiality_agreement', 'title' => 'Taushetserklæring', 'location' => 'Arkiv sak 2026/114']))->assertSessionHasNoErrors();

        $certificate = $supplier->documents()->where('title', 'ISO 27001-sertifikat 2025')->sole();
        $this->assertSame(['https://contoso.sharepoint.com/sites/innkjop/iso.pdf', $editor->id], [$certificate->location, (int) $certificate->created_by]);
        $this->assertSame(
            ['ISO 27001-sertifikat 2025' => 'expired', 'Rammeavtale drift' => 'valid', 'Taushetserklæring' => 'no_expiry'],
            $this->statuses($editor, $supplier),
        );

        // Corrected in place: a mutable row, not history.
        $this->actingAs($editor)->patch("{$url}/{$certificate->id}", $this->documentPayload([
            'document_type' => 'certificate', 'title' => 'ISO 27001-sertifikat', 'valid_from' => '2025-10-01', 'valid_until' => '2026-10-06', 'comment' => 'Utstedt av DNV.',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(['ISO 27001-sertifikat', 'Utstedt av DNV.'], [$certificate->fresh()->title, $certificate->fresh()->comment]);

        // Registrer fornyet: a new row of the same type whatever the form says; the old one stays, Erstattet.
        $this->actingAs($editor)->post("{$url}/{$certificate->id}/renew", $this->documentPayload([
            'document_type' => 'other', 'title' => 'ISO 27001-sertifikat 2026', 'valid_from' => '2026-10-01', 'valid_until' => '2027-10-01',
        ]))->assertSessionHasNoErrors();
        $renewed = $supplier->documents()->where('title', 'ISO 27001-sertifikat 2026')->sole();
        $this->assertSame(['certificate', $renewed->id], [$renewed->document_type, (int) $certificate->fresh()->replaced_by_document_id]);
        $this->assertSame(
            ['ISO 27001-sertifikat 2026' => 'valid', 'Rammeavtale drift' => 'valid', 'Taushetserklæring' => 'no_expiry', 'ISO 27001-sertifikat' => 'replaced'],
            $this->statuses($editor, $supplier),
        );
        // A renewed row is not renewed twice.
        $this->actingAs($editor)->post("{$url}/{$certificate->id}/renew", $this->documentPayload())->assertSessionHasErrors('title');
        $this->assertSame(4, $supplier->documents()->count());

        // Deleting the renewal by mistake makes the old row current again.
        $this->actingAs($editor)->delete("{$url}/{$renewed->id}")->assertSessionHas('success');
        $this->assertNull($certificate->fresh()->replaced_by_document_id);
        $this->assertSame('expired', $this->statuses($editor, $supplier)['ISO 27001-sertifikat']);
    }

    public function test_edit_writes_view_reads_assess_does_not_ended_is_read_only_and_other_customers_are_404(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Aktiv AS');
        $url = "/app/supplier-management/{$supplier->id}/documents";
        $this->actingAs($editor)->post($url, $this->documentPayload())->assertSessionHasNoErrors();
        $document = $supplier->documents()->sole();

        // supplier.view reads; neither it, supplier.assess nor supplier.delete writes.
        $reader = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $page = $this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['Databehandleravtale'], array_column($page['documents'], 'title'));
        $this->assertSame([false, false], [$page['permissions']['can_manage_documents'], $page['permissions']['has_edit_right']]);
        $this->actingAs($reader)->post($url, $this->documentPayload())->assertForbidden();
        $this->actingAs($reader)->patch("{$url}/{$document->id}", $this->documentPayload(['title' => 'Endret']))->assertForbidden();
        $this->actingAs($reader)->post("{$url}/{$document->id}/renew", $this->documentPayload())->assertForbidden();
        $this->actingAs($reader)->delete("{$url}/{$document->id}")->assertForbidden();
        $this->assertSame(['Databehandleravtale', 1], [$document->fresh()->title, $supplier->documents()->count()]);

        // Ended: still shown, nothing written until it is reopened.
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post($url, $this->documentPayload())->assertSessionHas('error');
        $this->actingAs($editor)->patch("{$url}/{$document->id}", $this->documentPayload(['title' => 'Endret']))->assertSessionHas('error');
        $this->actingAs($editor)->post("{$url}/{$document->id}/renew", $this->documentPayload())->assertSessionHas('error');
        $this->actingAs($editor)->delete("{$url}/{$document->id}")->assertSessionHas('error');
        $this->assertSame(['Databehandleravtale', 1, null], [$document->fresh()->title, $supplier->documents()->count(), $document->fresh()->replaced_by_document_id]);
        $page = $this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([false, true, 1], [$page['permissions']['can_manage_documents'], $page['permissions']['has_edit_right'], count($page['documents'])]);

        // Another customer's supplier and document: 404 whichever way they are named, and no row can cross.
        $otherEditor = $this->supplierUser($other, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $foreign = $this->supplier($other, $otherEditor, 'Fremmed AS');
        $this->actingAs($otherEditor)->post("/app/supplier-management/{$foreign->id}/documents", $this->documentPayload(['title' => 'Fremmed avtale']))->assertSessionHasNoErrors();
        $foreignDocument = $foreign->documents()->sole();
        $this->actingAs($editor)->post("/app/supplier-management/{$foreign->id}/documents", $this->documentPayload())->assertNotFound();
        $this->actingAs($editor)->patch("/app/supplier-management/{$foreign->id}/documents/{$foreignDocument->id}", $this->documentPayload())->assertNotFound();
        $this->actingAs($editor)->delete("{$url}/{$foreignDocument->id}")->assertNotFound();
        $this->actingAs($otherEditor)->get("/app/supplier-management/{$supplier->id}")->assertNotFound();
        $this->assertSame('Fremmed avtale', $foreignDocument->fresh()->title);
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_documents')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $foreign->id, 'document_type' => 'agreement', 'title' => 'x',
        ]), 'documentation across customers');
    }

    public function test_a_supplier_with_documentation_is_ended_never_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $supplier = $this->supplier($customer, $manager, 'Dokumentert AS');
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/documents", $this->documentPayload())->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->actingAs($manager)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with documentation');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_documents')->where('supplier_id', $supplier->id)->update(['valid_from' => '2027-01-01', 'valid_until' => '2026-01-01']), 'validity running backwards');
        $this->assertTrue(Supplier::query()->whereKey($supplier->id)->exists());
    }

    /** @return array<string, string> title => status, in the order the page lists them */
    private function statuses($user, Supplier $supplier): array
    {
        $documents = $this->actingAs($user)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props']['documents'];

        return array_column($documents, 'status', 'title');
    }

    /** @return array<string, mixed> */
    private function documentPayload(array $overrides = []): array
    {
        return array_merge([
            'document_type' => 'data_processing_agreement',
            'title' => 'Databehandleravtale',
            'location' => '',
            'valid_from' => '',
            'valid_until' => '',
            'comment' => '',
        ], $overrides);
    }
}
