<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Kildedokumenter row was three lines tall for two lines of content: the owner cell stacked a
 * "Lagre eier" button under the select, and the action cell stacked a "Kjøringer" link under the
 * buttons. This pins the compact shape — owner as [name ▼][save icon], actions as one line — and,
 * just as importantly, pins what did NOT change: the same owner endpoint, the same explicit save,
 * the same status codes.
 *
 * The row markup is asserted at source level; the project has no JSX renderer, and this is the same
 * guard style the rest of this suite uses for layout contracts.
 */
class WikiSourceRowLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app->setLocale('no');
    }

    // =========================================================================
    // Document owner: name only, save as an icon on the same line
    // =========================================================================

    public function test_the_owner_options_payload_carries_a_bare_name_for_the_row_select(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Ada Eier');

        $options = $this->sourcesProps($viewer)['document_owner_options'];
        $ada = collect($options)->firstWhere('id', $viewer->id);

        $this->assertSame('Ada Eier', $ada['name']);
        $this->assertStringNotContainsString('@', $ada['name'], 'the row select must not show an e-mail');
        $this->assertSame("Ada Eier · {$viewer->email}", $ada['label'], 'the filter and upload picker keep the e-mail');
    }

    public function test_the_row_select_renders_the_name_and_the_filter_keeps_the_label(): void
    {
        $tab = $this->sourcesTabSource();

        $this->assertStringContainsString('{option.name ?? option.label}', $tab, 'the row select shows the bare name');
        $this->assertStringContainsString('{owner.label}', $tab, 'the owner filter still shows name · e-mail');
    }

    public function test_the_separate_save_owner_text_button_is_gone(): void
    {
        $tab = $this->sourcesTabSource();

        $this->assertStringNotContainsString(
            "className={ACTION_BUTTON_SECONDARY}\n                                                        >\n                                                            {savingOwnerIds",
            $tab,
            'the owner cell must not render a full-width text button any more',
        );
        $this->assertStringNotContainsString('space-y-2', $this->ownerCellSource(), 'the owner cell is one line, not a stack');
    }

    public function test_the_save_owner_action_survives_as_an_icon_button_with_an_accessible_name(): void
    {
        $cell = $this->ownerCellSource();

        $this->assertStringContainsString('ICON_BUTTON_SECONDARY', $cell);
        $this->assertStringContainsString('handleOwnerSave(source)', $cell, 'the same save handler is kept');
        $this->assertStringContainsString('aria-label=', $cell);
        $this->assertStringContainsString('title=', $cell);
        $this->assertStringContainsString('document_owner_save', $cell);
        $this->assertStringContainsString('document_owner_saving', $cell, 'the loading state is kept');
        $this->assertStringContainsString('disabled={savingOwnerIds.has(source.id)}', $cell);
        $this->assertStringContainsString('aria-hidden="true"', $cell, 'the glyph itself is decorative; the button carries the name');
    }

    public function test_the_owner_select_still_does_not_autosave(): void
    {
        $cell = $this->ownerCellSource();

        $this->assertStringContainsString('onChange={(event) => handleOwnerChange(source.id, event.target.value)}', $cell);
        $this->assertStringNotContainsString('onChange={(event) => handleOwnerSave', $cell, 'saving stays explicit');
    }

    public function test_the_save_owner_request_is_unchanged(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');
        $document = $this->createDocument($customer, 'rutine.docx', $viewer);

        $this->actingAs($viewer)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $bo->id,
                'tab' => 'sources',
            ])
            ->assertRedirect();

        $this->assertSame($bo->id, $document->fresh()->owner_user_id);
    }

    // =========================================================================
    // Actions: one line, no Kjøringer link
    // =========================================================================

    public function test_download_create_wiki_and_delete_share_one_action_container(): void
    {
        $actions = $this->actionCellSource();
        $container = mb_substr($actions, mb_strpos($actions, 'flex flex-wrap items-center gap-2'));

        $download = mb_strpos($container, 'source_download_button');
        $ingest = mb_strpos($container, 'source_ingest_button');
        $delete = mb_strpos($container, 'source_delete_button');

        foreach (['download' => $download, 'ingest' => $ingest, 'delete' => $delete] as $name => $position) {
            $this->assertNotFalse($position, "{$name} must sit inside the single action row");
        }

        $this->assertLessThan($ingest, $download);
        $this->assertLessThan($delete, $ingest);
    }

    public function test_the_delete_button_keeps_its_destructive_styling_and_confirm_flow(): void
    {
        $actions = $this->actionCellSource();

        $this->assertStringContainsString('ACTION_BUTTON_DESTRUCTIVE', $actions);
        $this->assertStringContainsString('handleDeleteClick(source)', $actions);
    }

    public function test_the_runs_link_is_gone_from_the_document_row(): void
    {
        $tab = $this->sourcesTabSource();

        $this->assertStringNotContainsString('tab=runs&run_src=', $tab, 'the per-row Kjøringer link is removed');
        $this->assertStringNotContainsString('runs_view_runs', $tab);
    }

    public function test_the_dead_runs_link_translation_key_was_removed_from_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $wiki = (require base_path("lang/{$locale}/procynia.php"))['wiki'] ?? [];

            $this->assertArrayNotHasKey('runs_view_runs', $wiki);
        }
    }

    public function test_the_kjoringer_tab_itself_is_untouched(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Ada Eier');

        $props = data_get(
            $this->actingAs($viewer)->get('/app/wiki?tab=runs')->assertOk()->viewData('page'),
            'props',
            [],
        );

        $this->assertSame('runs', $props['active_tab']);
        $this->assertArrayHasKey('runs', $props);
    }

    // =========================================================================
    // Copy
    // =========================================================================

    public function test_the_ingest_button_reads_lag_wiki(): void
    {
        $this->assertSame('Lag Wiki', trans('procynia.wiki.source_ingest_button'));
        $this->assertStringNotContainsString('utkast', trans('procynia.wiki.source_ingest_button'));

        $this->app->setLocale('en');
        $this->assertSame('Create Wiki', trans('procynia.wiki.source_ingest_button'));
        $this->assertStringNotContainsString('draft', trans('procynia.wiki.source_ingest_button'));
        $this->app->setLocale('no');
    }

    public function test_the_awaiting_approval_status_reads_short(): void
    {
        $this->assertSame('Avventer godkjenning', trans('procynia.wiki.ingest_status_awaiting_document_owner_approval'));

        $this->app->setLocale('en');
        $this->assertSame('Awaiting approval', trans('procynia.wiki.ingest_status_awaiting_document_owner_approval'));
        $this->app->setLocale('no');
    }

    public function test_no_surface_still_says_venter_paa_dokumenteiergodkjenning(): void
    {
        foreach (['no' => 'Venter på dokumenteiergodkjenning', 'en' => 'Waiting for document owner approval'] as $locale => $retired) {
            $wiki = (require base_path("lang/{$locale}/procynia.php"))['wiki'] ?? [];

            $this->assertNotContains($retired, $wiki, "[{$locale}] still carries the long status wording");
        }

        $this->assertStringNotContainsString(
            'Venter på dokumenteiergodkjenning',
            (string) file_get_contents(base_path('resources/js/Pages/App/Wiki/Index.jsx')),
            'the JS status fallback must match the language files',
        );
    }

    // =========================================================================
    // Backend semantics unchanged
    // =========================================================================

    public function test_the_status_code_and_run_lifecycle_are_unchanged(): void
    {
        $this->assertSame('awaiting_document_owner_approval', EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $this->assertContains(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, EnterpriseWikiIngestRun::STATUSES);
        $this->assertSame('extracted', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
    }

    public function test_the_row_still_carries_the_same_owner_data_it_always_did(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Ada Eier');
        $this->createDocument($customer, 'rutine.docx', $viewer);

        $row = $this->sourcesProps($viewer)['sources'][0];

        $this->assertSame($viewer->id, $row['owner_user_id']);
        $this->assertSame('Ada Eier', $row['owner_name']);
        $this->assertSame($viewer->email, $row['owner_email'], 'e-mail is still in the payload, only out of the select');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function sourcesTabSource(): string
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/App/Wiki/Index.jsx'));
        $start = mb_strpos($source, 'function SourcesTab(');
        $end = mb_strpos($source, "\nconst RUNS_SELECT_CLS", $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($source, $start, $end - $start);
    }

    private function ownerCellSource(): string
    {
        $tab = $this->sourcesTabSource();
        $start = mb_strpos($tab, '{canAssignDocumentOwner ? (');
        $end = mb_strpos($tab, 'sourceOwnerLabel}', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($tab, $start, $end - $start);
    }

    private function actionCellSource(): string
    {
        $tab = $this->sourcesTabSource();
        $start = mb_strpos($tab, 'One action container');

        $this->assertNotFalse($start, 'the single action container must exist');

        return mb_substr($tab, $start);
    }

    /** @return array<string, mixed> */
    private function sourcesProps(User $user): array
    {
        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');
        $response->assertOk();

        return data_get($response->viewData('page'), 'props', []);
    }

    private function createCustomer(string $name = 'Radopprydding AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::lower(Str::random(8)).'@radopprydding.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, string $filename, User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'owner_user_id' => $owner->id,
        ]);
    }
}
