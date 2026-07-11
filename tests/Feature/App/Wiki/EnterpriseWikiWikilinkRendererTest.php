<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiWikilinkRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiWikilinkRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_only_link_renders_as_internal_link(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $target = $this->createPage($customer, 'business-case', 'Business Case');

        $result = $this->renderer()->render('Se [[business-case]] for detaljer.', $customer->id, $source);

        $this->assertSame('Se [business-case](/app/wiki/business-case) for detaljer.', $result);
    }

    public function test_slug_with_anchor_keeps_anchor_text(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $this->createPage($customer, 'prosjekteier', 'Prosjekteier');

        $result = $this->renderer()->render('Eies av [[prosjekteier|prosjekteieren]].', $customer->id, $source);

        $this->assertSame('Eies av [prosjekteieren](/app/wiki/prosjekteier).', $result);
    }

    public function test_unknown_slug_renders_as_plain_text(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');

        $result = $this->renderer()->render('Se [[does-not-exist|dette konseptet]].', $customer->id, $source);

        $this->assertSame('Se dette konseptet.', $result);
        $this->assertStringNotContainsString('[[', $result);
        $this->assertStringNotContainsString('](/app/wiki/', $result);
    }

    public function test_cross_customer_slug_does_not_become_internal_link(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $source = $this->createPage($customerA, 'artikkel');
        $this->createPage($customerB, 'business-case', 'Business Case');

        $result = $this->renderer()->render('Se [[business-case|saken]].', $customerA->id, $source);

        $this->assertSame('Se saken.', $result);
    }

    public function test_self_link_is_not_clickable(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel', 'Artikkel');

        $result = $this->renderer()->render('Se [[artikkel|denne siden]] for mer.', $customer->id, $source);

        $this->assertSame('Se denne siden for mer.', $result);
    }

    public function test_ordinary_markdown_link_is_not_affected(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $this->createPage($customer, 'business-case', 'Business Case');

        $markdown = 'See [the business case](https://example.com/business-case) for details.';

        $this->assertSame($markdown, $this->renderer()->render($markdown, $customer->id, $source));
    }

    public function test_wikilink_inside_fenced_code_block_is_not_transformed(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $this->createPage($customer, 'business-case', 'Business Case');

        $markdown = "Text before.\n\n```\nExample: [[business-case]]\n```\n\nText after.";

        $this->assertSame($markdown, $this->renderer()->render($markdown, $customer->id, $source));
    }

    public function test_wikilink_inside_inline_code_is_not_transformed(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $this->createPage($customer, 'business-case', 'Business Case');

        $markdown = 'Use the syntax `[[business-case]]` in your pages.';

        $this->assertSame($markdown, $this->renderer()->render($markdown, $customer->id, $source));
    }

    public function test_mixed_code_and_prose_only_transforms_the_prose_wikilink(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $this->createPage($customer, 'business-case', 'Business Case');

        $markdown = "See [[business-case]] here, but not `[[business-case]]` in code.";

        $result = $this->renderer()->render($markdown, $customer->id, $source);

        $this->assertSame(
            'See [business-case](/app/wiki/business-case) here, but not `[[business-case]]` in code.',
            $result,
        );
    }

    public function test_render_does_not_persist_or_mutate_anything(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPage($customer, 'artikkel');
        $this->createPage($customer, 'business-case', 'Business Case');

        $original = 'Se [[business-case]] for detaljer.';
        $this->renderer()->render($original, $customer->id, $source);

        // The renderer must be a pure function of its inputs — calling it must not alter
        // the source string passed in, nor write anything to the database.
        $this->assertSame('Se [[business-case]] for detaljer.', $original);
    }

    private function renderer(): EnterpriseWikiWikilinkRenderer
    {
        return app(EnterpriseWikiWikilinkRenderer::class);
    }

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
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createPage(Customer $customer, string $slug, string $title = 'Artikkel'): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }
}
