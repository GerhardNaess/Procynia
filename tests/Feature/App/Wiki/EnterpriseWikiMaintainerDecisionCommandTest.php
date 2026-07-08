<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_command_blocks_when_ai_flag_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->artisan('wiki:maintainer-decision', ['--customer' => 1, '--document-id' => 1])
            ->expectsOutputToContain('AI is not enabled')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_customer_option_is_missing(): void
    {
        $this->artisan('wiki:maintainer-decision', ['--document-id' => 1])
            ->expectsOutputToContain('--customer')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_document_id_option_is_missing(): void
    {
        $this->artisan('wiki:maintainer-decision', ['--customer' => 1])
            ->expectsOutputToContain('--document-id')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_customer_not_found(): void
    {
        $this->artisan('wiki:maintainer-decision', ['--customer' => 99999, '--document-id' => 1])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_service_throws_invalid_argument(): void
    {
        $customer = $this->createCustomer();

        $mock = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $mock->shouldReceive('runForDocument')
            ->once()
            ->andThrow(new \InvalidArgumentException('Document [99] not found for customer [1].'));

        $this->artisan('wiki:maintainer-decision', ['--customer' => $customer->id, '--document-id' => 99])
            ->expectsOutputToContain('Document [99] not found')
            ->assertExitCode(1);
    }

    public function test_command_prints_decision_json_on_success(): void
    {
        $customer = $this->createCustomer();

        /** @var EnterpriseWikiMaintainerDecisionService&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $mock->shouldReceive('runForDocument')->once()->andReturn($this->validDecision());

        $exitCode = Artisan::call('wiki:maintainer-decision', [
            '--customer'     => $customer->id,
            '--document-id'  => 1,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[DRY-RUN]', $output);
        $this->assertStringContainsString('source_article', $output);
        $this->assertStringContainsString('proposed_slug', $output);
        $this->assertStringContainsString('test-artikkel-ab1c2d', $output);
    }

    public function test_command_does_not_write_new_pages(): void
    {
        $customer = $this->createCustomer();

        /** @var EnterpriseWikiMaintainerDecisionService&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $mock->shouldReceive('runForDocument')->once()->andReturn($this->validDecision());

        $pagesBefore    = EnterpriseWikiPage::query()->count();
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        $this->artisan('wiki:maintainer-decision', ['--customer' => $customer->id, '--document-id' => 1])
            ->assertExitCode(0);

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_command_does_not_write_new_documents(): void
    {
        $customer = $this->createCustomer();

        /** @var EnterpriseWikiMaintainerDecisionService&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $mock->shouldReceive('runForDocument')->once()->andReturn($this->validDecision());

        $docsBefore = EnterpriseWikiDocument::query()->count();

        $this->artisan('wiki:maintainer-decision', ['--customer' => $customer->id, '--document-id' => 1])
            ->assertExitCode(0);

        $this->assertSame($docsBefore, EnterpriseWikiDocument::query()->count());
    }

    public function test_command_passes_customer_language_to_service(): void
    {
        $customer = $this->createCustomer();
        $capturedLang = null;

        /** @var EnterpriseWikiMaintainerDecisionService&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $mock->shouldReceive('runForDocument')
            ->once()
            ->andReturnUsing(function (int $_cId, int $_dId, string $lang) use (&$capturedLang): array {
                $capturedLang = $lang;
                return $this->validDecision();
            });

        $this->artisan('wiki:maintainer-decision', ['--customer' => $customer->id, '--document-id' => 1])
            ->assertExitCode(0);

        $this->assertSame('no', $capturedLang);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validDecision(): array
    {
        return [
            'source_article' => [
                'action'        => 'create',
                'title'         => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason'        => 'New.',
            ],
            'source_summary' => [
                'action'        => 'create',
                'title'         => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason'        => 'Companion.',
            ],
            'concept_pages'    => [],
            'entity_pages'     => [],
            'no_action_reason' => null,
            'warnings'         => [],
        ];
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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }
}
