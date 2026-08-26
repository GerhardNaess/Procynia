<?php

namespace Tests\Feature\Azure;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Azure migration readiness — web → shared storage → worker handoff.
 *
 * This is the single most exposed part of the Azure migration. Procynia resolves physical
 * filesystem paths in nine places via Storage::disk('local')->path(...) and hands them to external
 * poppler processes. Web, every queue worker and the scheduler must therefore all see the same
 * bytes at the same path — which is why the Azure IaC mounts an Azure Files share at
 * /var/www/html/storage/app instead of moving straight to Blob.
 *
 * What this test actually proves, locally and without faking anything:
 *
 *   1. A real HTTP upload stores a real file on a real disk and persists its relative path to
 *      PostgreSQL. Storage::fake() is deliberately NOT used — faking the disk would hide the very
 *      question being asked.
 *   2. A genuinely separate OS process, with its own Laravel container and no shared memory, can
 *      take that relative path, resolve it to a physical path, and read the same bytes.
 *   3. That separate process can run the real DocumentTextExtractor, including the real pdftotext
 *      subprocess, against the resolved path.
 *   4. The worker's result is persisted to PostgreSQL and readable back through the application
 *      layer.
 *
 * What it deliberately does NOT claim: that Azure Files SMB behaves identically to a local
 * filesystem. Separate processes are not separate containers, and a local filesystem is not SMB.
 * The cross-container version is scripts/azure-readiness/azure-smoke.sh; the SMB version is a
 * staging test. See docs/azure-migration-test-readiness.md.
 *
 * The database write-back in step 4 happens in the test process because the suite runs inside a
 * RefreshDatabase transaction, which a child process could not observe. The transport being
 * verified here is the filesystem, not the database.
 */
class SharedStorageHandoffTest extends TestCase
{
    use RefreshDatabase;

    private string $sharedRoot;

    /** @var list<string> Source fixtures created in the container temp dir, removed in tearDown. */
    private array $temporaryFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        // A dedicated root, shared by both processes by path only. This is the local stand-in for
        // the Azure Files mount point, and it keeps the test out of the development storage tree.
        $this->sharedRoot = sys_get_temp_dir().'/procynia-azure-readiness-'.Str::lower(Str::random(12));
        mkdir($this->sharedRoot, 0775, true);

        config(['filesystems.disks.local.root' => $this->sharedRoot]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $fixture) {
            @unlink($fixture);
        }

        $this->temporaryFixtures = [];

