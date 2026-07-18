<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiClaimIntegrityRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_classifications_without_changing_claims(): void
    {
        $customer = $this->createCustomer();
        [, , $claim] = $this->createPageVersionAndClaim($customer, 'Kildebasert tekst.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED,
        ]);

        $this->artisan('wiki:repair-claim-integrity', ['--customer-id' => $customer->id])
            ->expectsOutputToContain('Dry-run only')
            ->expectsOutputToContain('Unsupported content: 1')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED, $claim->fresh()->content_origin);
    }

    public function test_apply_classifies_source_best_practice_and_internal_error_claims(): void
    {
        $customer = $this->createCustomer();
        [$page, $version, $sourceClaim] = $this->createPageVersionAndClaim($customer, 'Kildebasert tekst.');
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $sourceClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 123,
            'source_label' => 'kilde.docx',
            'excerpt' => 'Kildebasert tekst.',
        ]);

        [, , $bestPracticeClaim] = $this->createPageVersionAndClaim($customer, 'Virksomheten bør følge etablert beste praksis.');
        [, , $unsupportedClaim] = $this->createPageVersionAndClaim($customer, 'Dette er en faktapåstand uten kilde.');
        [, , $internalErrorClaim] = $this->createPageVersionAndClaim($customer, 'Finnes ikke i siden.');
        $internalErrorClaim->update([
            'enterprise_wiki_page_version_id' => $version->id,
            'page_excerpt' => 'Finnes ikke i siden.',
        ]);

        $this->artisan('wiki:repair-claim-integrity', [
            '--customer-id' => $customer->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $sourceClaim->fresh()->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $bestPracticeClaim->fresh()->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $unsupportedClaim->fresh()->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, $internalErrorClaim->fresh()->content_origin);
        $this->assertFalse($bestPracticeClaim->fresh()->needsSourceWarning());
        $this->assertFalse($unsupportedClaim->fresh()->needsSourceWarning());
        $this->assertFalse($internalErrorClaim->fresh()->needsSourceWarning());

        $this->assertSame($page->customer_id, $customer->id);
    }

    public function test_apply_backfills_stable_blocks_for_legacy_page_versions(): void
    {
        $customer = $this->createCustomer();
        [, $version, $claim] = $this->createPageVersionAndClaim($customer, 'Legacy kildebasert tekst.');
        $version->update(['content_blocks_json' => null]);
        $claim->update([
            'content_block_key' => null,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED,
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 123,
            'source_label' => 'kilde.docx',
            'excerpt' => 'Legacy kildebasert tekst.',
        ]);

        $this->artisan('wiki:repair-claim-integrity', [
            '--customer-id' => $customer->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
        $this->assertSame('block-0002', $claim->fresh()->content_block_key);
        $this->assertNotEmpty($version->fresh()->content_blocks_json);
        $this->assertSame('Legacy kildebasert tekst.', $version->fresh()->content_blocks_json[1]['markdown']);
    }

    /**
     * @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion, 2: EnterpriseWikiClaim}
     */
    private function createPageVersionAndClaim(Customer $customer, string $text, array $claimOverrides = []): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'repair-page-'.Str::lower(Str::random(8)),
            'title' => 'Repair Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Repair Page\n\n{$text}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-0001',
                    'position' => 0,
                    'markdown' => $text,
                    'source_id' => 123,
                    'source_label' => 'kilde.docx',
                    'source_hash' => str_pad('a', 64, '0'),
                    'source_element_key' => 'source-1',
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                    'source_row_key' => null,
                    'source_excerpt' => $text,
                    'page_reference' => 'Avsnitt 1',
                ],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        $claim = EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'page_excerpt' => $text,
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $claimOverrides));

        return [$page, $version, $claim];
    }

    private function createCustomer(string $name = 'Claim Repair Test AS'): Customer
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
}
