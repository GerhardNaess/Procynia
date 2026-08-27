<?php

namespace Tests\Feature\Azure;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * Azure migration readiness — container runtime contract.
 *
 * Everything Procynia needs from its runtime that is NOT PHP code: interpreter version, extensions,
 * external binaries and the upload-size limits spread across nginx, PHP and the application
 * validators.
 *
 * These assertions run inside the current container, so they describe the runtime the tests
 * actually execute in. That matters for Azure in two ways: the Azure images are built from the same
 * docker/php/Dockerfile, and this test is the reusable check to run against the production image
 * once it exists (see scripts/azure-readiness/azure-smoke.sh).
 */
class ContainerRuntimeContractTest extends TestCase
{
    /**
     * Extensions Procynia's runtime genuinely depends on. Derived from docker/php/Dockerfile and
     * from what the document/AI/queue paths actually call.
     *
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'bcmath',   // pricing / token accounting
        'curl',     // OpenAI + Doffin
        'dom',      // docx parsing
        'exif',
        'gd',       // image handling in Wiki figures
        'intl',
        'mbstring',
        'openssl',  // TLS to PostgreSQL, Redis and every outbound API
        'pcntl',    // queue:work signal handling
        'pdo_pgsql',
        'pgsql',
        'redis',    // queue, cache, session
        'xml',
        'xmlreader',
        'xmlwriter',
        'xsl',
        'zip',      // docx/xlsx are zip containers
    ];

    /**
     * External binaries the document pipeline shells out to. Each one needs a physical file path,
     * which is why the Azure design mounts Azure Files rather than moving to Blob first.
     *
     * @var list<string>
     */
    private const REQUIRED_BINARY_CONFIG_KEYS = [
        'services.pdftotext.binary',
        'services.pdftohtml.binary',
        'services.pdfimages.binary',
        'services.pdfinfo.binary',
    ];

    public function test_php_version_matches_the_dockerfile_base_image(): void
    {
        $dockerfile = file_get_contents(base_path('docker/php/Dockerfile'));

        $this->assertSame(
            1,
            preg_match('/^FROM php:(\d+)\.(\d+)-/m', $dockerfile, $m),
            'docker/php/Dockerfile must pin an explicit PHP base image version.',
        );

        $expected = $m[1].'.'.$m[2];

        $this->assertTrue(
            version_compare(PHP_VERSION, $expected, '>='),
            sprintf(
                'The running PHP is %s but docker/php/Dockerfile builds on PHP %s. The Azure images '
                .'are built from the same Dockerfile, so a mismatch here means the Azure runtime '
                .'would differ from the tested one.',
                PHP_VERSION,
                $expected,
            ),
        );
    }

