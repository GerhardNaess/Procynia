<?php
require "/var/www/html/vendor/autoload.php";

function emit(string $name, float $seconds, int $peakBytes, string $detail = ''): void
{
    printf("%s\t%.3f\t%.1f\t%s\n", $name, $seconds, $peakBytes / 1048576, $detail);
}

/** A structurally valid PDF with $pages pages of text that pdftotext can parse. */
function makePdf(string $path, int $pages): void
{
    $objects = [];
    $kids = [];
    $objectNumber = 3;

    for ($p = 0; $p < $pages; $p++) {
        $contentNumber = $objectNumber + 1;
        $kids[] = "{$objectNumber} 0 R";
        $stream = "BT /F1 11 Tf 54 760 Td (Side " . ($p + 1) . " av {$pages}.) Tj\n";
        for ($line = 0; $line < 45; $line++) {
            $stream .= "0 -15 Td (Kravtekst linje {$line} med representativt innhold for maalingen.) Tj\n";
        }
        $stream .= "ET\n";

        $objects[$objectNumber] = "{$objectNumber} 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            . "/Contents {$contentNumber} 0 R /Resources <</Font <</F1 <</Type /Font /Subtype /Type1 "
            . "/BaseFont /Helvetica>>>>>>>>\nendobj\n";
        $objects[$contentNumber] = "{$contentNumber} 0 obj\n<</Length " . strlen($stream) . ">>\nstream\n{$stream}endstream\nendobj\n";
        $objectNumber += 2;
    }

    $header = "%PDF-1.4\n";
    $objects[1] = "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
    $objects[2] = "2 0 obj\n<</Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$pages}>>\nendobj\n";
    ksort($objects);

    $body = '';
    $offsets = [];
    $cursor = strlen($header);
    foreach ($objects as $number => $content) {
        $offsets[$number] = $cursor;
        $body .= $content;
        $cursor += strlen($content);
    }

    $maxObject = max(array_keys($objects));
    $xref = "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $xref .= str_pad((string) ($offsets[$i] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }

    file_put_contents($path, $header . $body . $xref
        . "trailer\n<</Size " . ($maxObject + 1) . " /Root 1 0 R>>\nstartxref\n{$cursor}\n%%EOF\n");
}

/** A DOCX with $paragraphs paragraphs, built as a raw OOXML zip. */
function makeDocx(string $path, int $paragraphs): void
{
    $body = '';
    for ($i = 0; $i < $paragraphs; $i++) {
        $body .= '<w:p><w:r><w:t>Avsnitt ' . $i . ' med representativt kravinnhold for maalingen av dokumentparsing.</w:t></w:r></w:p>';
    }

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    $zip->addFromString('word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body . '</w:body></w:document>');
    $zip->close();
}

$mode = $argv[1] ?? 'all';
$tmp = sys_get_temp_dir();

// The application is booted for every mode, not only for the boot measurement. Without the console
// kernel bootstrap there is no configuration, so services.pdftotext.binary resolves to null and the
// extractor silently returns an empty string — which would look like a very fast extraction rather
// than like a broken measurement.
$bootStart = microtime(true);
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bootElapsed = microtime(true) - $bootStart;

if ($mode === 'boot' || $mode === 'all') {
    emit('framework-boot', $bootElapsed, memory_get_peak_usage(true), 'Laravel container booted');
}

// Guard the measurement itself: a misconfigured binary must fail loudly, not produce a fast zero.
if (($mode === 'pdf' || $mode === 'all') && ! is_executable((string) config('services.pdftotext.binary'))) {
    fwrite(STDERR, "pdftotext is not executable — refusing to report a meaningless measurement.\n");
    exit(1);
}

// ---- PDF extraction: real pdftotext, real extractor ------------------------
if ($mode === 'pdf' || $mode === 'all') {
    foreach ([10, 50, 150] as $pages) {
        $path = "{$tmp}/measure-{$pages}.pdf";
        makePdf($path, $pages);
        $sizeMb = filesize($path) / 1048576;

        $extractor = $app->make(App\Services\DocumentTextExtractor::class);

        $before = memory_get_peak_usage(true);
        $start = microtime(true);
        $text = $extractor->extractText($path);
        $elapsed = microtime(true) - $start;

        if (strlen((string) $text) === 0) {
            fwrite(STDERR, "pdf-extract-{$pages}p produced no text — the measurement is not valid.\n");
            exit(1);
        }

        emit(
            "pdf-extract-{$pages}p",
            $elapsed,
            max(memory_get_peak_usage(true), $before),
            sprintf('%.2f MB file, %d chars extracted', $sizeMb, strlen((string) $text))
        );
        @unlink($path);
    }
}

// ---- DOCX parsing: real PhpWord --------------------------------------------
if ($mode === 'docx' || $mode === 'all') {
    foreach ([200, 1000] as $paragraphs) {
        $path = "{$tmp}/measure-{$paragraphs}.docx";
        makeDocx($path, $paragraphs);
        $sizeMb = filesize($path) / 1048576;

        $extractor = $app->make(App\Services\DocumentTextExtractor::class);

        $start = microtime(true);
        $text = $extractor->extractText($path);
        $elapsed = microtime(true) - $start;

        emit(
            "docx-extract-{$paragraphs}par",
            $elapsed,
            memory_get_peak_usage(true),
            sprintf('%.2f MB file, %d chars extracted', $sizeMb, strlen((string) $text))
        );
        @unlink($path);
    }
}
