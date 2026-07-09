<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiInspectAppliedDecisionCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:inspect-applied-decision')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:inspect-applied-decision', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Not-applied guard
    // =========================================================================

    public function test_command_warns_and_exits_zero_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer);

        // run is pending (not applied yet)
        $this->artisan('wiki:inspect-applied-decision', ['--run-id' => $run->id])
            ->expectsOutputToContain('not applied yet')
            ->assertExitCode(0);
    }

    public function test_command_output_includes_current_status_when_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);

        $this->assertStringContainsString('pending', Artisan::output());
    }

    // =========================================================================
    // Successful inspection
    // =========================================================================

    public function test_command_exits_zero_for_applied_run(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer);

        $this->artisan('wiki:inspect-applied-decision', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_outputs_page_titles_and_slugs(): void
    {
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createAppliedRun($customer);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);
        $output = Artisan::output();

        $this->assertStringContainsString($article->title, $output);
        $this->assertStringContainsString($article->slug, $output);
        $this->assertStringContainsString($summary->title, $output);
    }

    public function test_command_outputs_created_action_for_created_pages(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);

        $this->assertStringContainsString('created', Artisan::output());
    }

    public function test_command_outputs_updated_action_for_updated_pages(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRunApplied($customer);
        $existing = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Delt Konsept');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $existing->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
        ]);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);

        $this->assertStringContainsString('updated', Artisan::output());
    }

    public function test_command_outputs_page_types_in_summary(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Article:', $output);
        $this->assertStringContainsString('Summary:', $output);
        $this->assertStringContainsString('Total:', $output);
    }

    public function test_command_summary_counts_are_correct(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer); // creates 1 article + 1 summary

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Article:  1', $output);
        $this->assertStringContainsString('Summary:  1', $output);
        $this->assertStringContainsString('Total:    2', $output);
    }

    public function test_command_outputs_run_id_in_header(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);

        $this->assertStringContainsString((string) $run->id, Artisan::output());
    }

    // =========================================================================
    // Customer mismatch guard (read-only integrity check)
    // =========================================================================

    public function test_command_fails_on_customer_mismatch_in_pivot_pages(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');

        $run = $this->createDecisionOnlyRunApplied($customer);
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Fremmed Artikkel');

        // Manually insert a pivot row pointing to a page from a different customer
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $foreignPage->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $this->artisan('wiki:inspect-applied-decision', ['--run-id' => $run->id])
            ->expectsOutputToContain('Customer mismatch')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Read-only guarantee: no writes to the database
    // =========================================================================

    public function test_command_does_not_write_any_rows(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer);

        $pagesBefore    = EnterpriseWikiPage::query()->count();
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();
        $claimsBefore   = EnterpriseWikiClaim::query()->count();
        $pivotBefore    = EnterpriseWikiIngestRunPage::query()->count();
        $runsBefore     = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($pivotBefore, EnterpriseWikiIngestRunPage::query()->count());
        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRun($customer);

        Artisan::call('wiki:inspect-applied-decision', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'test.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /** Run with status=decision_only, maintainer_decision_status=pending (not yet applied). */
    private function createDecisionOnlyRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json'         => ['source_article' => [], 'source_summary' => [], 'concept_pages' => [], 'entity_pages' => [], 'no_action_reason' => null, 'warnings' => []],
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /** Run already marked as applied, but with no pivot rows yet. */
    private function createDecisionOnlyRunApplied(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * Applied run with one article and one summary page in the pivot.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createAppliedRun(Customer $customer): array
    {
        $run     = $this->createDecisionOnlyRunApplied($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $article->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $summary->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        return [$run, $article, $summary];
    }
}
