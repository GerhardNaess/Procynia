<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\KnowledgeMetadataTerm;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeedDefaultMetadataTermsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_creates_default_service_product_tags(): void
    {
        $customer = $this->makeCustomer('Alpha');

        $this->artisan('knowledge:seed-default-metadata-terms')
            ->expectsOutputToContain('[KNOWLEDGE][METADATA_TERMS]')
            ->assertSuccessful();

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->count());
    }

    public function test_command_creates_default_theme_tags(): void
    {
        $customer = $this->makeCustomer('Beta');

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_THEME_TAG)
            ->count());
    }

    public function test_all_seeded_terms_have_approved_true(): void
    {
        $customer = $this->makeCustomer('Gamma');

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $unapproved = KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('approved', false)
            ->count();

        $this->assertSame(0, $unapproved);
    }

    public function test_command_is_idempotent(): void
    {
        $customer = $this->makeCustomer('Delta');

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();
        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->count());

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_THEME_TAG)
            ->count());
    }

    public function test_existing_terms_are_not_overwritten(): void
    {
        $customer = $this->makeCustomer('Epsilon');

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG,
            'canonical_name' => 'Service Desk',
            'description' => 'Kundens egne beskrivelse',
            'approved' => true,
        ]);

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $term = KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->where('canonical_name', 'Service Desk')
            ->sole();

        $this->assertSame('Kundens egne beskrivelse', $term->description);
    }

    public function test_terms_are_customer_scoped(): void
    {
        $alpha = $this->makeCustomer('Zeta');
        $beta = $this->makeCustomer('Eta');

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $alpha->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->count());

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $beta->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->count());

        $this->assertSame(0, KnowledgeMetadataTerm::query()
            ->where('customer_id', $alpha->id)
            ->where('canonical_name', 'DOES_NOT_BELONG_HERE')
            ->count());
    }

    public function test_customer_id_option_scopes_to_single_customer(): void
    {
        $target = $this->makeCustomer('Theta');
        $other = $this->makeCustomer('Iota');

        $this->artisan('knowledge:seed-default-metadata-terms', ['--customer-id' => $target->id])
            ->assertSuccessful();

        $this->assertSame(6, KnowledgeMetadataTerm::query()
            ->where('customer_id', $target->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->count());

        $this->assertSame(0, KnowledgeMetadataTerm::query()
            ->where('customer_id', $other->id)
            ->count());
    }

    public function test_inactive_customers_are_skipped(): void
    {
        $inactive = $this->makeCustomer('Kappa', active: false);

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $this->assertSame(0, KnowledgeMetadataTerm::query()
            ->where('customer_id', $inactive->id)
            ->count());
    }

    public function test_case_insensitive_match_prevents_duplicate_canonical_names(): void
    {
        $customer = $this->makeCustomer('Lambda');

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG,
            'canonical_name' => 'SERVICE DESK',
            'approved' => true,
        ]);

        $this->artisan('knowledge:seed-default-metadata-terms')->assertSuccessful();

        $count = KnowledgeMetadataTerm::query()
            ->where('customer_id', $customer->id)
            ->where('type', KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG)
            ->whereRaw('LOWER(canonical_name) = ?', ['service desk'])
            ->count();

        $this->assertSame(1, $count);
    }

    private function makeCustomer(string $tag, bool $active = true): Customer
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
            'name' => "Metadata Term Test {$tag}",
            'slug' => 'metadata-term-test-'.strtolower($tag).'-'.Str::lower(Str::random(4)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => $active,
        ]);
    }
}