        $this->deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_a_separate_process_can_read_and_parse_a_document_uploaded_by_the_web_layer(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        // ── 1. Web layer receives the document over real HTTP ────────────────
        $pdf = $this->makeRealPdf('Delt lagring fungerer.');

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => new UploadedFile($pdf, 'delt-lagring.pdf', 'application/pdf', null, true),
            'tab' => 'sources',
        ]);

        $response->assertSessionHasNoErrors();

        // ── 2. Metadata is in PostgreSQL ─────────────────────────────────────
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNotSame('', (string) $document->file_path, 'The web layer must persist the stored path.');
        $this->assertSame(
            EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            $document->document_status,
            'The web layer must have extracted text from the real PDF via the real pdftotext binary.',
        );

        // The file really is on disk — not in a fake.
        $webAbsolutePath = Storage::disk('local')->path($document->file_path);
        $this->assertFileExists($webAbsolutePath);
        $this->assertStringStartsWith($this->sharedRoot, $webAbsolutePath);

        // ── 3/4. A separate OS process resolves the same relative path ───────
        $worker = $this->runWorkerProcess($document->file_path);

        $this->assertTrue($worker['ok'], 'The worker process failed: '.($worker['error'] ?? 'unknown'));
        $this->assertNotSame(
            getmypid(),
            $worker['pid'],
            'The worker must really run in a different process, otherwise this proves nothing.',
        );
        $this->assertSame(
            $webAbsolutePath,
            $worker['absolute_path'],
            'Web and worker must resolve the same relative path to the same physical path.',
        );
        $this->assertSame(
            hash_file('sha256', $webAbsolutePath),
            $worker['sha256'],
            'The worker must read exactly the bytes the web layer wrote.',
        );

        // ── 5. The worker parsed the document itself ─────────────────────────
        $this->assertStringContainsString(
            'Delt lagring fungerer',
            $worker['extracted_text'],
            'The worker process must be able to run the real extractor against the shared file.',
        );
        $this->assertSame(
            trim((string) $document->extracted_text),
            trim($worker['extracted_text']),
            'Web and worker must extract identical text from the same shared file.',
        );

        // ── 6. Result persisted to PostgreSQL ────────────────────────────────
        $document->forceFill([
            'extracted_text' => $worker['extracted_text'],
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ])->save();

        // ── 7. Readable back through the application layer ───────────────────
        $reloaded = EnterpriseWikiDocument::query()->findOrFail($document->id);
        $this->assertStringContainsString('Delt lagring fungerer', (string) $reloaded->extracted_text);

        $download = $this->actingAs($user)->get(route('app.wiki.sources.download', ['document' => $document->id]));
        $download->assertOk();
    }

    /**
     * The negative control. If the worker process is pointed at a different root — which is what a
     * missing or misconfigured Azure Files mount looks like — it must fail loudly rather than
     * silently returning empty text. Without this, the positive test above could pass for the
     * wrong reason.
     */
    public function test_a_worker_without_the_shared_mount_cannot_see_the_document(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $pdf = $this->makeRealPdf('Skal ikke vaere synlig.');

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => new UploadedFile($pdf, 'usynlig.pdf', 'application/pdf', null, true),
            'tab' => 'sources',
        ])->assertSessionHasNoErrors();

        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $unmountedRoot = sys_get_temp_dir().'/procynia-azure-readiness-unmounted-'.Str::lower(Str::random(8));
        mkdir($unmountedRoot, 0775, true);

        try {
            $worker = $this->runWorkerProcess($document->file_path, $unmountedRoot, expectSuccess: false);

            $this->assertFalse($worker['ok'], 'A worker without the shared mount must not report success.');
            $this->assertStringContainsString('cannot see', (string) $worker['error']);
        } finally {
            $this->deleteDirectory($unmountedRoot);
        }
    }

    /**
     * Temporary files must stay container-local and must not leak into the shared mount: SMB is
     * slower and shared across replicas, so scratch space belongs on the container filesystem.
     */
    public function test_temporary_files_are_created_outside_the_shared_storage_root(): void
    {
        $tempDir = sys_get_temp_dir();

        $this->assertDirectoryIsWritable($tempDir, 'The container temp directory must be writable.');
        $this->assertStringStartsNotWith(
            rtrim(config('filesystems.disks.local.root'), '/'),
            rtrim($tempDir, '/'),
            'sys_get_temp_dir() must not resolve inside the shared storage mount.',
        );

        $probe = tempnam($tempDir, 'procynia-azure-readiness-');
        $this->assertNotFalse($probe);

        try {
            $this->assertStringStartsNotWith(
                $this->sharedRoot,
                $probe,
                'tempnam() must not allocate inside the Azure Files mount.',
            );
        } finally {
            @unlink($probe);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function runWorkerProcess(string $relativePath, ?string $root = null, bool $expectSuccess = true): array
    {
        $process = new Process(
            [
                PHP_BINARY,
                base_path('tests/Feature/Azure/support/read-shared-document.php'),
                $root ?? $this->sharedRoot,
                $relativePath,
            ],
            base_path(),
            [
                // The worker reads no database, but is pinned to the test database anyway so that a
                // future change to the script can never reach the development database.
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'postgres',
                'DB_DATABASE' => 'procynia_test',
            ],
            null,
            120,
        );

        $process->run();

        $output = trim($process->getOutput());

        $this->assertNotSame(
            '',
            $output,
            'The worker process produced no output. stderr: '.$process->getErrorOutput(),
        );

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'The worker process did not return JSON. Output: '.$output);

        if ($expectSuccess) {
            $this->assertTrue(
                $process->isSuccessful(),
                'The worker process exited non-zero. stderr: '.$process->getErrorOutput(),
            );
        }

        return $decoded;
    }

    /**
     * A minimal but structurally complete PDF that the real pdftotext binary can parse. Mirrors the
     * fixture approach already used in tests/Unit/DocumentTextExtractorTest.php — no binary
     * fixtures are committed to the repository.
     */
    private function makeRealPdf(string $text): string
    {
        $path = sys_get_temp_dir().'/procynia-azure-readiness-src-'.Str::lower(Str::random(8)).'.pdf';

        $header = "%PDF-1.4\n";
        $obj1 = "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $obj2 = "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $obj3 = "3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R"
            .' /Resources <</Font <</F1 <</Type /Font /Subtype /Type1 /BaseFont /Helvetica'
            ." /Encoding /WinAnsiEncoding>>>>>>\n>>\nendobj\n";
        $stream = 'BT /F1 14 Tf 72 720 Td ('.$text.") Tj ET\n";
        $obj4 = "4 0 obj\n<</Length ".strlen($stream).">>\nstream\n{$stream}endstream\nendobj\n";

        $o1 = strlen($header);
        $o2 = $o1 + strlen($obj1);
        $o3 = $o2 + strlen($obj2);
        $o4 = $o3 + strlen($obj3);
        $xrefOffset = $o4 + strlen($obj4);

        $xref = "xref\n0 5\n"
            ."0000000000 65535 f \n"
            .str_pad((string) $o1, 10, '0', STR_PAD_LEFT)." 00000 n \n"
            .str_pad((string) $o2, 10, '0', STR_PAD_LEFT)." 00000 n \n"
            .str_pad((string) $o3, 10, '0', STR_PAD_LEFT)." 00000 n \n"
            .str_pad((string) $o4, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        $trailer = "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        file_put_contents($path, $header.$obj1.$obj2.$obj3.$obj4.$xref.$trailer);

        $this->temporaryFixtures[] = $path;

        return $path;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $name = 'Azure Readiness AS';

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Azure Readiness Uploader',
            'email' => Str::lower(Str::random(8)).'@azure-readiness.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
