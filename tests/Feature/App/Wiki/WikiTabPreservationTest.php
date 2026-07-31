<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers "Rett fanebevaring i Enterprise Wiki" — the mechanism itself
 * (App\Http\Controllers\Concerns\RedirectsToWikiIndexTab), rather than re-proving every individual
 * controller action's happy/error path (those are covered per-action in WikiControllerTest and
 * WikiSourceControllerTest, all of which now send/expect the tab explicitly too). Focuses on what's
 * new: missing/invalid tab fallback, filter preservation, explicit non-default tab honored, and
 * that a manipulated tab value can never become an open redirect.
 */
class WikiTabPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_missing_tab_context_falls_back_to_default_pages_tab(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING);

        // ingest() with document_status=pending fails validation and redirects with an error —
        // sent with NO "tab" value at all, exactly as an old/unaware caller would.
        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'pages']));
        $response->assertSessionHas('error');
    }

    public function test_unknown_tab_value_falls_back_to_default_pages_tab(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createIngestRun($customer, $this->createDocument($customer), EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel", ['tab' => 'not-a-real-tab']);

        // Not cancellable (terminal) -> error branch, but the tab fallback rule applies regardless
        // of which branch is hit.
        $response->assertRedirect(route('app.wiki.index', ['tab' => 'pages']));
        $response->assertSessionHas('error');
    }

    public function test_manipulated_tab_value_cannot_produce_an_open_redirect(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING);

        $payload = 'https://evil.example.com/phishing';
        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest", ['tab' => $payload]);

        $target = $response->headers->get('Location');

        $this->assertNotNull($target);
        $this->assertStringStartsWith(config('app.url'), $target);
        $this->assertStringNotContainsString('evil.example.com', $target);
        $response->assertRedirect(route('app.wiki.index', ['tab' => 'pages']));
    }

    public function test_explicit_pages_tab_context_is_honored_not_just_used_as_fallback(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);

        $response = $this->actingAs($user)->patch("/app/wiki/sources/{$document->id}/owner", [
            'owner_user_id' => null,
            'tab' => 'pages',
        ]);

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'pages']));
    }

    public function test_runs_tab_active_filter_survives_a_cancel_action(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_RUNNING);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel", [
            'tab' => 'runs',
            'run_status' => EnterpriseWikiIngestRun::STATUS_RUNNING,
        ]);

        $response->assertRedirect(route('app.wiki.index', [
            'tab' => 'runs',
            'run_status' => EnterpriseWikiIngestRun::STATUS_RUNNING,
        ]));
        $response->assertSessionHas('success');
    }

    public function test_sources_tab_active_filter_survives_an_ingest_error(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest", [
            'tab' => 'sources',
            'src_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
        ]);

        $response->assertRedirect(route('app.wiki.index', [
            'tab' => 'sources',
            'src_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
        ]));
        $response->assertSessionHas('error');
    }

    public function test_unsupported_filter_key_for_the_active_tab_is_not_forwarded(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createIngestRun($customer, $this->createDocument($customer), EnterpriseWikiIngestRun::STATUS_RUNNING);

        // src_q/src_status are Kildedokumenter filter keys — irrelevant on the Kjøringer tab and
        // must not leak into the redirect even if a manipulated request includes them.
        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel", [
            'tab' => 'runs',
            'src_q' => 'should-not-appear',
        ]);

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'runs']));
    }

    public function test_cancel_blocking_runs_for_deletion_returns_to_sources_tab(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_RUNNING);

        $response = $this->actingAs($user)->patch("/app/wiki/sources/{$document->id}/cancel-blocking-runs", [
            'tab' => 'sources',
        ]);

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));
        $response->assertSessionHas('success');
    }

    public function test_upload_from_sources_tab_returns_to_sources_tab_not_default(): void
    {
        Storage::fake('local');
        $this->mock(DocumentTextExtractor::class, function ($mock): void {
            $mock->shouldReceive('extractText')->andReturn('Innhold.');
        });

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tab-preservation.pdf', 32, 'application/pdf'),
            'tab' => 'sources',
        ]);

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));
    }

    private function createCustomer(string $name = 'Fanebevaring Test AS'): Customer
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

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Fanebevaring User',
            'email' => Str::lower(Str::random(8)).'@tab-preservation.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, string $status = EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'fanebevaring-test.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => $status,
        ]);
    }

    private function createIngestRun(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', "enterprise_wiki_document:{$document->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
        ]);
    }
}
