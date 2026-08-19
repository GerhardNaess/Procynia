<?php

namespace Tests\Feature\App\Wiki;

use App\Http\Controllers\Concerns\RedirectsToWikiIndexTab;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kildedokumenter can be filtered by document owner, server-side.
 *
 * The owner list is NOT a new definition of who may own a document: it is the same
 * WikiController::documentOwnerOptionsForCustomer() the row's own owner select already renders, so
 * the filter can never offer someone the row cannot assign. That shared list is also what makes the
 * parameter safe — a requested id is only honoured when it appears in it, which is customer-scoped
 * and permission-gated, so a foreign or invented id is inert rather than a way to address another
 * customer's rows.
 *
 * Filtering only. Nothing here touches how ownership is set, saved or authorized.
 */
class WikiSourcesDocumentOwnerFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // The option list
    // =========================================================================

    public function test_the_filter_offers_the_customers_own_document_owners(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $owner = $this->createUser($customer, 'Ada Eier');

        $options = $this->sourcesProps($viewer)['document_owner_options'];
        $ids = array_column($options, 'id');

        $this->assertContains($owner->id, $ids);
        $this->assertContains($viewer->id, $ids);
        $this->assertSame(
            "{$owner->name} · {$owner->email}",
            collect($options)->firstWhere('id', $owner->id)['label'],
            'the filter reuses the existing owner presentation',
        );
    }

    public function test_no_owner_from_another_customer_leaks_into_the_filter(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $foreign = $this->createUser($this->createCustomer('Annen Kunde AS'), 'Fremmed Eier');

        $ids = array_column($this->sourcesProps($viewer)['document_owner_options'], 'id');

        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_an_inactive_user_is_not_offered_as_a_filter_option(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $inactive = $this->createUser($customer, 'Inaktiv Eier');
        $inactive->forceFill(['is_active' => false])->save();

        $ids = array_column($this->sourcesProps($viewer)['document_owner_options'], 'id');

        $this->assertNotContains($inactive->id, $ids, 'the filter must not diverge from the row owner select');
    }

    // =========================================================================
    // The filter itself
    // =========================================================================

    public function test_selecting_an_owner_shows_only_that_owners_documents(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');

        $adasDocument = $this->createDocument($customer, 'ada-rutine.docx', $ada);
        $bosDocument = $this->createDocument($customer, 'bo-rutine.docx', $bo);

        $props = $this->sourcesProps($viewer, ['src_owner' => $ada->id]);

        $this->assertSame([$adasDocument->id], $this->sourceIds($props));
        $this->assertNotContains($bosDocument->id, $this->sourceIds($props));
        $this->assertSame($ada->id, $props['sources_filters']['document_owner']);
    }

    public function test_all_document_owners_shows_every_document_again(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');

        $this->createDocument($customer, 'ada-rutine.docx', $ada);
        $this->createDocument($customer, 'bo-rutine.docx', $bo);

        foreach ([[], ['src_owner' => '']] as $query) {
            $props = $this->sourcesProps($viewer, $query);

            $this->assertCount(2, $props['sources']);
            $this->assertNull($props['sources_filters']['document_owner']);
        }
    }

    public function test_the_owner_filter_combines_with_the_filename_search(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');

        $match = $this->createDocument($customer, 'rutine-beredskap.docx', $ada);
        $this->createDocument($customer, 'rutine-beredskap.docx', $bo);
        $this->createDocument($customer, 'annet-notat.docx', $ada);

        $props = $this->sourcesProps($viewer, ['src_owner' => $ada->id, 'src_q' => 'beredskap']);

        $this->assertSame([$match->id], $this->sourceIds($props));
    }

    public function test_the_owner_filter_combines_with_the_status_filter(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');

        $match = $this->createDocument($customer, 'ada-feilet.docx', $ada, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);
        $this->createDocument($customer, 'ada-ok.docx', $ada, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $this->createDocument($customer, 'bo-feilet.docx', $bo, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);

        $props = $this->sourcesProps($viewer, [
            'src_owner' => $ada->id,
            'src_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ]);

        $this->assertSame([$match->id], $this->sourceIds($props));
    }

    public function test_all_three_filters_apply_together(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');

        $match = $this->createDocument($customer, 'rutine-beredskap.docx', $ada, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);
        $this->createDocument($customer, 'rutine-beredskap.docx', $ada, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $this->createDocument($customer, 'rutine-beredskap.docx', $bo, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);
        $this->createDocument($customer, 'annet-notat.docx', $ada, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);

        $props = $this->sourcesProps($viewer, [
            'src_owner' => $ada->id,
            'src_q' => 'beredskap',
            'src_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ]);

        $this->assertSame([$match->id], $this->sourceIds($props));
        $this->assertSame('beredskap', $props['sources_filters']['search']);
        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED, $props['sources_filters']['status']);
        $this->assertSame($ada->id, $props['sources_filters']['document_owner']);
    }

    // =========================================================================
    // Safety
    // =========================================================================

    public function test_an_owner_id_from_another_customer_never_addresses_another_customers_rows(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $own = $this->createDocument($customer, 'ada-rutine.docx', $ada);

        $foreignCustomer = $this->createCustomer('Annen Kunde AS');
        $foreignOwner = $this->createUser($foreignCustomer, 'Fremmed Eier');
        $foreignDocument = $this->createDocument($foreignCustomer, 'fremmed.docx', $foreignOwner);

        $props = $this->sourcesProps($viewer, ['src_owner' => $foreignOwner->id]);

        $this->assertNull($props['sources_filters']['document_owner'], 'a foreign owner id is never honoured');
        $this->assertSame([$own->id], $this->sourceIds($props));
        $this->assertNotContains($foreignDocument->id, $this->sourceIds($props));
    }

    public function test_a_nonsense_owner_id_is_ignored_rather_than_hiding_everything(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer');
        $ada = $this->createUser($customer, 'Ada Eier');
        $document = $this->createDocument($customer, 'ada-rutine.docx', $ada);

        foreach (['999999', 'abc', '-1', '0'] as $value) {
            $props = $this->sourcesProps($viewer, ['src_owner' => $value]);

            $this->assertNull($props['sources_filters']['document_owner'], "[{$value}] must not be honoured");
            $this->assertSame([$document->id], $this->sourceIds($props));
        }
    }

    public function test_the_filter_survives_a_write_action_on_this_tab(): void
    {
        $this->assertContains(
            'src_owner',
            (new class
            {
                use RedirectsToWikiIndexTab;

                /** @return list<string> */
                public function sourcesFilterKeys(): array
                {
                    $reflection = new \ReflectionClass($this);

                    return $reflection->getConstant('WIKI_TAB_FILTER_KEYS')['sources'] ?? [];
                }
            })->sourcesFilterKeys(),
            'a redirect back to Kildedokumenter must carry the active owner filter',
        );
    }

    // =========================================================================
    // Untouched behaviour
    // =========================================================================

    public function test_the_row_owner_data_and_the_owner_endpoint_are_unchanged(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, 'Viewer', User::BID_ROLE_SYSTEM_OWNER);
        $ada = $this->createUser($customer, 'Ada Eier');
        $bo = $this->createUser($customer, 'Bo Eier');
        $document = $this->createDocument($customer, 'ada-rutine.docx', $ada);

        $row = $this->sourcesProps($viewer)['sources'][0];

        $this->assertSame($ada->id, $row['owner_user_id']);
        $this->assertSame($ada->name, $row['owner_name']);
        $this->assertSame($ada->email, $row['owner_email']);
        $this->assertTrue($row['owner_is_active']);

        // Assigning an owner still works exactly as before, and the redirect keeps the tab.
        $this->actingAs($viewer)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $bo->id,
                'tab' => 'sources',
                'src_owner' => (string) $ada->id,
            ])
            ->assertRedirect();

        $this->assertSame($bo->id, $document->fresh()->owner_user_id);
    }

    public function test_the_filter_bar_renders_search_then_status_then_owner(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/App/Wiki/Index.jsx'));
        $tab = mb_substr($source, mb_strpos($source, 'function SourcesTab('));

        $search = mb_strpos($tab, 'sources_search_placeholder');
        $status = mb_strpos($tab, 'sources_status_all');
        $owner = mb_strpos($tab, 'filter_document_owner_all');

        $this->assertNotFalse($search);
        $this->assertNotFalse($status);
        $this->assertNotFalse($owner, 'the owner filter must be rendered on the sources tab');
        $this->assertLessThan($status, $search, 'search comes first');
        $this->assertLessThan($owner, $status, 'the owner filter sits after the status filter');
    }

    public function test_both_languages_already_carry_the_filter_label(): void
    {
        foreach (['no' => 'Alle dokumenteiere', 'en' => 'All document owners'] as $locale => $expected) {
            $wiki = (require base_path("lang/{$locale}/procynia.php"))['wiki'] ?? [];

            $this->assertSame($expected, $wiki['filter_document_owner_all'] ?? null);
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return array<string, mixed> */
    private function sourcesProps(User $user, array $query = []): array
    {
        $url = '/app/wiki?'.http_build_query(array_merge(['tab' => 'sources'], $query));
        $response = $this->actingAs($user)->get($url);

        $response->assertOk();

        $props = data_get($response->viewData('page'), 'props', []);

        $this->assertSame('sources', $props['active_tab']);

        return $props;
    }

    /** @return list<int> */
    private function sourceIds(array $props): array
    {
        return collect($props['sources'])->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function createCustomer(string $name = 'Kildefilter AS'): Customer
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

    private function createUser(Customer $customer, string $name, string $bidRole = User::BID_ROLE_SYSTEM_OWNER): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::lower(Str::random(8)).'@kildefilter.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(
        Customer $customer,
        string $filename,
        User $owner,
        string $status = EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
    ): EnterpriseWikiDocument {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => $status,
            'owner_user_id' => $owner->id,
        ]);
    }
}
