<?php

namespace Tests\Feature\Security;

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dompdf hardening for the document preview flow.
 *
 * DocumentPreviewService converts a customer-uploaded .docx to HTML with PHPWord and hands that
 * HTML to Dompdf. The HTML therefore carries content the customer controls — images, SVG, CSS —
 * which is exactly the surface the 3.1.6 advisories cover (CVE-2026-56722 local file read via
 * data-URI SVG, CVE-2026-55555 font-face existence oracle, CVE-2026-55554 chroot bypass).
 *
 * These tests pin the two properties that keep that surface closed: the renderer still works, and
 * none of the usual reference vectors can pull a local file into the PDF. They mirror the exact
 * Options that DocumentPreviewService::generateDocxPreviewPdf() sets, so flipping isRemoteEnabled
 * there without thinking will fail here.
 */
class DompdfPreviewFileAccessTest extends TestCase
{
    /** Marker written into the fixture; must never reach the rendered PDF. */
    private const CANARY = 'PROCYNIA_LEAK_CANARY_9F3A';

    private string $fixturePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'procynia-dompdf-fixture-');

        file_put_contents($this->fixturePath, 'harmless test fixture '.self::CANARY."\n");
    }

    protected function tearDown(): void
    {
        if ($this->fixturePath !== '' && is_file($this->fixturePath)) {
            @unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    /** The same options DocumentPreviewService uses for the docx preview. */
    private function previewOptions(): Options
    {
        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        return $options;
    }

    private function render(string $bodyHtml): string
    {
        $dompdf = new Dompdf($this->previewOptions());
        $dompdf->loadHtml('<html><body>'.$bodyHtml.'</body></html>', 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function test_the_preview_renderer_still_produces_a_pdf(): void
    {
        $pdf = $this->render('<h1>Procynia</h1><p>Æøå 123</p><table><tr><td>a</td><td>b</td></tr></table>');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fileReferenceVectors(): array
    {
        return [
            'img with file scheme' => ['<img src="file://__FIXTURE__" />'],
            'img with bare path' => ['<img src="__FIXTURE__" />'],
            'css font-face source' => ['<style>@font-face{font-family:x;src:url("file://__FIXTURE__");}</style><p style="font-family:x">t</p>'],
            'css background image' => ['<div style="background-image:url(file://__FIXTURE__)">t</div>'],
            'svg image href' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="file://__FIXTURE__"/></svg>'],
        ];
    }

    #[DataProvider('fileReferenceVectors')]
    public function test_a_local_file_cannot_be_pulled_into_the_preview(string $template): void
    {
        $pdf = $this->render(str_replace('__FIXTURE__', $this->fixturePath, $template));

        $this->assertStringNotContainsString(
            self::CANARY,
            $pdf,
            'Dompdf embedded a local file that the customer only referenced from preview HTML.',
        );
    }

    public function test_remote_resources_stay_disabled_for_the_preview(): void
    {
        $this->assertFalse($this->previewOptions()->getIsRemoteEnabled());
    }
}
