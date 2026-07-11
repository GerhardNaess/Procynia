<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8I-3: rendered_markdown and canonical backlinks on the Wiki detail page.
 */
class EnterpriseWikiBacklinksControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_rendered_markdown_with_clickable_internal_link(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $target = $this->createPage($customer, 'business-case', 'Business Case');
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Artikkel', 'Se [[business-case|forretningscaset]] for detaljer.');

        $response = $this->actingAs($user)->get('/app/wiki/'.$source->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($target): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return $rendered !== null
                && str_contains($rendered, "[forretningscaset](/app/wiki/{$target->slug})")
                && ! str_contains($rendered, '[[');
        });
    }

    public function test_show_keeps_canonical_content_markdown_unchanged(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $this->createPage($customer, 'business-case', 'Business Case');
        $raw = 'Se [[business-case]] for detaljer.';
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Artikkel', $raw);

        $response = $this->actingAs($user)->get('/app/wiki/'.$source->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($raw): bool {
            return data_get($inertia, 'props.current_version.content_markdown') === $raw;
        });

        $this->assertSame($raw, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $source->id)->first()->content_markdown);
    }

    public function test_show_returns_backlinks_for_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $target = $this->createPage($customer, 'business-case', 'Business Case');
        $this->createWikilink($customer, $this->createPageWithVersion($customer, 'artikkel', 'Kildeartikkel', 'Se [[business-case]].'), $target);

        $response = $this->actingAs($user)->get('/app/wiki/'.$target->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($target): bool {
            $backlinks = data_get($inertia, 'props.backlinks', []);

            return count($backlinks) === 1
                && $backlinks[0]['title'] === 'Kildeartikkel'
                && $backlinks[0]['page_type'] === EnterpriseWikiPage::PAGE_TYPE_ARTICLE;
        });
    }

    public function test_backlink_exposes_source_title_slug_and_type(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $target = $this->createPage($customer, 'business-case', 'Business Case');
        $source = $this->createPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createWikilink($customer, $source, $target);

        $response = $this->actingAs($user)->get('/app/wiki/'.$target->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($source): bool {
            $backlink = data_get($inertia, 'props.backlinks.0');

            return $backlink['title'] === $source->title
                && $backlink['slug'] === $source->slug
                && $backlink['page_type'] === EnterpriseWikiPage::PAGE_TYPE_CONCEPT;
        });
    }

    public function test_only_wikilink_type_relations_are_shown_as_backlinks(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $target = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $summary = $this->createPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        // A combinatoric structural relation — must NOT be treated as a backlink.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $summary->id,
            'to_page_id' => $target->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$target->slug);

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.backlinks') === []);
    }

    public function test_backlinks_are_customer_scoped(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $userB = $this->createUser($customerB);

        $targetA = $this->createPage($customerA, 'business-case', 'Business Case');
        $sourceA = $this->createPage($customerA, 'artikkel', 'Artikkel');
        $this->createWikilink($customerA, $sourceA, $targetA);

        $targetB = $this->createPage($customerB, 'business-case', 'Business Case');

        $response = $this->actingAs($userB)->get('/app/wiki/'.$targetB->slug);

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.backlinks') === []);
    }

    public function test_empty_backlink_list_is_handled_without_error(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'isolert', 'Isolert side');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.backlinks') === []);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Testkunde AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createPageWithVersion(
        Customer $customer,
        string $slug,
        string $title,
        string $markdown,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $slug, $title, $pageType);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createWikilink(Customer $customer, EnterpriseWikiPage $from, EnterpriseWikiPage $to): EnterpriseWikiPageLink
    {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }
}