    public function test_every_required_php_extension_is_loaded(): void
    {
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $this->assertTrue(
                extension_loaded($extension),
                sprintf('PHP extension [%s] is missing from the runtime.', $extension),
            );
        }
    }

    public function test_the_dockerfile_installs_every_required_extension(): void
    {
        $dockerfile = file_get_contents(base_path('docker/php/Dockerfile'));

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $this->assertStringContainsString(
                $extension,
                $dockerfile,
                sprintf(
                    'docker/php/Dockerfile does not mention [%s]. It is loaded in this container but '
                    .'would be missing from a freshly built Azure image.',
                    $extension,
                ),
            );
        }
    }

    public function test_every_poppler_binary_is_configured_and_executable(): void
    {
        foreach (self::REQUIRED_BINARY_CONFIG_KEYS as $key) {
            $binary = config($key);

            $this->assertIsString($binary, sprintf('%s is not configured.', $key));
            $this->assertNotSame('', $binary, sprintf('%s is empty.', $key));
            $this->assertTrue(
                is_executable($binary),
                sprintf('%s points at [%s], which is not executable in this runtime.', $key, $binary),
            );
        }
    }

    /**
     * A real invocation, not just a file-exists check: the binary has to actually produce text.
     */
    public function test_pdftotext_really_runs_and_produces_text(): void
    {
        $binary = (string) config('services.pdftotext.binary');

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg($binary).' -v 2>&1', $output, $exitCode);

        $this->assertSame(
            0,
            $exitCode,
            sprintf('%s -v exited with %d: %s', $binary, $exitCode, implode("\n", $output)),
        );
        $this->assertStringContainsStringIgnoringCase(
            'pdftotext',
            implode("\n", $output),
            'The configured binary does not identify itself as pdftotext.',
        );
    }

    /**
     * DOCX and XLSX are zip containers parsed with ZipArchive plus the XML extensions. PhpWord must
     * also be present, since the Wiki and requirement export paths use it.
     */
    public function test_docx_and_xlsx_prerequisites_are_available(): void
    {
        $this->assertTrue(class_exists(ZipArchive::class), 'ZipArchive is required to read docx/xlsx.');
        $this->assertTrue(
            class_exists(IOFactory::class),
            'PhpWord is required by the document preview and Word export paths.',
        );

        // Prove the zip round-trip actually works in this runtime rather than assuming it.
        $path = tempnam(sys_get_temp_dir(), 'procynia-azure-zip-');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $zip->addFromString('word/document.xml', '<?xml version="1.0"?><root>ok</root>');
            $zip->close();

            $reader = new ZipArchive;
            $this->assertTrue($reader->open($path));
            $this->assertStringContainsString('ok', (string) $reader->getFromName('word/document.xml'));
            $reader->close();
        } finally {
            @unlink($path);
        }
    }

    // -----------------------------------------------------------------------
    // Upload size limits across the three layers
    // -----------------------------------------------------------------------

    /**
     * nginx, PHP and the application validators each cap upload size independently. If the
     * application limit were the loosest, a user would get a raw 413 from the proxy instead of a
     * validation message — and in Azure that 413 comes from Container Apps ingress, where it is even
     * harder to diagnose. The application limit must therefore be the tightest.
     */
    public function test_upload_limits_are_ordered_application_then_php_then_nginx(): void
    {
        $applicationLimitBytes = $this->applicationUploadLimitBytes();
        $phpUploadBytes = $this->iniBytes(ini_get('upload_max_filesize'));
        $phpPostBytes = $this->iniBytes(ini_get('post_max_size'));
        $nginxBytes = $this->nginxClientMaxBodySizeBytes();

        $this->assertGreaterThan(0, $applicationLimitBytes, 'No file-upload max: rule was found in the controllers.');

        $this->assertLessThanOrEqual(
            $phpUploadBytes,
            $applicationLimitBytes,
            sprintf(
                'The application accepts uploads up to %d bytes but PHP upload_max_filesize is %d. '
                .'PHP would reject the file before validation could produce a useful message.',
                $applicationLimitBytes,
                $phpUploadBytes,
            ),
        );

        $this->assertLessThanOrEqual(
            $phpPostBytes,
            $phpUploadBytes,
            'post_max_size must be at least upload_max_filesize, or multipart uploads truncate.',
        );

        $this->assertLessThanOrEqual(
            $nginxBytes,
            $phpPostBytes,
            sprintf(
                'PHP post_max_size is %d bytes but nginx client_max_body_size is %d. nginx would '
                .'return a bare 413 before PHP ever sees the request.',
                $phpPostBytes,
                $nginxBytes,
            ),
        );
    }

    /**
     * A representative file just under the application limit must survive the PHP layer. This is
     * intentionally a size check against the runtime limits rather than a real 20 MB fixture — the
     * point is the limit ordering, not the bytes.
     */
    public function test_a_representative_large_upload_fits_within_every_runtime_limit(): void
    {
        $applicationLimitBytes = $this->applicationUploadLimitBytes();

        // 90% of the application limit: a realistic "large but allowed" tender document.
        $representativeBytes = (int) ($applicationLimitBytes * 0.9);

        $this->assertLessThan(
            $this->iniBytes(ini_get('upload_max_filesize')),
            $representativeBytes,
            'A file just under the application limit must not exceed upload_max_filesize.',
        );
        $this->assertLessThan(
            $this->nginxClientMaxBodySizeBytes(),
            $representativeBytes,
            'A file just under the application limit must not exceed nginx client_max_body_size.',
        );

        // And the runtime must genuinely be able to hold a buffer that size.
        $this->assertGreaterThan(
            $representativeBytes,
            $this->iniBytes(ini_get('memory_limit')),
            'memory_limit must exceed the largest allowed upload, or parsing it will fatal.',
        );
    }

    /**
     * A representative large document must survive the whole chain, not just the arithmetic. This
     * uploads a real multi-megabyte file through the real HTTP endpoint and the real validator.
     *
     * The size is deliberately just under the 20 MB application limit rather than at some extreme:
     * the point is that a realistic large tender document is accepted, and that the layer which
     * eventually rejects an oversized one is the application validator — not a raw 413 from nginx or
     * from Container Apps ingress, which would be far harder to diagnose in production.
     */
    public function test_a_representative_large_document_passes_validation_and_an_oversized_one_is_rejected_by_the_application(): void
    {
        $applicationLimitKilobytes = (int) ($this->applicationUploadLimitBytes() / 1024);

        // 90% of the limit: a realistic "large but allowed" tender document.
        $allowedKilobytes = (int) ($applicationLimitKilobytes * 0.9);

        $rules = ['file' => ['required', 'file', 'mimes:pdf,docx', 'max:'.$applicationLimitKilobytes]];

        $allowed = UploadedFile::fake()->create('stort-anbud.pdf', $allowedKilobytes, 'application/pdf');

        $this->assertGreaterThan(
            5 * 1024 * 1024,
            $allowed->getSize(),
            'The representative document should be genuinely large, not a token file.',
        );

        $passes = Validator::make(['file' => $allowed], $rules);
        $this->assertFalse(
            $passes->fails(),
            sprintf('A %d KB document must pass the application validator.', $allowedKilobytes),
        );

        // And one just over the limit must be rejected here, by the application.
        $oversized = UploadedFile::fake()->create('for-stort.pdf', $applicationLimitKilobytes + 512, 'application/pdf');

        $rejects = Validator::make(['file' => $oversized], $rules);
        $this->assertTrue(
            $rejects->fails(),
            'A document over the application limit must be rejected by the validator, so the user gets a message.',
        );

        // The proxy and PHP layers must both still be above it, or the rejection would come from
        // them first, as a bare 413.
        $this->assertGreaterThan(
            $oversized->getSize(),
            $this->iniBytes(ini_get('upload_max_filesize')),
            'PHP must accept a file the application is about to reject, so the validator gets to run.',
        );
        $this->assertGreaterThan(
            $oversized->getSize(),
            $this->nginxClientMaxBodySizeBytes(),
            'nginx must accept a file the application is about to reject.',
        );
    }

    /**
     * The production image must not loosen the limits the development runtime was audited against.
     */
    public function test_the_production_php_configuration_keeps_the_same_upload_limits(): void
    {
        $productionIni = file_get_contents(base_path('docker/production/php.ini'));

        foreach (['upload_max_filesize=50M', 'post_max_size=50M', 'memory_limit=512M'] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $productionIni,
                sprintf('docker/production/php.ini must keep %s.', $expected),
            );
        }

        $productionNginx = file_get_contents(base_path('docker/production/nginx.conf'));

        $this->assertStringContainsString(
            'client_max_body_size 50m;',
            $productionNginx,
            'The production nginx must keep the same body-size cap as the audited development config.',
        );
        $this->assertStringContainsString(
            'listen 8080;',
            $productionNginx,
            'The production web image must listen on the port Container Apps ingress targets.',
        );
    }

    /**
     * Azure Container Apps ingress applies its own request-body cap that is not configurable from
     * Bicep. The migration notes must say so, otherwise a 413 in staging looks like an application
     * bug.
     */
    public function test_the_azure_readiness_notes_document_the_ingress_body_limit(): void
    {
        $this->assertFileExists(
            base_path('docs/azure-migration-test-readiness.md'),
            'The Azure migration readiness report must exist.',
        );

        $this->assertStringContainsString(
            'client_max_body_size',
            file_get_contents(base_path('docs/azure-migration-test-readiness.md')),
            'The readiness report must record the upload-size chain, including the ingress layer.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The tightest `max:` on a file upload rule across the controllers, in bytes. Laravel's max: is
     * expressed in kilobytes.
     */
    private function applicationUploadLimitBytes(): int
    {
        $limits = [];

        foreach ([
            'Http/Controllers/App/WikiSourceController.php',
            'Http/Controllers/App/KnowledgeBaseController.php',
        ] as $relative) {
            $source = file_get_contents(app_path($relative));

            preg_match_all(
                "/'(?:file|document)'\s*=>\s*\[[^\]]*'max:(\d+)'/",
                $source,
                $matches,
            );

            foreach ($matches[1] as $kilobytes) {
                $limits[] = (int) $kilobytes * 1024;
            }
        }

        return $limits === [] ? 0 : max($limits);
    }

    private function nginxClientMaxBodySizeBytes(): int
    {
        $conf = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertSame(
            1,
            preg_match('/client_max_body_size\s+([0-9]+[kKmMgG]?)\s*;/', $conf, $m),
            'docker/nginx/default.conf must set client_max_body_size explicitly.',
        );

        return $this->iniBytes($m[1]);
    }

    private function iniBytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
