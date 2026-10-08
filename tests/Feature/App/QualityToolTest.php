<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityTool;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Verktøy — the library of documents a control is carried out with.
 *
 * A tool is a name and a purpose for a file in the existing document archive; the file is never
 * copied. A control's use of a tool is a quality_item_documents row in the `tool` capacity, so one
 * tool serves many controls and removing a use never removes the document. quality.edit registers
 * and links, quality.view reads and downloads.
 */
class QualityToolTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_editor_uploads_a_tool_into_the_existing_document_archive(): void
    {
        Storage::fake('local');

        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($editor)
            ->post('/app/quality/tools', [
                'title' => 'Veiledning for tilgangsgjennomgang',
                'description' => 'Slik gjennomføres kvartalsvis gjennomgang av tilganger.',
                'category' => 'guide',
                'file' => UploadedFile::fake()->createWithContent('veiledning-tilgang.pdf', 'Veiledning.'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The file is an ordinary archive document, on the archive's own path.
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();
        $this->assertStringStartsWith("customers/{$customer->id}/wiki-documents/", $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);

        $tool = QualityTool::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame((int) $document->id, (int) $tool->enterprise_wiki_document_id);
        $this->assertSame('guide', $tool->category);
        $this->assertSame((int) $editor->id, (int) $tool->created_by_user_id);

        $this->actingAs($editor)->get('/app/quality?tab=tools')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('active_tab', 'tools')
                ->where('items', [])
                ->where('tools.0.title', 'Veiledning for tilgangsgjennomgang')
                ->where('tools.0.description', 'Slik gjennomføres kvartalsvis gjennomgang av tilganger.')
                ->where('tools.0.category', 'guide')
                ->where('tools.0.filename', 'veiledning-tilgang.pdf')
                ->where('tools.0.controls', [])
            );
    }

    public function test_an_archive_document_becomes_a_tool_without_a_second_copy(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $document = $this->document($customer, 'mal-avviksrapport.docx');

        $this->actingAs($editor)
            ->post('/app/quality/tools', [
                'title' => 'Mal for avviksrapport',
                'category' => 'template',
                'enterprise_wiki_document_id' => $document->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame((int) $document->id, (int) QualityTool::query()->sole()->enterprise_wiki_document_id);

        // One library entry per file.
        $this->actingAs($editor)
            ->post('/app/quality/tools', ['title' => 'Igjen', 'enterprise_wiki_document_id' => $document->id])
            ->assertSessionHasErrors('enterprise_wiki_document_id');
        $this->assertSame(1, QualityTool::query()->count());
    }

    public function test_one_tool_serves_several_controls_and_removing_a_use_keeps_the_document(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $tool = $this->tool($customer, 'Sjekkliste for tilgangsgjennomgang');
        $first = $this->control($customer, 'Kvartalsvis tilgangsgjennomgang');
        $second = $this->control($customer, 'Årlig gjennomgang av privilegerte kontoer');

        foreach ([$first, $second] as $control) {
            $this->actingAs($editor)
                ->post("/app/quality/items/{$control->id}/tools", ['quality_tool_id' => $tool->id])
                ->assertSessionHasNoErrors();
        }
        // Linking twice is a no-op, not a second row.
        $this->actingAs($editor)->post("/app/quality/items/{$first->id}/tools", ['quality_tool_id' => $tool->id]);

        $uses = QualityItemDocument::query()->where('relation_type', QualityItemDocument::RELATION_TYPE_TOOL)->get();
        $this->assertCount(2, $uses);
        $this->assertTrue($uses->every(fn (QualityItemDocument $use): bool => (int) $use->enterprise_wiki_document_id === (int) $tool->enterprise_wiki_document_id));

        // The library shows where the tool is used, alphabetically — Å last, as in Norwegian.
        $library = $this->actingAs($editor)->get('/app/quality?tab=tools')->assertOk()->viewData('page')['props']['tools'];
        $this->assertSame(
            ['Kvartalsvis tilgangsgjennomgang', 'Årlig gjennomgang av privilegerte kontoer'],
            array_column($library[0]['controls'], 'title'),
        );

        // The control shows the tool in its own section, never in the general document list.
        $props = $this->actingAs($editor)->get("/app/quality/items/{$first->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['Sjekkliste for tilgangsgjennomgang'], array_column($props['control_tools'], 'title'));
        $this->assertSame([], $props['documents']);
        $this->assertSame([], $props['control_tool_options']);
        $this->assertNotContains(QualityItemDocument::RELATION_TYPE_TOOL, $props['document_relation_types']);

        $this->actingAs($editor)
            ->delete("/app/quality/document-links/{$props['control_tools'][0]['link_id']}")
            ->assertRedirect();

        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $first->id)->count());
        $this->assertSame(1, QualityItemDocument::query()->where('quality_item_id', $second->id)->count());
        $this->assertNotNull(QualityTool::query()->find($tool->id));
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($tool->enterprise_wiki_document_id));
    }

    public function test_quality_view_reads_and_downloads_tools_but_cannot_register_or_link(): void
    {
        Storage::fake('local');

        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $tool = $this->tool($customer, 'Metodebeskrivelse');
        Storage::disk('local')->put($tool->document->file_path, '%PDF-1.4 innhold');
        $control = $this->control($customer, 'Stikkprøvekontroll');

        $this->actingAs($reader)->get('/app/quality?tab=tools')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tools.0.title', 'Metodebeskrivelse'));

        // Kvalitet's own file route: quality.view is enough, no Wiki permission needed.
        $this->actingAs($reader)->get("/app/quality/tools/{$tool->id}/file")
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=metodebeskrivelse.pdf');
        $this->actingAs($reader)->get("/app/quality/tools/{$tool->id}/file?download=1")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=metodebeskrivelse.pdf');

        $this->actingAs($reader)->post('/app/quality/tools', [
            'title' => 'Ny',
            'enterprise_wiki_document_id' => $tool->enterprise_wiki_document_id,
        ])->assertForbidden();
        $this->actingAs($reader)
            ->post("/app/quality/items/{$control->id}/tools", ['quality_tool_id' => $tool->id])
            ->assertForbidden();

        $this->assertSame(0, QualityItemDocument::query()->count());
    }

    public function test_tools_stay_inside_their_customer_and_attach_only_to_controls(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $foreignTool = $this->tool($other, 'Fremmed verktøy');
        $ownTool = $this->tool($customer, 'Eget verktøy');
        $control = $this->control($customer, 'Kontroll');

        $this->actingAs($editor)->get("/app/quality/tools/{$foreignTool->id}/file")->assertNotFound();
        $this->actingAs($editor)
            ->post("/app/quality/items/{$control->id}/tools", ['quality_tool_id' => $foreignTool->id])
            ->assertNotFound();
        $this->actingAs($editor)
            ->post('/app/quality/tools', ['title' => 'Fremmed fil', 'enterprise_wiki_document_id' => $foreignTool->enterprise_wiki_document_id])
            ->assertNotFound();

        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Avvikshåndtering',
            'status' => QualityItem::STATUS_DRAFT,
        ]);
        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/tools", ['quality_tool_id' => $ownTool->id])
            ->assertSessionHasErrors('quality_tool_id');

        // The general document form cannot write the tool capacity either.
        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/document-links", [
                'enterprise_wiki_document_id' => $ownTool->enterprise_wiki_document_id,
                'relation_type' => QualityItemDocument::RELATION_TYPE_TOOL,
            ])
            ->assertSessionHasErrors('relation_type');

        $this->assertSame(0, QualityItemDocument::query()->count());
    }

    public function test_deleting_the_file_in_the_archive_removes_the_tool_and_its_uses(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $tool = $this->tool($customer, 'Standardtekst');
        $control = $this->control($customer, 'Kontroll');
        $this->actingAs($editor)->post("/app/quality/items/{$control->id}/tools", ['quality_tool_id' => $tool->id]);

        $result = app(EnterpriseWikiDocumentDeletionService::class)->delete($tool->document, $editor);
        $this->assertFalse($result['blocked']);

        $this->assertNull(QualityTool::query()->find($tool->id));
        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $control->id)->count());
        $this->assertNotNull(QualityItem::query()->find($control->id));
    }

    public function test_the_checklists_tab_is_replaced_by_tools(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);

        // An old link to the retired tab falls back to the overview rather than failing.
        $this->actingAs($reader)->get('/app/quality?tab=checklists')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('active_tab', 'overview'));
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function tool(Customer $customer, string $title): QualityTool
    {
        $document = $this->document($customer, Str::slug($title).'.pdf');

        return QualityTool::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_document_id' => $document->id,
            'title' => $title,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function member(Customer $customer, array $permissionKeys): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'verktoy-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function control(Customer $customer, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);
    }

    private function document(Customer $customer, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', $filename.Str::random(8)),
            'extracted_text' => 'Innhold i '.$filename,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Verktøy AS',
            'slug' => 'kvalitet-verktoy-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        return $customer;
    }
}
