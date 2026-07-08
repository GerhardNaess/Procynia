<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiIndexContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnterpriseWikiIndexContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseWikiIndexContextService::class);
    }

    public function test_returns_only_pages_for_given_customer(): void
    {
        $customer = $this->createCustomer('Kunde A');
        $other    = $this->createCustomer('Kunde B');

        $this->createPage($customer, ['title' => 'Side for Kunde A']);
        $this->createPage($other, ['title' => 'Side for Kunde B']);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertCount(1, $result);
        $this->assertSame('Side for Kunde A', $result[0]['title']);
    }

    public function test_empty_result_when_customer_has_no_pages(): void
    {
        $customer = $this->createCustomer();

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertSame([], $result);
    }

    public function test_all_page_types_are_representable_in_index(): void
    {
        $customer = $this->createCustomer();

        foreach (EnterpriseWikiPage::PAGE_TYPES as $type) {
            $this->createPage($customer, ['page_type' => $type, 'title' => "Side {$type}"]);
        }

        $result = $this->service->buildForCustomer($customer->id);
        $types = array_column($result, 'page_type');

        foreach (EnterpriseWikiPage::PAGE_TYPES as $expectedType) {
            $this->assertContains($expectedType, $types, "page_type '{$expectedType}' must appear in index");
        }
    }

    public function test_result_entry_contains_required_keys(): void
    {
        $customer = $this->createCustomer();
        $this->createPage($customer);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertCount(1, $result);
        $entry = $result[0];
        foreach (['id', 'title', 'slug', 'page_type', 'status', 'excerpt', 'open_lint_count', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $entry, "Entry must have key '{$key}'");
        }
    }

    public function test_excerpt_is_derived_from_current_version_content_markdown(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $this->createCurrentVersion($page, "## Innledning\n\nVi leverer sertifisert tjeneste til offentlig sektor.");

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertCount(1, $result);
        $this->assertNotNull($result[0]['excerpt']);
        $this->assertStringContainsString('Vi leverer sertifisert tjeneste', $result[0]['excerpt']);
        $this->assertStringNotContainsString('##', $result[0]['excerpt']);
    }

    public function test_excerpt_is_stripped_of_markdown_formatting(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $this->createCurrentVersion($page, "## Tittel\n\n**Fet tekst** og *kursiv* og `kode`.");

        $result = $this->service->buildForCustomer($customer->id);

        $excerpt = $result[0]['excerpt'];
        $this->assertNotNull($excerpt);
        $this->assertStringContainsString('Fet tekst', $excerpt);
        $this->assertStringNotContainsString('**', $excerpt);
        $this->assertStringNotContainsString('`', $excerpt);
        $this->assertStringNotContainsString('##', $excerpt);
    }

    public function test_excerpt_is_truncated_to_max_length(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $longText = str_repeat('Lang setning med mye innhold. ', 20);
        $this->createCurrentVersion($page, $longText);

        $result = $this->service->buildForCustomer($customer->id);

        $excerpt = $result[0]['excerpt'];
        $this->assertNotNull($excerpt);
        $this->assertLessThanOrEqual(201 + mb_strlen('…'), mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function test_page_without_current_version_returns_null_excerpt(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'content_markdown' => 'Ikke-current innhold.',
        ]);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['excerpt']);
    }

    public function test_page_with_no_versions_returns_null_excerpt(): void
    {
        $customer = $this->createCustomer();
        $this->createPage($customer);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertNull($result[0]['excerpt']);
    }

    public function test_open_lint_count_reflects_open_findings_for_page(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::STATUS_OPEN);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::STATUS_OPEN);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::STATUS_RESOLVED);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertSame(2, $result[0]['open_lint_count']);
    }

    public function test_open_lint_count_is_scoped_to_page_not_other_pages(): void
    {
        $customer = $this->createCustomer();
        $pageA = $this->createPage($customer, ['title' => 'Side A']);
        $pageB = $this->createPage($customer, ['title' => 'Side B']);

        $this->createLintFinding($customer, $pageA, EnterpriseWikiLintFinding::STATUS_OPEN);
        $this->createLintFinding($customer, $pageA, EnterpriseWikiLintFinding::STATUS_OPEN);
        $this->createLintFinding($customer, $pageB, EnterpriseWikiLintFinding::STATUS_OPEN);

        $result = $this->service->buildForCustomer($customer->id);
        $byTitle = collect($result)->keyBy('title');

        $this->assertSame(2, $byTitle['Side A']['open_lint_count']);
        $this->assertSame(1, $byTitle['Side B']['open_lint_count']);
    }

    public function test_open_lint_count_is_scoped_to_customer_not_other_customers(): void
    {
        $customer = $this->createCustomer('Kunde A');
        $other    = $this->createCustomer('Kunde B');

        $page = $this->createPage($customer);
        $this->createLintFinding($other, $page, EnterpriseWikiLintFinding::STATUS_OPEN);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertSame(0, $result[0]['open_lint_count']);
    }

    public function test_page_with_no_lint_findings_returns_zero_count(): void
    {
        $customer = $this->createCustomer();
        $this->createPage($customer);

        $result = $this->service->buildForCustomer($customer->id);

        $this->assertSame(0, $result[0]['open_lint_count']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Test AS'): Customer
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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createPage(Customer $customer, array $overrides = []): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create(array_merge([
            'customer_id'  => $customer->id,
            'slug'         => 'test-page-' . Str::lower(Str::random(6)),
            'title'        => 'Test Side',
            'page_type'    => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status'       => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ], $overrides));
    }

    private function createCurrentVersion(EnterpriseWikiPage $page, string $markdown): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => $markdown,
        ]);
    }

    private function createLintFinding(Customer $customer, EnterpriseWikiPage $page, string $status): EnterpriseWikiLintFinding
    {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id'             => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'code'        => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity'    => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message'     => 'Test lint finding.',
            'status'      => $status,
            'detected_at' => now(),
        ]);
    }
}
