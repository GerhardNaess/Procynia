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
 * The Kildedokumenter row is compact: the document owner is a name, changed in a dialog rather than
 * with a select and a save button stacked in the row, and «Lag Wiki» is the one visible action, with
 * «Last ned» and «Slett» in a ⋯ panel (086ad35e). What these tests defend is what that shape is for,
 * and what must not change with it: an explicit save to the same owner endpoint, accessible names
 * on the icon-only controls, the destructive delete behind its confirmation, and no per-row link
 * into Kjøringer except when a run actually needs attention.
 *
 * The markup is asserted at the level of the components that render it (SourceOwner,
 * SourceRowActions, IngestStatusBadge and the owner dialog in SourcesTab) — the project has no JSX
 * renderer. The same behaviour is exercised in a real browser by
 * tests/e2e/wiki-source-upload-readability.spec.js (14–16).
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

    /** The row shows the owner as a name, not a select with a save button stacked under it. */
    public function test_the_row_shows_the_owner_as_a_name_with_an_edit_button_not_a_select(): void
    {
        $owner = $this->componentSource('SourceOwner');

        $this->assertStringContainsString('{source.owner_name ??', $owner, 'the owner is shown by name');
        $this->assertStringNotContainsString('<select', $owner, 'no select in the row');
        $this->assertStringNotContainsString('space-y-2', $owner, 'the owner cell is one line, not a stack');
        $this->assertStringNotContainsString('handleOwnerChange', $this->sourcesTabSource(), 'the row no longer changes the owner itself');

        // The icon-only button carries its own name, says which document it is about, and the
        // glyph is decorative.
        $this->assertStringContainsString('aria-label={editLabel}', $owner);
        $this->assertStringContainsString('title={editLabel}', $owner);
        $this->assertStringContainsString('aria-describedby={describedById}', $owner);
        $this->assertStringContainsString('aria-hidden="true"', $owner);
        $this->assertStringContainsString('onClick={(event) => onEdit(source, event.currentTarget)}', $owner, 'focus returns to the pencil');
    }

    /** Changing the owner is a labelled dialog with an explicit save, its busy state and its error. */
    public function test_the_owner_is_saved_explicitly_from_a_labelled_dialog(): void
    {
        $dialog = $this->ownerDialogSource();

        $this->assertStringContainsString('titleId="wiki-source-owner-edit-title"', $dialog);
        $this->assertStringContainsString('<form onSubmit={handleOwnerSave} noValidate>', $dialog);
        $this->assertStringContainsString('<label htmlFor="wiki-source-owner-edit"', $dialog);
        $this->assertStringContainsString('{option.name ?? option.label}', $dialog, 'the select shows the bare name');
        $this->assertStringContainsString('type="submit"', $dialog);
        $this->assertStringContainsString('document_owner_save', $dialog);
        $this->assertStringContainsString('document_owner_saving', $dialog, 'the loading state is kept');
        $this->assertStringContainsString('aria-disabled={ownerEdit.saving || undefined}', $dialog, 'busy, not disabled: focus stays put');
        $this->assertStringContainsString('<p id="wiki-source-owner-edit-error" role="alert"', $dialog);
    }

    /** Choosing a name saves nothing; only the save does, to the same endpoint as before. */
    public function test_the_owner_select_still_does_not_autosave(): void
    {
        $dialog = $this->ownerDialogSource();
        $select = mb_substr($dialog, mb_strpos($dialog, '<select'), mb_strpos($dialog, '</select>') - mb_strpos($dialog, '<select'));

        $this->assertStringContainsString('setOwnerEdit(', $select);
        $this->assertStringNotContainsString('router.', $select, 'the select never submits on change');
        $this->assertStringNotContainsString('handleOwnerSave', $select);

        $tab = $this->sourcesTabSource();
        $save = mb_substr($tab, mb_strpos($tab, 'const handleOwnerSave = (event) => {'));
        $this->assertStringContainsString('router.patch(', $save);
        $this->assertStringContainsString('`/app/wiki/sources/${source.id}/owner`', $save);
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

    /** «Lag Wiki» is the one visible action; «Last ned» then «Slett» are in the ⋯ panel. */
    public function test_lag_wiki_is_the_visible_action_and_download_and_delete_are_in_the_panel(): void
    {
        $actions = $this->componentSource('SourceRowActions');

        $ingest = mb_strpos($actions, 'source_ingest_button');
        $menu = mb_strpos($actions, 'aria-expanded={isOpen}');
        $panel = mb_strpos($actions, '{isOpen && position && (');
        $download = mb_strpos($actions, 'source_download_button');
        $delete = mb_strpos($actions, 'source_delete_button');

        foreach (['ingest' => $ingest, 'menu' => $menu, 'panel' => $panel, 'download' => $download, 'delete' => $delete] as $name => $position) {
            $this->assertNotFalse($position, "{$name} is missing from the row actions");
        }

        // «Lag Wiki» before the ⋯ button, outside the panel; Last ned and Slett inside it, in order.
        $this->assertLessThan($menu, $ingest);
        $this->assertLessThan($download, $panel);
        $this->assertLessThan($delete, $download);

        // The ⋯ button is a disclosure with a name, pointing at the panel it opens.
        $this->assertStringContainsString('aria-controls={isOpen ? panelId : undefined}', $actions);
        $this->assertStringContainsString('aria-label={menuLabel}', $actions);
    }

    /** Slett keeps its destructive styling and goes through the delete preview and its confirmation. */
    public function test_the_delete_button_keeps_its_destructive_styling_and_confirm_flow(): void
    {
        $actions = $this->componentSource('SourceRowActions');
        $deleteButton = mb_substr($actions, mb_strpos($actions, '{canDelete && ('));

        $this->assertStringContainsString('text-rose-700', $deleteButton, 'destructive styling');
        $this->assertStringContainsString('onDelete(source', $deleteButton);

        $tab = $this->sourcesTabSource();
        $this->assertStringContainsString('onDelete={handleDeleteClick}', $tab);

        $confirm = mb_substr($tab, mb_strpos($tab, 'const handleDeleteClick = (source, trigger = null) => {'));
        $this->assertStringContainsString('/delete-preview`', $confirm, 'nothing is deleted before the preview');

        $this->assertStringContainsString('descriptionId="wiki-source-delete-description"', $this->indexSource(), 'the confirmation is a described dialog');
    }

    /**
     * The row no longer carries a link into Kjøringer for every document. It links there only when
     * the latest run needs attention (failed, escalated or apparently stalled), as «Se detaljer».
     */
    public function test_the_row_links_to_kjoringer_only_when_a_run_needs_attention(): void
    {
        $this->assertStringNotContainsString('runs_view_runs', $this->sourcesTabSource(), 'the per-row Kjøringer link is gone');

        $badge = $this->componentSource('IngestStatusBadge');
        $link = mb_strpos($badge, '<Link href={detailsHref}');

        $this->assertNotFalse($link);
        $this->assertMatchesRegularExpression('/\{needsAttention && detailsHref && \(\s*<Link href=\{detailsHref\}/', $badge);
        $this->assertStringContainsString("const needsAttention = run.status === 'failed' || run.status === 'escalated' || seemsStalled;", $badge);
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

    private function indexSource(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/App/Wiki/Index.jsx'));
    }

    /** One top-level component of Index.jsx, from its declaration to the next top-level declaration. */
    private function componentSource(string $name): string
    {
        $source = $this->indexSource();
        $start = mb_strpos($source, "\nfunction {$name}(");

        $this->assertNotFalse($start, "component {$name} not found");

        $ends = array_filter([
            mb_strpos($source, "\nfunction ", $start + 1),
            mb_strpos($source, "\nconst ", $start + 1),
            mb_strpos($source, "\nexport ", $start + 1),
        ], static fn (mixed $position): bool => $position !== false);

        return mb_substr($source, $start, ($ends === [] ? mb_strlen($source) : min($ends)) - $start);
    }

    private function sourcesTabSource(): string
    {
        return $this->componentSource('SourcesTab');
    }

    private function ownerDialogSource(): string
    {
        $tab = $this->sourcesTabSource();
        $start = mb_strpos($tab, 'isOpen={ownerEdit !== null}');
        $end = mb_strpos($tab, '</ActionDialog>', (int) $start);

        $this->assertNotFalse($start, 'the owner dialog must exist');
        $this->assertNotFalse($end);

        return mb_substr($tab, $start, $end - $start);
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
