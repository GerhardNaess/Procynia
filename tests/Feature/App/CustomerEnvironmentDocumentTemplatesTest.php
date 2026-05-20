<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class CustomerEnvironmentDocumentTemplatesTest extends TestCase
{
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

    public function test_system_owner_can_see_document_templates_tab(): void
    {
        $context = $this->customerActorContext(User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);

        $this->assertDocumentTemplatesTabVisible($context['user']);
    }

    public function test_bid_manager_can_see_document_templates_tab(): void
    {
        $context = $this->customerActorContext(User::BID_ROLE_BID_MANAGER, User::ROLE_CUSTOMER_ADMIN, [
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ]);

        $this->assertDocumentTemplatesTabVisible($context['user']);
    }

    public function test_contributor_can_see_document_templates_tab(): void
    {
        $context = $this->customerActorContext(User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $this->assertDocumentTemplatesTabVisible($context['user']);
    }

    public function test_view_only_customer_user_cannot_manage_document_templates(): void
    {
        $context = $this->customerActorContext(User::BID_ROLE_VIEWER, User::ROLE_USER);

        $this->actingAs($context['user'])->get('/app/customer-environment')->assertForbidden();
    }

    public function test_customer_can_upload_a_docx_document_template_for_own_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerActorContext(User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $response = $this->postWithCsrf($context['user'], route('app.customer-environment.document-templates.store', [], false), [
            'name' => 'Standard Word-mal',
            'description' => 'Brukes til Word-eksport.',
            'file_path' => $this->createDocxUpload('standard-mal.docx', 'Malen har [[PROCYNIA_CONTENT]].'),
            'is_active' => true,
            'is_default' => true,
            'redirect_to' => '/app/customer-environment?tab=document-templates',
        ]);

        $response->assertRedirect('/app/customer-environment?tab=document-templates');
        $response->assertSessionHas('success', 'Dokumentmalen ble lastet opp.');

        $template = DocumentTemplate::query()
            ->where('customer_id', $context['customer']->id)
            ->where('name', 'Standard Word-mal')
            ->firstOrFail();

        $this->assertSame($context['customer']->id, $template->customer_id);
        $this->assertSame(DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT, $template->template_type);
        $this->assertSame('local', $template->file_disk);
        $this->assertSame('standard-mal.docx', $template->original_filename);
        $this->assertTrue($template->is_active);
        $this->assertTrue($template->is_default);
        $this->assertStringStartsWith('document-templates/customer-'.$context['customer']->id.'/', $template->file_path);
        Storage::disk('local')->assertExists($template->file_path);
    }

    public function test_non_docx_document_template_upload_is_rejected(): void
    {
        $context = $this->customerActorContext(User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $response = $this->postWithCsrf($context['user'], route('app.customer-environment.document-templates.store', [], false), [
            'name' => 'Feil mal',
            'description' => 'Skal avvises.',
            'file_path' => UploadedFile::fake()->create('template.txt', 8, 'text/plain'),
            'is_active' => true,
            'is_default' => true,
            'redirect_to' => '/app/customer-environment?tab=document-templates',
        ]);

        $response->assertSessionHasErrors('file_path');
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_docx_without_procynia_content_placeholder_is_rejected(): void
    {
        $context = $this->customerActorContext(User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $response = $this->postWithCsrf($context['user'], route('app.customer-environment.document-templates.store', [], false), [
            'name' => 'Manglende placeholder',
            'description' => 'Skal avvises.',
            'file_path' => $this->createDocxUpload('uten-placeholder.docx', 'Dette dokumentet mangler plassholderen.'),
            'is_active' => true,
            'is_default' => true,
            'redirect_to' => '/app/customer-environment?tab=document-templates',
        ]);

        $response->assertSessionHasErrors('file_path');
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_only_one_default_template_per_customer_and_type_remains_set(): void
    {
        Storage::fake('local');

        $context = $this->customerActorContext(User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $firstTemplate = $this->storeTemplateViaCustomerEnvironment(
            $context['user'],
            $context['customer']->id,
            'Første mal',
            'første.docx',
            'Første mal med [[PROCYNIA_CONTENT]].',
        );

        $this->assertTrue($firstTemplate->is_default);

        $secondTemplate = $this->storeTemplateViaCustomerEnvironment(
            $context['user'],
            $context['customer']->id,
            'Andre mal',
            'andre.docx',
            'Andre mal med [[PROCYNIA_CONTENT]].',
        );

        $firstTemplate->refresh();
        $secondTemplate->refresh();

        $this->assertFalse($firstTemplate->is_default);
        $this->assertTrue($secondTemplate->is_default);
        $this->assertDatabaseCount('document_templates', 2);
    }

    public function test_deactivated_standard_template_is_not_used_as_active_default(): void
    {
        Storage::fake('local');

        $context = $this->customerActorContext(User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $template = $this->storeTemplateViaCustomerEnvironment(
            $context['user'],
            $context['customer']->id,
            'Deaktiverbar mal',
            'deaktiverbar.docx',
            'Deaktiverbar mal med [[PROCYNIA_CONTENT]].',
        );

        $response = $this->patchWithCsrf($context['user'], route('app.customer-environment.document-templates.toggle-active', ['documentTemplate' => $template->id], false), [
            'redirect_to' => '/app/customer-environment?tab=document-templates',
        ]);

        $response->assertRedirect('/app/customer-environment?tab=document-templates');

        $template->refresh();

        $this->assertFalse($template->is_active);
        $this->assertFalse($template->is_default);
        $this->assertNull(DocumentTemplate::activeWordExportForCustomer($context['customer']->id));
    }

    public function test_customer_a_cannot_see_or_edit_customer_b_document_templates(): void
    {
        Storage::fake('local');

        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');
        $actorA = $this->createActor($customerA, User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);
        $actorB = $this->createActor($customerB, User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $templateA = $this->storeTemplateViaCustomerEnvironment(
            $actorA,
            $customerA->id,
            'Kunde A mal',
            'kunde-a.docx',
            'Kunde A med [[PROCYNIA_CONTENT]].',
        );

        $templateB = $this->storeTemplateViaCustomerEnvironment(
            $actorB,
            $customerB->id,
            'Kunde B mal',
            'kunde-b.docx',
            'Kunde B med [[PROCYNIA_CONTENT]].',
        );

        $this->actingAs($actorA)
            ->get('/app/customer-environment?tab=document-templates')
            ->assertOk()
            ->assertViewHas('page', function (array $page) use ($templateA, $templateB): bool {
                $templates = collect(data_get($page, 'props.documentTemplates', []));

                return data_get($page, 'component') === 'App/CustomerEnvironment/Index'
                    && $templates->contains(fn (array $template): bool => (int) $template['id'] === (int) $templateA->id)
                    && ! $templates->contains(fn (array $template): bool => (int) $template['id'] === (int) $templateB->id);
            });

        $this->patchWithCsrf($actorA, route('app.customer-environment.document-templates.update', ['documentTemplate' => $templateB->id], false), [
            'name' => 'Forsøk på feil kunde',
            'description' => 'Skal ikke virke.',
            'redirect_to' => '/app/customer-environment?tab=document-templates',
        ])->assertNotFound();
    }

    private function assertDocumentTemplatesTabVisible(User $user): void
    {
        $this->actingAs($user)
            ->get('/app/customer-environment?tab=document-templates')
            ->assertOk()
            ->assertViewHas('page', function (array $page): bool {
                return data_get($page, 'component') === 'App/CustomerEnvironment/Index'
                    && data_get($page, 'props.activeTab') === 'document-templates'
                    && data_get($page, 'props.canManageDocumentTemplates') === true;
            });
    }

    private function storeTemplateViaCustomerEnvironment(User $actor, int $customerId, string $name, string $filename, string $content): DocumentTemplate
    {
        $response = $this->postWithCsrf($actor, route('app.customer-environment.document-templates.store', [], false), [
            'name' => $name,
            'description' => 'Kundemal',
            'file_path' => $this->createDocxUpload($filename, $content),
            'is_active' => true,
            'is_default' => true,
            'redirect_to' => '/app/customer-environment?tab=document-templates',
        ]);

        $response->assertRedirect('/app/customer-environment?tab=document-templates');

        return DocumentTemplate::query()
            ->where('customer_id', $customerId)
            ->where('name', $name)
            ->firstOrFail();
    }

    private function customerActorContext(string $bidRole, string $role, array $attributes = []): array
    {
        $customer = $this->createCustomer('Procynia AS');

        $user = User::query()->create(array_merge([
            'name' => ucfirst(str_replace('_', ' ', $bidRole)).' User',
            'email' => 'customer.actor+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ], $attributes));

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    private function createActor(Customer $customer, string $bidRole, string $role, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => ucfirst(str_replace('_', ' ', $bidRole)).' User',
            'email' => 'customer.actor+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ], $attributes));
    }

    private function createCustomer(string $name): Customer
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

    private function postWithCsrf(User $user, string $uri, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->post($uri, ['_token' => 'test-token', ...$data]);
    }

    private function patchWithCsrf(User $user, string $uri, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->patch($uri, ['_token' => 'test-token', ...$data]);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia',
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
