<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki run-11: the Kjøringer tab kept showing a run's OLD snapshot ("Vedlikeholderbeslutning",
 * 0 pages, 0 findings) long after the backend had moved it to
 * awaiting_document_owner_approval with 3 generated pages. This test drives the exact request
 * the 5-second poll makes — an Inertia partial reload of only the `runs` prop against the same
 * ?tab=runs URL — and asserts the response carries the run's CURRENT state, so a stale row can
 * never again be blamed on the backend without evidence.
 */
class WikiRunsTabPollingRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_partial_reload_returns_the_runs_prop_with_the_current_status(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION);

        // First paint: the row the user originally saw — maintainer decision, nothing generated.
        $before = $this->fetchRunRow($user, $run->id);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION, $before['status']);
        $this->assertSame(0, $before['pages_count']);

        // Backend progresses the run exactly as the real pipeline does.
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'error_message' => 'Avventer godkjenning fra Dokumenteier',
        ]);
        foreach (['article', 'summary', 'concept'] as $index => $pageType) {
            $this->attachGeneratedPage($customer, $run, $pageType, "Run 11 {$pageType} {$index}");
        }

        // The poll: Inertia partial reload of ONLY the runs prop, same ?tab=runs URL.
        $after = $this->fetchRunRow($user, $run->id, partial: true);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $after['status']);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION, $after['status']);
        $this->assertSame(3, $after['pages_count']);
        $this->assertSame('applied', $after['maintainer_decision_status']);
    }

    /**
     * A partial reload asking only for `runs` must actually return that prop. If the request's
     * tab context were lost, WikiController::index()'s match would build the Pages tab instead
     * and omit `runs` entirely — Inertia would then keep the previous (stale) value client-side,
     * which is exactly the failure mode this guards.
     */
    public function test_partial_reload_of_runs_actually_contains_the_runs_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $this->inertiaVersion(),
                'X-Inertia-Partial-Data' => 'runs',
                'X-Inertia-Partial-Component' => 'App/Wiki/Index',
            ])
            ->get('/app/wiki?tab=runs');

        $response->assertOk();
        $props = $response->json('props');

        $this->assertArrayHasKey('runs', $props, 'the poll must receive a fresh runs prop');
        $this->assertNotEmpty($props['runs']);
    }

    /**
     * The asset version Inertia would reject a stale partial reload against (HTTP 409) — the poll
     * always sends the version it was served, so the test must too.
     */
    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRunRow(User $user, int $runId, bool $partial = false): array
    {
        $headers = $partial
            ? [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $this->inertiaVersion(),
                'X-Inertia-Partial-Data' => 'runs',
                'X-Inertia-Partial-Component' => 'App/Wiki/Index',
            ]
            : [];

        $response = $this->actingAs($user)->withHeaders($headers)->get('/app/wiki?tab=runs');
        $response->assertOk();

        $runs = $partial
            ? $response->json('props.runs')
            : data_get($response->viewData('page'), 'props.runs');

        $row = collect($runs)->firstWhere('id', $runId);

        $this->assertNotNull($row, "run {$runId} must be present in the runs payload");

        return $row;
    }

    private function createCustomer(string $name = 'Runs Tab Polling AS'): Customer
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
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@runs-tab-polling.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function attachGeneratedPage(Customer $customer, EnterpriseWikiIngestRun $run, string $pageType, string $title): void
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nInnhold.",
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }
}
