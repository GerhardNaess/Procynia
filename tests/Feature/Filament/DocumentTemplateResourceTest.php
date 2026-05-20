<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplateResource\Pages\CreateDocumentTemplate;
use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class DocumentTemplateResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    public function test_internal_admin_can_open_the_document_template_register(): void
    {
        Storage::fake('local');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Malkunde AS');

        $storedPath = $this->persistStoredUpload(
            $customer,
            $this->createDocxUpload('index-template.docx', 'Dokumentmal med [[PROCYNIA_CONTENT]].'),
            'index-template.docx',
        );

        DocumentTemplate::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Indeks-mal',
            'description' => 'Brukes til å verifisere listen.',
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $storedPath,
            'original_filename' => 'index-template.docx',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->actingAs($admin)
            ->get(DocumentTemplateResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Dokumentmaler')
            ->assertSee('Ny dokumentmal')
            ->assertSee('Word-eksport');
    }

    public function test_customer_admin_cannot_access_document_template_resource(): void
    {
        $customer = $this->createCustomer('Kundeadmin AS');
        $customerAdmin = User::query()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($customerAdmin);

        $this->assertFalse(DocumentTemplateResource::canAccess());
    }

    public function test_customer_user_cannot_access_document_template_resource(): void
    {
        $customer = $this->createCustomer('Sluttbruker AS');
        $customerUser = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer.user+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($customerUser);

        $this->assertFalse(DocumentTemplateResource::canAccess());
    }

    public function test_admin_can_upload_a_valid_docx_template_with_placeholder_from_filament(): void
    {
        Storage::fake('local');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Wordkunde AS');
        $upload = $this->createDocxUpload('firmamal.docx', 'Dette er en testmal med [[PROCYNIA_CONTENT]].');

        Livewire::actingAs($admin)
            ->test(CreateDocumentTemplate::class)
            ->set('data.customer_id', $customer->id)
            ->set('data.name', 'Standard Word-mal')
            ->set('data.description', 'Standard layout for tilbud.')
            ->set('data.file_path', $upload)
            ->set('data.is_active', true)
            ->set('data.is_default', true)
            ->call('create')
            ->assertHasNoErrors();

        $template = DocumentTemplate::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($customer->id, $template->customer_id);
        $this->assertSame('Standard Word-mal', $template->name);
        $this->assertSame('Standard layout for tilbud.', $template->description);
        $this->assertSame(DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT, $template->template_type);
        $this->assertSame('local', $template->file_disk);
        $this->assertSame('firmamal.docx', $template->original_filename);
        $this->assertTrue($template->is_active);
        $this->assertTrue($template->is_default);
        $this->assertNotNull($template->mime_type);
        $this->assertGreaterThan(0, (int) $template->file_size);
        $this->assertStringStartsWith('document-templates/customer-'.$customer->id.'/', $template->file_path);
        $this->assertTrue(Storage::disk('local')->exists($template->file_path));
    }

    public function test_document_template_validation_rejects_docx_without_placeholder(): void
    {
        $upload = $this->createDocxUpload('uten-placeholder.docx', 'Dette dokumentet mangler kontraktteksten.');

        $this->expectException(ValidationException::class);

        DocumentTemplate::validateUploadedWordExportTemplate($upload);
    }

    public function test_document_template_validation_rejects_non_docx_upload(): void
    {
        $upload = UploadedFile::fake()->create('template.txt', 8, 'text/plain');

        $this->expectException(ValidationException::class);

        DocumentTemplate::validateUploadedWordExportTemplate($upload);
    }

    public function test_only_one_default_template_per_customer_and_type_remains_active(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer('Defaultkunde AS');

        $firstUpload = $this->createDocxUpload('first.docx', 'Første mal med [[PROCYNIA_CONTENT]].');
        $firstStoredPath = $this->persistStoredUpload($customer, $firstUpload, 'first.docx');

        $firstTemplate = DocumentTemplate::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Første standardmal',
            'description' => null,
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $firstStoredPath,
            'original_filename' => 'first.docx',
            'is_active' => true,
            'is_default' => true,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);

        $secondUpload = $this->createDocxUpload('second.docx', 'Andre mal med [[PROCYNIA_CONTENT]].');
        $secondStoredPath = $this->persistStoredUpload($customer, $secondUpload, 'second.docx');

        $secondTemplate = DocumentTemplate::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Andre standardmal',
            'description' => null,
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $secondStoredPath,
            'original_filename' => 'second.docx',
            'is_active' => true,
            'is_default' => true,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);

        $firstTemplate->refresh();
        $secondTemplate->refresh();

        $this->assertFalse($firstTemplate->is_default);
        $this->assertTrue($secondTemplate->is_default);
        $this->assertDatabaseCount('document_templates', 2);
    }

    public function test_templates_for_one_customer_do_not_affect_another_customer(): void
    {
        Storage::fake('local');

        $firstCustomer = $this->createCustomer('Kunde A AS');
        $secondCustomer = $this->createCustomer('Kunde B AS');

        $firstUpload = $this->createDocxUpload('customer-a.docx', 'Kunde A mal med [[PROCYNIA_CONTENT]].');
        $firstPath = $this->persistStoredUpload($firstCustomer, $firstUpload, 'customer-a.docx');

        $firstTemplate = DocumentTemplate::query()->create([
            'customer_id' => $firstCustomer->id,
            'name' => 'Kunde A standardmal',
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $firstPath,
            'original_filename' => 'customer-a.docx',
            'is_active' => true,
            'is_default' => true,
        ]);

        $secondUpload = $this->createDocxUpload('customer-b.docx', 'Kunde B mal med [[PROCYNIA_CONTENT]].');
        $secondPath = $this->persistStoredUpload($secondCustomer, $secondUpload, 'customer-b.docx');

        $secondTemplate = DocumentTemplate::query()->create([
            'customer_id' => $secondCustomer->id,
            'name' => 'Kunde B standardmal',
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $secondPath,
            'original_filename' => 'customer-b.docx',
            'is_active' => true,
            'is_default' => true,
        ]);

        $firstTemplate->refresh();
        $secondTemplate->refresh();

        $this->assertTrue($firstTemplate->is_default);
        $this->assertTrue($secondTemplate->is_default);
    }

    private function createDocxUpload(string $filename, string $text): UploadedFile
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText($text);

        $tmpBase = tempnam(sys_get_temp_dir(), 'procynia_docx_');

        if ($tmpBase === false) {
            $this->fail('Unable to create a temporary file for the DOCX fixture.');
        }

        @unlink($tmpBase);
        $docxPath = $tmpBase.'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

        return new class($docxPath, $filename) extends UploadedFile
        {
            public string $name;

            public function __construct(string $path, string $originalName)
            {
                $this->name = $originalName;

                parent::__construct(
                    $path,
                    $originalName,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    null,
                    true,
                );
            }
        };
    }

    private function persistStoredUpload(Customer $customer, UploadedFile $upload, string $filename): string
    {
        $directory = 'document-templates/customer-'.$customer->id;
        $path = $directory.'/'.$filename;

        Storage::disk('local')->putFileAs($directory, $upload, $filename);

        return $path;
    }

    private function internalAdmin(string $name = 'Procynia Admin'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name = 'Procynia AS'): Customer
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
            'is_active' => true,
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.username' => 'gehard',
            'database.connections.pgsql.password' => 'Opaque01',
            'database.connections.pgsql.search_path' => 'public',
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::reconnect('pgsql');
    }
}
