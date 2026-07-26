<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Feature coverage for WikiSourceController::image() — the authenticated, customer-scoped route
 * that serves a single Word image. There is no separate stored image file: the route re-extracts
 * from the document's own already-private .docx on every request and re-encodes the bytes through
 * GD (stripping embedded metadata, and rejecting anything that does not genuinely decode).
 */
class WikiSourceControllerImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_authenticated_customer_user_can_fetch_a_valid_image(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocxDocument($customer, $this->buildDocxWithOneImage());

        $response = $this->actingAs($user)->get("/app/wiki/sources/{$document->id}/images/img0");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertNotSame('', $response->getContent());
    }

    public function test_cross_customer_access_returns_404(): void
    {
        Storage::fake('local');
        $owner = $this->createCustomer('Eier AS');
        $intruder = $this->createCustomer('Fremmed AS');
        $intruderUser = $this->createUser($intruder, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocxDocument($owner, $this->buildDocxWithOneImage());

        $this->actingAs($intruderUser)
            ->get("/app/wiki/sources/{$document->id}/images/img0")
            ->assertNotFound();
    }

    public function test_unknown_image_key_returns_404(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocxDocument($customer, $this->buildDocxWithOneImage());

        $this->actingAs($user)
            ->get("/app/wiki/sources/{$document->id}/images/img99")
            ->assertNotFound();
    }

    public function test_corrupt_image_bytes_return_404_instead_of_broken_content(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocxDocument($customer, $this->buildDocxWithOneImage('not-a-real-png'));

        $this->actingAs($user)
            ->get("/app/wiki/sources/{$document->id}/images/img0")
            ->assertNotFound();
    }

    public function test_served_image_bytes_are_re_encoded_and_do_not_leak_a_local_file_path(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocxDocument($customer, $this->buildDocxWithOneImage());

        $response = $this->actingAs($user)->get("/app/wiki/sources/{$document->id}/images/img0");

        $response->assertOk();
        $this->assertStringNotContainsString((string) storage_path(), (string) $response->getContent());
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    private function buildDocxWithOneImage(?string $mediaBytesOverride = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-wiki-image-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <w:r>
                <w:drawing>
                    <wp:inline>
                        <wp:extent cx="952500" cy="952500"/>
                        <wp:docPr id="1" name="Figur 1" title="Figur 1" descr="Alt-tekst"/>
                        <a:graphic>
                            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:pic>
                                    <pic:blipFill>
                                        <a:blip r:embed="rId1"/>
                                    </pic:blipFill>
                                </pic:pic>
                            </a:graphicData>
                        </a:graphic>
                    </wp:inline>
                </w:drawing>
            </w:r>
        </w:p>
    </w:body>
</w:document>
XML;

        $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;

        $mediaBytes = $mediaBytesOverride ?? base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2X3b8AAAAASUVORK5CYII=',
            true,
        );

        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        $zip->addFromString('word/media/image1.png', $mediaBytes);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function createDocxDocument(Customer $customer, string $docxBytes): EnterpriseWikiDocument
    {
        $filename = 'bilder-'.Str::lower(Str::random(6)).'.docx';
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, $filename),
            'file_hash_sha256' => hash('sha256', $docxBytes),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'Testtekst.',
        ]);

        Storage::disk('local')->put($document->file_path, $docxBytes);

        return $document;
    }

    private function createCustomer(string $name = 'Wiki Image Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Wiki Image Test User',
            'email' => Str::lower(Str::random(8)).'@wiki-image-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
