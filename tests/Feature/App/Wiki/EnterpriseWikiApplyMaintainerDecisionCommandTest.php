<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiApplyMaintainerDecisionCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_option_is_missing(): void
    {
        $this->artisan('wiki:apply-maintainer-decision')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:apply-maintainer-decision', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Successful apply
    // =========================================================================

    public function test_command_applies_successfully_and_exits_zero(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $this->artisan('wiki:apply-maintainer-decision', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_outputs_created_count_on_success(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Created: 2', $output);
        $this->assertStringContainsString('Updated: 0', $output);
    }

    public function test_command_outputs_page_titles_in_created_section(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Test Artikkel', $output);
        $this->assertStringContainsString('Sammendrag: Test Artikkel', $output);
        $this->assertStringContainsString('Created pages:', $output);
    }

    public function test_command_outputs_applied_confirmation(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        $this->assertStringContainsString('marked as applied', Artisan::output());
    }

    public function test_command_outputs_updated_section_when_update_action_present(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Delt Konsept');

        $decision = $this->baseDecision([
            'concept_pages' => [
                [
                    'action'        => 'update',
                    'page_id'       => $existing->id,
                    'title'         => 'Delt Konsept',
                    'proposed_slug' => 'delt-konsept-xy1z',
                    'reason'        => 'Updating.',
                ],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Updated: 1', $output);
        $this->assertStringContainsString('Updated pages:', $output);
        $this->assertStringContainsString('Delt Konsept', $output);
    }

    public function test_command_marks_run_as_applied_in_database(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
    }

    public function test_command_creates_wiki_pages_in_database(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $pagesBefore = EnterpriseWikiPage::query()->count();

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        $this->assertSame($pagesBefore + 2, EnterpriseWikiPage::query()->count());
    }

    // =========================================================================
    // Guard: already applied
    // =========================================================================

    public function test_command_stops_cleanly_when_already_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        // First apply
        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        // Second apply — service guard should fire
        $this->artisan('wiki:apply-maintainer-decision', ['--run-id' => $run->id])
            ->expectsOutputToContain('already been applied')
            ->assertExitCode(1);
    }

    public function test_command_stops_cleanly_when_already_applied_creates_no_extra_pages(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);
        $pagesAfterFirst = EnterpriseWikiPage::query()->count();

        // Second attempt should be blocked
        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        $this->assertSame($pagesAfterFirst, EnterpriseWikiPage::query()->count());
    }

    // =========================================================================
    // Guard: wrong run state
    // =========================================================================

    public function test_command_fails_when_run_status_not_decision_only(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'         => Str::uuid()->toString(),
            'customer_id'  => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => $document->id,
            'status'       => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        $this->artisan('wiki:apply-maintainer-decision', ['--run-id' => $run->id])
            ->expectsOutputToContain('decision_only')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_maintainer_decision_json_is_null(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'                       => Str::uuid()->toString(),
            'customer_id'                => $customer->id,
            'trigger_type'               => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                  => $document->id,
            'status'                     => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
        ]);

        $this->artisan('wiki:apply-maintainer-decision', ['--run-id' => $run->id])
            ->expectsOutputToContain('maintainer_decision_json')
            ->assertExitCode(1);
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_command_does_not_create_page_versions(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_command_does_not_create_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:apply-maintainer-decision', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
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

    private function createDecisionOnlyRun(Customer $customer, array $decision): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json'         => $decision,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function baseDecision(array $overrides = []): array
    {
        return array_merge([
            'source_article' => [
                'action'        => 'create',
                'title'         => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason'        => 'New article.',
            ],
            'source_summary' => [
                'action'        => 'create',
                'title'         => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason'        => 'Companion summary.',
            ],
            'concept_pages'    => [],
            'entity_pages'     => [],
            'no_action_reason' => null,
            'warnings'         => [],
        ], $overrides);
    }
}
