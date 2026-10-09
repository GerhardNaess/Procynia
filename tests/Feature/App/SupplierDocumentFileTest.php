<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluationDocument;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use App\Support\PrivateFiles\PrivateFileScanStatus;
use App\Support\PrivateFiles\PrivateFileStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;
use ZipArchive;

/**
 * Leverandøroppfølging v2.1: one private file per documentation row (docs/supplier-assurance-v2-plan.md
 * §27), through the shared App\Support\PrivateFiles core. One test per rule:
 *
 *  - a file is stored privately under the customer's prefix with a random name, the type decided
 *    from its content and SHA-256 computed on the server; the page never sees the storage path;
 *  - forged, broken, macro-enabled, disallowed and oversized files are refused and leave nothing;
 *  - downloading needs supplier.view, is isolated per customer and is always an attachment with
 *    nosniff and no-store; a file whose scan status is not downloadable is refused;
 *  - a file is replaced and removed by supplier.edit or supplier.assure, the old one deleted only
 *    after the change commits;
 *  - a row a control rests on keeps its file, and the control keeps the file's key and SHA-256;
 *  - an ended supplier is read-only for files but still downloadable;
 *  - a refused write leaves no file; private-files:prune-orphans removes only unreferenced, old files.
 */
class SupplierDocumentFileTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Storage::fake('local');

        // As DatabaseTransactions does: after-commit callbacks (deleting a replaced file) run when the
        // code's own transaction commits, not when the test's outer transaction would.
        $manager = new DatabaseTransactionsManager([DB::getDefaultConnection()]);
        $this->app->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
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

    public function test_a_file_is_stored_privately_with_its_type_decided_by_content_and_its_sha256_computed_on_the_server(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $pdf = $this->pdf();

        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/documents", $this->payload([
            'file' => UploadedFile::fake()->createWithContent('ISO 27001 sertifikat.pdf', $pdf),
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        $document = SupplierDocument::query()->sole();
        $this->assertSame(26, strlen((string) $document->file_key));
        $this->assertSame("customers/{$customer->id}/supplier-documents/{$document->file_key}.pdf", $document->file_path);
        $this->assertSame(
            ['ISO 27001 sertifikat.pdf', 'application/pdf', strlen($pdf), hash('sha256', $pdf), PrivateFileScanStatus::NOT_SCANNED, $editor->id],
            [$document->file_original_name, $document->file_mime_type, $document->file_size_bytes, $document->file_sha256, $document->file_scan_status, $document->file_uploaded_by],
        );
        $this->assertNotNull($document->file_uploaded_at);
        $this->assertSame($pdf, Storage::disk('local')->get($document->file_path));

        // The database refuses a file outside the customer's own prefix, and a half-recorded file.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_documents')->where('id', $document->id)->update(['file_path' => 'customers/'.($customer->id + 1)."/supplier-documents/{$document->file_key}.pdf"]), 'a file under another customer');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_documents')->where('id', $document->id)->update(['file_sha256' => null]), 'a file without its checksum');

        // The page shows the file, never where it is stored.
        $response = $this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->assertOk();
        $row = $response->viewData('page')['props']['documents'][0];
        $this->assertSame(['ISO 27001 sertifikat.pdf', 'application/pdf', strlen($pdf), true, false], [$row['file']['name'], $row['file']['mime_type'], $row['file']['size_bytes'], $row['file']['downloadable'], $row['file_locked']]);
        $this->assertSame("/app/supplier-management/{$supplier->id}/documents/{$document->id}/file", $row['file']['download_url']);
        $this->assertStringNotContainsString('supplier-documents/', json_encode($response->viewData('page')['props']));

        // Every allowed type, each checked by its content; «.jpeg» is a JPG.
        foreach ([
            ['Avtale.docx', $this->office('word/document.xml'), 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['Underleverandører.xlsx', $this->office('xl/workbook.xml'), 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['Skjermbilde.png', $this->image('png'), 'png', 'image/png'],
            ['Foto.jpeg', $this->image('jpg'), 'jpg', 'image/jpeg'],
        ] as [$name, $content, $extension, $mime]) {
            $row = $this->row($supplier, $name);
            $this->actingAs($editor)->post($this->fileUrl($supplier, $row), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertSessionHasNoErrors();
            $row->refresh();
            $this->assertSame([$mime, hash('sha256', $content)], [$row->file_mime_type, $row->file_sha256], $name);
            $this->assertStringEndsWith(".{$extension}", (string) $row->file_path);
        }
    }

    public function test_forged_broken_macro_disallowed_and_oversized_files_are_refused_and_leave_nothing(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $row = $this->row($supplier, 'Avtale');

        foreach ([
            'a PNG named .pdf' => UploadedFile::fake()->createWithContent('avtale.pdf', $this->image('png')),
            'a PDF named .docx' => UploadedFile::fake()->createWithContent('avtale.docx', $this->pdf()),
            'text named .pdf' => UploadedFile::fake()->createWithContent('avtale.pdf', 'Dette er ikke en PDF.'),
            'a PDF cut short' => UploadedFile::fake()->createWithContent('avtale.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >>"),
            'a broken image' => UploadedFile::fake()->createWithContent('bilde.png', "\x89PNG\r\n\x1a\nikke et bilde"),
            'a macro-enabled Word file' => UploadedFile::fake()->createWithContent('avtale.docx', $this->office('word/document.xml', withMacros: true)),
            'HTML' => UploadedFile::fake()->createWithContent('side.html', '<script>alert(1)</script>'),
            'SVG' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'an executable' => UploadedFile::fake()->createWithContent('program.exe', "MZ\x90\x00"),
            'an empty file' => UploadedFile::fake()->createWithContent('tom.pdf', ''),
        ] as $case => $file) {
            $this->actingAs($editor)->post($this->fileUrl($supplier, $row), ['file' => $file])
                ->assertSessionHasErrors(['file' => __('procynia.supplier_management.validation.file_type')], errorBag: 'default');
            $this->assertNull($row->fresh()->file_path, $case);
        }

        // Over 20 MB, with or without a new row; nothing is written.
        $big = UploadedFile::fake()->create('stor.pdf', 20481, 'application/pdf');
        $this->actingAs($editor)->post($this->fileUrl($supplier, $row), ['file' => $big])->assertSessionHasErrors(['file' => __('procynia.supplier_management.validation.file_size')]);
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/documents", $this->payload(['file' => $big]))->assertSessionHasErrors('file');
        $this->actingAs($editor)->post($this->fileUrl($supplier, $row), [])->assertSessionHasErrors('file');

        $this->assertSame(1, SupplierDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_downloading_needs_supplier_view_is_isolated_per_customer_and_is_always_a_private_attachment(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $pdf = $this->pdf();
        $document = $this->withFile($editor, $supplier, $this->row($supplier, 'Databehandleravtale'), 'Databehandleravtale «Drift» 2026.pdf', $pdf);
        $url = "/app/supplier-management/{$supplier->id}/documents/{$document->id}/file";

        $response = $this->actingAs($reader)->get($url)->assertOk();
        $this->assertSame($pdf, $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('Databehandleravtale «Drift» 2026.pdf'), $disposition);

        // Without supplier.view, as System Owner without a role, or with a module-less customer: refused.
        $outsider = $this->member($customer);
        $this->actingAs($outsider)->get($url)->assertForbidden();
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->actingAs($systemOwner)->get($url)->assertForbidden();

        // Another customer's person, another supplier's row, a row without a file: 404.
        $foreignReader = $this->supplierUser($foreignCustomer, []);
        $this->actingAs($foreignReader)->get($url)->assertNotFound();
        $other = $this->supplier($customer, $editor, 'Annen AS');
        $this->actingAs($reader)->get("/app/supplier-management/{$other->id}/documents/{$document->id}/file")->assertNotFound();
        $this->actingAs($reader)->get($this->fileUrl($supplier, $this->row($supplier, 'Uten fil')))->assertNotFound();

        // The file must be where the row says, inside the customer's prefix: gone from storage → 404.
        Storage::disk('local')->move((string) $document->file_path, 'flyttet.pdf');
        $this->actingAs($reader)->get($url)->assertNotFound();
        Storage::disk('local')->move('flyttet.pdf', (string) $document->file_path);

        // Scan status: infected is never served; with clean scans required only clean is.
        $document->forceFill(['file_scan_status' => PrivateFileScanStatus::INFECTED])->save();
        $this->actingAs($reader)->get($url)->assertForbidden();
        $this->assertFalse($this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['documents'][0]['file']['downloadable']);
        config(['private_files.require_clean_scan' => true]);
        $document->forceFill(['file_scan_status' => PrivateFileScanStatus::NOT_SCANNED])->save();
        $this->actingAs($reader)->get($url)->assertForbidden();
        $document->forceFill(['file_scan_status' => PrivateFileScanStatus::CLEAN])->save();
        $this->actingAs($reader)->get($url)->assertOk();
    }

    public function test_a_file_is_replaced_and_removed_by_edit_or_assure_and_the_old_file_is_deleted_after_commit(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $row = $this->row($supplier, 'Sertifikat');

        // supplier.view, supplier.assess and supplier.delete change no file.
        foreach ([[], [CustomerPermissionCatalog::SUPPLIER_ASSESS], [CustomerPermissionCatalog::SUPPLIER_DELETE]] as $keys) {
            $this->actingAs($this->supplierUser($customer, $keys))->post($this->fileUrl($supplier, $row), ['file' => UploadedFile::fake()->createWithContent('a.pdf', $this->pdf())])->assertForbidden();
        }

        $first = $this->withFile($editor, $supplier, $row, 'Sertifikat 2025.pdf', $this->pdf('2025'));
        $firstPath = (string) $first->file_path;

        // Replaced by the person who controls: the new file is in place and the old one is gone.
        $second = $this->withFile($assurer, $supplier, $first, 'Sertifikat 2026.pdf', $this->pdf('2026'));
        $this->assertNotSame($firstPath, $second->file_path);
        $this->assertSame([hash('sha256', $this->pdf('2026')), $assurer->id], [$second->file_sha256, $second->file_uploaded_by]);
        $this->assertTrue(Storage::disk('local')->exists((string) $second->file_path));
        $this->assertFalse(Storage::disk('local')->exists($firstPath));

        // Removed: the row stays, without a file, and the file is gone.
        $this->actingAs($editor)->delete($this->fileUrl($supplier, $second))->assertSessionHasNoErrors()->assertSessionHas('success');
        $removed = $second->fresh();
        $this->assertSame([null, null, null, 'Sertifikat'], [$removed->file_path, $removed->file_key, $removed->file_sha256, $removed->title]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->actingAs($editor)->delete($this->fileUrl($supplier, $removed))->assertSessionHasErrors('file');

        // Deleting a row with a file deletes the file with it.
        $third = $this->withFile($editor, $supplier, $removed, 'Ny.pdf', $this->pdf());
        $this->actingAs($editor)->delete("/app/supplier-management/{$supplier->id}/documents/{$third->id}")->assertSessionHasNoErrors();
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Editing the description never touches the file.
        $kept = $this->withFile($editor, $supplier, $this->row($supplier, 'Avtale'), 'Avtale.pdf', $this->pdf());
        $this->actingAs($editor)->patch("/app/supplier-management/{$supplier->id}/documents/{$kept->id}", $this->payload(['title' => 'Avtale rettet']))->assertSessionHasNoErrors();
        $this->assertSame($kept->file_path, $kept->fresh()->file_path);
    }

    public function test_a_row_a_control_rests_on_keeps_its_file_and_the_control_keeps_the_files_identity(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $requirement = $this->requirement($customer, 'Databehandleravtale');
        $pdf = $this->pdf('DBA');
        $document = $this->withFile($assurer, $supplier, $this->row($supplier, 'Databehandleravtale'), 'DBA signert.pdf', $pdf);

        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/requirement-evaluations", [
            'requirement_id' => $requirement->id, 'status' => 'documented', 'rationale' => 'Signert avtale mottatt.',
            'evaluated_on' => now()->toDateString(), 'accepted_until' => '', 'document_ids' => [$document->id],
        ])->assertSessionHasNoErrors();

        // The control's snapshot names the file by key, name and SHA-256 — and the stored bytes still match.
        $used = SupplierRequirementEvaluationDocument::query()->sole();
        $this->assertSame([$document->file_key, 'DBA signert.pdf', hash('sha256', $pdf)], [$used->document_file_key, $used->document_file_name, $used->document_file_sha256]);
        $this->assertSame($used->document_file_sha256, hash('sha256', Storage::disk('local')->get((string) $document->file_path)));
        $page = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertTrue($page['documents'][0]['file_locked']);
        $this->assertSame(['DBA signert.pdf', hash('sha256', $pdf)], [
            $page['control_requirements']['applicable'][0]['evaluations'][0]['documents'][0]['file_name'],
            $page['control_requirements']['applicable'][0]['evaluations'][0]['documents'][0]['file_sha256'],
        ]);

        // Locked: the file can be neither replaced nor removed, nor the row deleted.
        $this->actingAs($assurer)->post($this->fileUrl($supplier, $document), ['file' => UploadedFile::fake()->createWithContent('Annen.pdf', $this->pdf('annen'))])
            ->assertSessionHasErrors(['file' => __('procynia.supplier_management.validation.file_locked')]);
        $this->actingAs($assurer)->delete($this->fileUrl($supplier, $document))->assertSessionHasErrors('file');
        $this->actingAs($assurer)->delete("/app/supplier-management/{$supplier->id}/documents/{$document->id}")->assertSessionHasErrors('title');
        $this->assertSame([$used->document_file_key, hash('sha256', $pdf)], [$document->fresh()->file_key, $document->fresh()->file_sha256]);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // A new edition is registered with its own file; the old row keeps its file and its lock.
        $renewal = $this->pdf('DBA 2027');
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/documents/{$document->id}/renew", $this->payload([
            'title' => 'Databehandleravtale 2027', 'file' => UploadedFile::fake()->createWithContent('DBA 2027.pdf', $renewal),
        ]))->assertSessionHasNoErrors();
        $new = SupplierDocument::query()->whereKeyNot($document->id)->sole();
        $this->assertSame([$new->id, hash('sha256', $renewal), 'certificate'], [(int) $document->fresh()->replaced_by_document_id, $new->file_sha256, $new->document_type]);
        $this->assertSame(hash('sha256', $pdf), $document->fresh()->file_sha256);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_an_ended_supplier_is_read_only_for_files_but_its_files_can_still_be_downloaded(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $document = $this->withFile($editor, $supplier, $this->row($supplier, 'Avtale'), 'Avtale.pdf', $this->pdf());
        $supplier->forceFill(['status' => Supplier::STATUS_ENDED])->save();

        $this->actingAs($editor)->post($this->fileUrl($supplier, $document), ['file' => UploadedFile::fake()->createWithContent('Ny.pdf', $this->pdf('ny'))])
            ->assertSessionHas('error', __('procynia.supplier_management.validation.reopen_before_edit'));
        $this->actingAs($editor)->delete($this->fileUrl($supplier, $document))->assertSessionHas('error');
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/documents", $this->payload(['file' => UploadedFile::fake()->createWithContent('Ny.pdf', $this->pdf('ny'))]))->assertSessionHas('error');

        $this->assertSame($document->file_path, $document->fresh()->file_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->actingAs($editor)->get($this->fileUrl($supplier, $document))->assertOk();
    }

    public function test_a_refused_write_leaves_no_file_and_prune_orphans_removes_only_unreferenced_old_files(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $kept = $this->withFile($editor, $supplier, $this->row($supplier, 'Avtale'), 'Avtale.pdf', $this->pdf());

        // Renewing a row that is already replaced is refused inside the transaction: the uploaded file is removed again.
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/documents/{$kept->id}/renew", $this->payload(['title' => 'Avtale 2027']))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/documents/{$kept->id}/renew", $this->payload([
            'title' => 'Avtale 2028', 'file' => UploadedFile::fake()->createWithContent('Avtale 2028.pdf', $this->pdf('2028')),
        ]))->assertSessionHasErrors('title');
        $this->assertSame([(string) $kept->file_path], Storage::disk('local')->allFiles());

        // Orphans: one old and unreferenced, one too young, one referenced only by a control's snapshot of its key.
        $disk = Storage::disk('local');
        $area = "customers/{$customer->id}/supplier-documents";
        $old = "{$area}/01HZZZZZZZZZZZZZZZZZZZZZZZ.pdf";
        $young = "{$area}/01HYYYYYYYYYYYYYYYYYYYYYYY.pdf";
        $disk->put($old, $this->pdf());
        $disk->put($young, $this->pdf());
        $disk->put('customers/'.$customer->id.'/wiki-documents/01HXXXXXXXXXXXXXXXXXXXXXXX.pdf', $this->pdf());
        touch($disk->path($old), now()->subHours(25)->getTimestamp());
        touch($disk->path((string) $kept->file_path), now()->subHours(25)->getTimestamp());

        $this->artisan('private-files:prune-orphans', ['--dry-run' => true])->expectsOutputToContain("Would delete {$old}")->assertSuccessful();
        $this->assertTrue($disk->exists($old));

        $this->artisan('private-files:prune-orphans')->expectsOutputToContain("Deleted {$old}")->assertSuccessful();
        $this->assertFalse($disk->exists($old));
        $this->assertTrue($disk->exists($young));
        $this->assertTrue($disk->exists((string) $kept->file_path));
        $this->assertTrue($disk->exists('customers/'.$customer->id.'/wiki-documents/01HXXXXXXXXXXXXXXXXXXXXXXX.pdf'));
    }

    public function test_a_storage_failure_on_delete_never_undoes_the_change_and_the_file_is_left_for_prune_orphans(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        $document = $this->withFile($editor, $supplier, $this->row($supplier, 'Avtale'), 'Avtale.pdf', $this->pdf());
        $path = (string) $document->file_path;

        // The storage refuses to delete: the removal still stands, and the file is left behind.
        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('delete')->andThrow(new RuntimeException('Lagringen svarer ikke.'));
        $this->app->instance(PrivateFileStore::class, new class($failing) extends PrivateFileStore
        {
            public function __construct(private readonly Filesystem $failing) {}

            public function disk(): Filesystem
            {
                return $this->failing;
            }
        });
        Log::spy();

        $this->actingAs($editor)->delete($this->fileUrl($supplier, $document))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertNull($document->fresh()->file_path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'left for prune-orphans'))->once();

        // The next prune removes it.
        $this->app->forgetInstance(PrivateFileStore::class);
        touch(Storage::disk('local')->path($path), now()->subHours(25)->getTimestamp());
        $this->artisan('private-files:prune-orphans')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_prune_orphans_stops_at_the_safety_brake_and_logs_every_deletion(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $disk = Storage::disk('local');
        $area = "customers/{$customer->id}/supplier-documents";
        $orphans = ["{$area}/01J9ZQ7W8K3M5N6P7Q8R9S0T1A.pdf", "{$area}/01J9ZQ7W8K3M5N6P7Q8R9S0T1B.pdf"];

        foreach ($orphans as $path) {
            $disk->put($path, $this->pdf());
            touch($disk->path($path), now()->subHours(25)->getTimestamp());
        }

        // More orphans than the brake allows — as a wrong or empty database would show — deletes nothing.
        config(['private_files.prune_max_per_run' => 1]);
        Log::spy();
        $this->artisan('private-files:prune-orphans')->expectsOutputToContain('Nothing was deleted')->assertFailed();
        foreach ($orphans as $path) {
            $this->assertTrue($disk->exists($path));
        }
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'safety brake') && $context['orphans'] === 2)->once();

        // Deliberately past the brake: each deletion is logged with its path and customer.
        $this->artisan('private-files:prune-orphans', ['--force' => true])->assertSuccessful();
        foreach ($orphans as $path) {
            $this->assertFalse($disk->exists($path));
            Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['path'] === $path && $context['customer_id'] === $customer->id)->once();
        }
    }

    public function test_a_file_over_the_servers_upload_limit_reads_as_too_large_and_a_control_without_a_file_has_no_file_identity(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $row = $this->row($supplier, 'Egenerklæring');

        // PHP refused the upload (upload_max_filesize): the person is told about the size, in Norwegian —
        // never the wrong type, never an untranslated key.
        $path = (string) tempnam(sys_get_temp_dir(), 'big');
        $tooLarge = fn (): UploadedFile => new UploadedFile($path, 'stor.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $this->actingAs($assurer)->post($this->fileUrl($supplier, $row), ['file' => $tooLarge()])
            ->assertSessionHasErrors(['file' => __('procynia.supplier_management.validation.file_upload_failed')]);
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/documents", $this->payload(['file' => $tooLarge()]))
            ->assertSessionHasErrors(['file' => __('procynia.supplier_management.validation.file_size')]);
        @unlink($path);
        $this->assertSame(1, SupplierDocument::query()->count());

        // A control on a row without a file works as before: no file identity in its snapshot or its history.
        $requirement = $this->requirement($customer, 'Lønns- og arbeidsvilkår');
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/requirement-evaluations", [
            'requirement_id' => $requirement->id, 'status' => 'documented', 'rationale' => 'Egenerklæring arkivert.',
            'evaluated_on' => now()->toDateString(), 'accepted_until' => '', 'document_ids' => [$row->id],
        ])->assertSessionHasNoErrors();
        $used = SupplierRequirementEvaluationDocument::query()->sole();
        $this->assertSame([null, null, null], [$used->document_file_key, $used->document_file_name, $used->document_file_sha256]);
        $history = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'][0]['evaluations'][0]['documents'][0];
        $this->assertSame([null, null, false], [$history['file_name'], $history['file_sha256'], $history['changed_since']]);

        // The row is now evidence without a file, and stays that way: no first upload either.
        $this->actingAs($assurer)->post($this->fileUrl($supplier, $row), ['file' => UploadedFile::fake()->createWithContent('Egenerklæring.pdf', $this->pdf())])
            ->assertSessionHasErrors(['file' => __('procynia.supplier_management.validation.file_locked')]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'document_type' => 'certificate',
            'title' => 'ISO 27001-sertifikat',
            'standard' => '',
            'location' => '',
            'valid_from' => '',
            'valid_until' => '',
            'comment' => '',
        ];
    }

    private function row(Supplier $supplier, string $title): SupplierDocument
    {
        return SupplierDocument::query()->create([
            'customer_id' => $supplier->customer_id,
            'supplier_id' => $supplier->id,
            'document_type' => 'certificate',
            'title' => $title,
        ]);
    }

    private function withFile(User $user, Supplier $supplier, SupplierDocument $row, string $name, string $content): SupplierDocument
    {
        $this->actingAs($user)->post($this->fileUrl($supplier, $row), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertSessionHasNoErrors();

        return $row->fresh();
    }

    private function fileUrl(Supplier $supplier, SupplierDocument $row): string
    {
        return "/app/supplier-management/{$supplier->id}/documents/{$row->id}/file";
    }

    private function requirement(Customer $customer, string $title): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => $title, 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'applies_when' => [],
        ]);
    }

    private function pdf(string $marker = 'Procynia'): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Title ({$marker}) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    private function image(string $type): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function office(string $mainPart, bool $withMacros = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'office');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString($mainPart, '<?xml version="1.0"?><document/>');

        if ($withMacros) {
            $zip->addFromString(dirname($mainPart).'/vbaProject.bin', 'macro');
        }

        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }
}
