<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Arbeidspakke A3 (docs/enterprise-wiki-knowledge-integration-plan.md §3.3): a run that
 * regenerates an already published page settles publication exactly like every other automated
 * content change. Before this, the page stayed `approved` with an unpublished current version,
 * and WikiController::submit() (draft only) refused to send it for review — a version nobody
 * could ever publish.
 */
class EnterpriseWikiRegenerationPublicationTest extends TestCase
{
    use GrantsWikiPermissions;
    use RefreshDatabase;

    public function test_a_regenerated_published_page_becomes_a_reviewable_draft_and_keeps_serving_the_published_version(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->grantWikiPermissions($customer, $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER));
        $reviewer = $this->grantWikiPermissions($customer, $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR));
        $reviewer->forceFill(['is_wiki_approver' => true])->save();
        [$page, $published] = $this->createPublishedPage($customer);
        $run = $this->createRunUpdating($customer, $page);
        $this->mockGeneration("# Konsept\n\nNy, regenerert forklaring.");

        (new GenerateEnterpriseWikiAppliedPage($run->id, $page->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        $page->refresh();
        $working = $page->currentVersion()->first();

        // 1–3: a new working version, treated as a draft; the published one still serves.
        $this->assertNotSame($published->id, $working->id);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame($published->id, (int) $page->published_version_id);
        $this->assertSame("# Konsept\n\nGodkjent forklaring.", $published->fresh()->content_markdown);

        // 5: nothing was published automatically.
        $state = app(EnterpriseWikiPublicationStatusService::class)->forPage($page->fresh(), $working);
        $this->assertSame(EnterpriseWikiPublicationStatusService::STATE_PUBLISHED_WITH_CHANGES, $state['state']);

        // 4 + 6: the ordinary review flow accepts it, and still does not publish it.
        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect()
            ->assertSessionMissing('error')
            ->assertSessionHas('success');

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertSame($published->id, (int) $page->published_version_id);
        $this->assertSame($reviewer->id, (int) $working->fresh()->reviewer_user_id);
    }

    public function test_a_failed_regeneration_leaves_publication_untouched(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        [$page, $published] = $this->createPublishedPage($customer);
        $run = $this->createRunUpdating($customer, $page);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andThrow(new RuntimeException('model returned nothing usable'));

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $page->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
            $this->fail('the generation should have failed');
        } catch (RuntimeException) {
        }

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame($published->id, (int) $page->published_version_id);
        $this->assertSame($published->id, (int) $page->currentVersion()->value('id'));
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
        $this->assertSame(
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
            EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->value('generation_status'),
        );
    }

    public function test_a_page_already_in_review_keeps_its_review_when_regenerated(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $reviewer = $this->grantWikiPermissions($customer, $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR));
        [$page, $published] = $this->createPublishedPage($customer);
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();
        $run = $this->createRunUpdating($customer, $page);
        $this->mockGeneration("# Konsept\n\nNy forklaring under gjennomgang.");

        (new GenerateEnterpriseWikiAppliedPage($run->id, $page->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        // The settlement rule leaves a named reviewer's open handover alone; the review flow's own
        // guard is what refuses to approve a version that changed after submission.
        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertSame($published->id, (int) $page->published_version_id);
    }

    public function test_a_never_published_draft_stays_a_draft(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $run = $this->createRunUpdating($customer, $page);
        $this->mockGeneration("# Konsept\n\nFørste versjon.");

        (new GenerateEnterpriseWikiAppliedPage($run->id, $page->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertNull($page->published_version_id);
    }

    private function mockGeneration(string $markdown): void
    {
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(function (string $pageTitle, string $pageType, string $sourceText, string $languageCode, string $additionalContext = '', array $linkCatalog = [], array $sourceElements = []) use ($markdown): array {
                $element = $sourceElements[0] ?? ['source_element_key' => 'document-1-full-text', 'source_element_type' => 'manual'];

                return [
                    'markdown' => $markdown,
                    'blocks' => [[
                        'markdown' => $markdown,
                        'content_origin' => 'source_based',
                        'source_element_keys' => [(string) $element['source_element_key']],
                        'source_element_types' => [(string) $element['source_element_type']],
                        'best_practice_reason' => null,
                        'link_intents' => [],
                    ]],
                ];
            });
    }

    /**
     * @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion}
     */
    private function createPublishedPage(Customer $customer): array
    {
        $page = $this->createPage($customer);

        $published = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Konsept\n\nGodkjent forklaring.",
            'generated_by_model' => 'gpt-5',
        ]);

        $page->forceFill([
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'published_version_id' => $published->id,
        ])->save();

        return [$page->fresh(), $published];
    }

    private function createPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'konsept-'.Str::lower(Str::random(6)),
            'title' => 'Konsept',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createRunUpdating(Customer $customer, EnterpriseWikiPage $page): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Konseptet forklares i kildedokumentet.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
        ]);

        return $run;
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Publisering AS',
            'slug' => 'publisering-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
