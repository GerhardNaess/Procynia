<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Enterprise Wiki through the API (WIK-01, WIK-02, D18): the published version of each published
 * page, under the Wiki's own read rule (the module and wiki.view) — never a working version, never
 * a page that was not published, and nothing written by reading.
 */
class ApiWikiReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    protected bool $customersHoldTenderPackage = false;

    private const ROW_KEYS = ['id', 'title', 'slug', 'page_type', 'scope', 'version_number', 'headings', 'excerpt'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_the_list_holds_published_versions_only_and_never_a_working_version(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $published = $this->page($customer, 'tjenestebeskrivelse', 'Tjenestebeskrivelse', "# Tjenestebeskrivelse\n\n## Svartid\n\nSvartiden er fire timer.");
        // In review: the published version 1 still answers; the working version 2 is never shown.
        $inReview = $this->page($customer, 'sikkerhet', 'Sikkerhet', "# Sikkerhet\n\nPUBLISERT-TEKST om tilgang.", status: EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->workingVersion($inReview, "# Sikkerhet\n\nARBEIDSVERSJON-TEKST som ikke er godkjent.");
        $neverPublished = $this->page($customer, 'utkast', 'Utkast', null, status: EnterpriseWikiPage::STATUS_DRAFT);
        $this->workingVersion($neverPublished, 'UTKAST-TEKST');
        $this->page($customer, 'arkivert', 'Arkivert', 'ARKIVERT-TEKST', status: EnterpriseWikiPage::STATUS_ARCHIVED);
        $concept = $this->page($customer, 'tilgangsstyring', 'Tilgangsstyring', 'Et begrep.', pageType: EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->page($this->apiCustomer('Annen kunde'), 'fremmed', 'Fremmed side', 'FREMMED-TEKST');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $response = $this->get('/api/v1/wiki/pages', $headers);

        $response->assertOk();
        $response->assertExactJsonStructure(['data' => ['*' => self::ROW_KEYS], 'meta' => ['as_of', 'request_id', 'limit', 'next_cursor']]);
        $this->assertSame([$published->id, $inReview->id, $concept->id], array_column($response->json('data'), 'id'));
        $this->assertSame([
            'id' => $published->id, 'title' => 'Tjenestebeskrivelse', 'slug' => 'tjenestebeskrivelse', 'page_type' => 'article',
            'scope' => 'company', 'version_number' => 1, 'headings' => ['Tjenestebeskrivelse', 'Svartid'],
            'excerpt' => $response->json('data.0.excerpt'),
        ], $response->json('data.0'));
        $response->assertJsonPath('data.1.version_number', 1);

        $body = (string) $response->getContent();

        foreach (['ARBEIDSVERSJON', 'UTKAST', 'ARKIVERT', 'FREMMED', 'Annen kunde', 'status', 'pending_review', 'content_blocks', 'claim', 'owner', 'reviewer', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }

        $this->assertSame([$concept->id], array_column($this->get('/api/v1/wiki/pages?page_type=concept', $headers)->json('data'), 'id'));

        $first = $this->get('/api/v1/wiki/pages?limit=2', $headers);
        $this->assertSame([$published->id, $inReview->id], array_column($first->json('data'), 'id'));
        $next = $this->get('/api/v1/wiki/pages?limit=2&cursor='.$first->json('meta.next_cursor'), $headers);
        $this->assertSame([$concept->id], array_column($next->json('data'), 'id'));
        $this->assertNull($next->json('meta.next_cursor'));
    }

    public function test_one_page_is_its_published_text_with_links_to_published_pages_read_from_that_text(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $target = $this->page($customer, 'tilgangsstyring', 'Tilgangsstyring', 'Et begrep.', pageType: EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $unpublished = $this->page($customer, 'hemmelig-utkast', 'Hemmelig utkast', null, status: EnterpriseWikiPage::STATUS_DRAFT);
        $this->workingVersion($unpublished, 'UTKAST-TEKST');
        $onlyInWorking = $this->page($customer, 'nytt-tema', 'Nytt tema', 'Publisert om et nytt tema.');
        $page = $this->page(
            $customer,
            'sikkerhet',
            'Sikkerhet',
            "# Sikkerhet\n\nSe [[tilgangsstyring|tilgangsstyring]] og [[hemmelig-utkast]]. Igjen [[tilgangsstyring]] og [[sikkerhet]].",
            status: EnterpriseWikiPage::STATUS_PENDING_REVIEW,
        );
        $this->workingVersion($page, "# Sikkerhet\n\nARBEIDSVERSJON med [[nytt-tema]].");
        // The link table follows the working version; the API reads links from the published text.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id, 'from_page_id' => $page->id, 'to_page_id' => $onlyInWorking->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK, 'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
        ]);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->wikiState($customer);

        $response = $this->get("/api/v1/wiki/pages/{$page->id}", $headers);

        $response->assertOk();
        $response->assertExactJsonStructure(['data' => [...self::ROW_KEYS, 'content_markdown', 'links' => ['*' => ['id', 'title', 'slug', 'page_type']]], 'meta' => ['as_of', 'request_id']]);
        $response->assertJsonPath('data.version_number', 1);
        $response->assertJsonPath('data.content_markdown', "# Sikkerhet\n\nSe [[tilgangsstyring|tilgangsstyring]] og [[hemmelig-utkast]]. Igjen [[tilgangsstyring]] og [[sikkerhet]].");
        $response->assertJsonPath('data.links', [['id' => $target->id, 'title' => 'Tilgangsstyring', 'slug' => 'tilgangsstyring', 'page_type' => 'concept']]);
        $this->assertStringNotContainsString('ARBEIDSVERSJON', (string) $response->getContent());
        $this->assertStringNotContainsString('Nytt tema', (string) $response->getContent());
        $this->assertSame($before, $this->wikiState($customer), 'Reading a page writes nothing.');

        // Not published, retired, another customer's, or missing: the same 404.
        $archived = $this->page($customer, 'arkivert', 'Arkivert', 'ARKIVERT', status: EnterpriseWikiPage::STATUS_ARCHIVED);
        $foreign = $this->page($this->apiCustomer('Annen kunde'), 'fremmed', 'Fremmed', 'FREMMED');

        foreach ([$unpublished->id, $archived->id, $foreign->id, 999999999] as $id) {
            $this->get("/api/v1/wiki/pages/{$id}", $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
    }

    public function test_the_wiki_is_read_only_with_the_module_and_wiki_view(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $page = $this->page($customer, 'side', 'Side', 'Tekst.');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $this->grantTo($client, $customer, $identity);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/wiki/pages', $headers)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->get("/api/v1/wiki/pages/{$page->id}", $headers)->assertForbidden();
        $this->get('/api/v1/me', $headers)->assertJsonPath('data.modules.wiki', ['entitled' => true, 'readable' => false, 'business_areas' => null]);

        // A human-only Wiki key in the role reads nothing more: an integration keeps only wiki.view.
        $this->giveRole($identity, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_APPROVE, CustomerPermissionCatalog::WIKI_EDIT]);
        $this->get('/api/v1/wiki/pages', $headers)->assertOk();
        $this->get('/api/v1/me', $headers)->assertJsonPath('data.modules.wiki', ['entitled' => true, 'readable' => true, 'business_areas' => null]);

        // Without the module (no package at all), the module guard refuses before the endpoint.
        $bare = $this->apiCustomer('Uten moduler', withRisk: false);
        $this->page($bare, 'side', 'Side', 'Tekst.');
        $this->grantReader($client, $bare);
        $this->get('/api/v1/wiki/pages', $this->asCustomer($this->accessToken(), $bare))->assertForbidden();
    }

    private function grantReader($client, Customer $customer): void
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::WIKI_VIEW]);
        $this->grantTo($client, $customer, $identity);
    }

    /** A page with version 1, published when $publishedMarkdown is given. */
    private function page(
        Customer $customer,
        string $slug,
        string $title,
        ?string $publishedMarkdown,
        string $status = EnterpriseWikiPage::STATUS_APPROVED,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'scope' => EnterpriseWikiPage::SCOPE_COMPANY,
            'page_type' => $pageType,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        if ($publishedMarkdown !== null) {
            $version = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => $publishedMarkdown,
                'content_blocks_json' => [['block_id' => 'b1', 'source_elements' => [['excerpt' => 'KILDEUTDRAG']]]],
                'generated_by_model' => 'gpt-5',
            ]);
            $page->forceFill(['published_version_id' => $version->id])->save();
        }

        return $page->refresh();
    }

    /** A newer, unpublished working version on top of whatever the page has. */
    private function workingVersion(EnterpriseWikiPage $page, string $markdown): EnterpriseWikiPageVersion
    {
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => (int) EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->max('version_number') + 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    /** @return array<string, mixed> */
    private function wikiState(Customer $customer): array
    {
        $pageIds = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->pluck('id');

        return [
            'pages' => EnterpriseWikiPage::query()->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(fn (EnterpriseWikiPage $page): array => [$page->id, $page->status, $page->published_version_id, $page->updated_at?->toIso8601String()])
                ->all(),
            'versions' => EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', $pageIds)->count(),
            'links' => EnterpriseWikiPageLink::query()->where('customer_id', $customer->id)->count(),
            'owner_approvals' => DB::table('enterprise_wiki_page_version_document_owner_approvals')->count(),
        ];
    }
}
