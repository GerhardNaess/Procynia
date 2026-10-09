<?php

namespace Tests\Unit\Support\PrivateFiles;

use App\Support\PrivateFiles\PrivateFileStore;
use App\Support\PrivateFiles\PrivateFileType;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The shared private-file core's pure rules: the type is decided from the content and must agree
 * with the name; a stored path is only ever recognised inside its own customer's area; a person's
 * file name is kept as harmless text.
 */
class PrivateFileTypeTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->paths, 'is_file'));

        parent::tearDown();
    }

    public function test_the_type_is_the_contents_and_must_agree_with_the_name(): void
    {
        $pdf = $this->file("%PDF-1.7\nx\n%%EOF");
        $png = $this->image('png');
        $docx = $this->office('word/document.xml');

        $this->assertSame('pdf', PrivateFileType::detect($pdf, 'Avtale.PDF'));
        $this->assertSame('png', PrivateFileType::detect($png, 'bilde.png'));
        $this->assertSame('jpg', PrivateFileType::detect($this->image('jpg'), 'foto.jpeg'));
        $this->assertSame('docx', PrivateFileType::detect($docx, 'avtale.docx'));
        $this->assertSame('xlsx', PrivateFileType::detect($this->office('xl/workbook.xml'), 'liste.xlsx'));

        $this->assertNull(PrivateFileType::detect($png, 'bilde.pdf'), 'a PNG is not a PDF');
        $this->assertNull(PrivateFileType::detect($docx, 'liste.xlsx'), 'a Word package is not a workbook');
        $this->assertNull(PrivateFileType::detect($pdf, 'avtale'), 'no extension');
        $this->assertNull(PrivateFileType::detect($pdf, 'avtale.pdf.exe'), 'the last extension decides');
        $this->assertNull(PrivateFileType::detect($this->file('%PDF-1.7 uten slutt'), 'avtale.pdf'), 'no end-of-file marker');
        $this->assertNull(PrivateFileType::detect($this->office('word/document.xml', macros: true), 'avtale.docx'), 'macros');
        $this->assertNull(PrivateFileType::detect($this->file('<svg/>'), 'logo.svg'));
        $this->assertNull(PrivateFileType::detect($this->office('word/document.xml', contentTypes: str_repeat('<x/>', 300_000)), 'bombe.docx'), 'an oversized content-types part is never unpacked');
        $this->assertSame('application/pdf', PrivateFileType::mimeType('pdf'));
        $this->assertSame('application/octet-stream', PrivateFileType::mimeType('html'));
    }

    public function test_a_stored_path_belongs_only_to_its_own_customer_and_area(): void
    {
        $store = new PrivateFileStore;
        $key = '01J9ZQ7W8K3M5N6P7Q8R9S0T1V';

        $this->assertTrue($store->belongsTo("customers/7/supplier-documents/{$key}.pdf", 7, 'supplier-documents'));
        $this->assertSame($key, PrivateFileStore::keyOf("customers/7/supplier-documents/{$key}.pdf"));

        foreach ([
            "customers/8/supplier-documents/{$key}.pdf",
            "customers/7/wiki-documents/{$key}.pdf",
            "customers/7/supplier-documents/../8/supplier-documents/{$key}.pdf",
            "customers/7/supplier-documents/{$key}.html",
            'customers/7/supplier-documents/avtale.pdf',
            "/customers/7/supplier-documents/{$key}.pdf",
        ] as $path) {
            $this->assertFalse($store->belongsTo($path, 7, 'supplier-documents'), $path);
        }
    }

    public function test_the_persons_file_name_is_kept_as_harmless_text(): void
    {
        $this->assertSame('Avtale «Drift» 2026.pdf', PrivateFileStore::displayName('Avtale «Drift» 2026.pdf', 'pdf'));
        $this->assertSame('.._.._etc_passwd.pdf', PrivateFileStore::displayName('../../etc/passwd.pdf', 'pdf'));
        $this->assertSame('ab.pdf', PrivateFileStore::displayName("a\x00\nb.pdf", 'pdf'));
        $this->assertSame('fil.pdf', PrivateFileStore::displayName('   ', 'pdf'));
        $this->assertSame(200, mb_strlen(PrivateFileStore::displayName(str_repeat('x', 300).'.pdf', 'pdf')));
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pft');
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return $path;
    }

    private function image(string $type): string
    {
        $image = imagecreatetruecolor(3, 3);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);

        return $this->file((string) ob_get_clean());
    }

    private function office(string $mainPart, bool $macros = false, string $contentTypes = '<Types/>'): string
    {
        $path = $this->file('');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString($mainPart, '<x/>');

        if ($macros) {
            $zip->addFromString(dirname($mainPart).'/vbaProject.bin', 'm');
        }

        $zip->close();

        return $path;
    }
}
