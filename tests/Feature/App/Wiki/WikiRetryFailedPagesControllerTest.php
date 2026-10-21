<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageGenerationFailureRecoveryService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Arbeidspakke A4 (docs/enterprise-wiki-knowledge-integration-plan.md §4.2): HTTP coverage for
 * «Prøv igjen for feilede sider» (WikiController::retryFailedPages()) and the Kjøringer row data
 * that decides whether it is offered. Authorization mirrors cancelRun()/retryMaintainerDecision();
 * eligibility — including never retrying a plan defect (decision D3) — is entirely
 * EnterpriseWikiPageGenerationFailureRecoveryService's decision.
 */
class WikiRetryFailedPagesControllerTest extends TestCase
{
    use GrantsWikiPermissions;
    use RefreshDatabase;

    private const TRANSIENT_ERROR = '[RuntimeException] OpenAI request failed: HTTP status [503]: upstream unavailable';

    private const UNKNOWN_ERROR = '[EnterpriseWikiPageGenerationIncompleteException] planned section(s) still missing or empty after one repair: Formål.';

    private const PLAN_DEFECT_ERROR = '[EnterpriseWikiPlannedSectionEvidenceMissingException] Run [26] page [110] (article): planned_section_no_evidence before generation: Formål og tidspunkt.';

    private const REVIEW_DEFERRAL_ERROR = '[EnterpriseWikiPageUnderReviewException] Page [69] is in pending_review. An automated change never writes a new working version while a review is open — the update is deferred until the review is closed.';

    public function test_a_transient_failure_retries_only_the_failed_page(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);

        $this->actingAs($owner)
            ->patch("/app/wiki/runs/{$run->id}/retry-failed-pages", ['tab' => 'runs'])
            ->assertRedirect('/app/wiki?tab=runs')
            ->assertSessionHas('success', __('procynia.wiki.run_retry_failed_pages_success'));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $run->status);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->qa_status, 'the old QA verdict must not finalize the resumed run');

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 1);
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, fn ($job) => $job->pageId === $pages['article']->id);
        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id && $job->recoverStalePages);

        // Already generated pages are left exactly as they were.
        foreach (['summary', 'concept'] as $type) {
            $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $this->pivot($run, $pages[$type])->generation_status);
        }
    }

    public function test_an_unknown_failure_may_be_retried_by_hand(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::UNKNOWN_ERROR);

        $this->actingAs($owner)
            ->patch("/app/wiki/runs/{$run->id}/retry-failed-pages", ['tab' => 'runs'])
            ->assertSessionHas('success');

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, fn ($job) => $job->pageId === $pages['article']->id);
    }

    public function test_a_plan_defect_is_never_retried(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::PLAN_DEFECT_ERROR);

        $this->actingAs($owner)
            ->patch("/app/wiki/runs/{$run->id}/retry-failed-pages", ['tab' => 'runs'])
            ->assertSessionHas('error', __('procynia.wiki.run_retry_failed_pages_plan_defect'));

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $this->pivot($run, $pages['article'])->generation_status);
        $this->assertSame(self::PLAN_DEFECT_ERROR, $this->pivot($run, $pages['article'])->generation_error);
        Queue::assertNothingPushed();
    }

    public function test_a_second_retry_while_the_first_is_running_dispatches_nothing_more(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")->assertSessionHas('success');
        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")
            ->assertSessionHas('error', __('procynia.wiki.run_retry_failed_pages_not_allowed'));

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 1);
    }

    public function test_a_contributor_who_does_not_own_the_document_is_forbidden(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$run] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR, $documentOwner->id);

        $this->actingAs($contributor)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")->assertForbidden();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_the_document_owner_may_retry_their_own_document(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$run] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR, $documentOwner->id);

        $this->actingAs($documentOwner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")->assertSessionHas('success');
    }

    public function test_a_user_without_source_management_is_forbidden(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        // A contributor who owns the document but was only given read access to the Wiki: owning
        // a document is not source administration (a System Owner holds Wiki permissions
        // implicitly, so the reader here must be an ordinary role).
        $reader = $this->grantWikiPermissions($customer, $this->createBareUser($customer, User::BID_ROLE_CONTRIBUTOR), [CustomerPermissionCatalog::WIKI_VIEW]);
        [$run] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR, $reader->id);

        $this->actingAs($reader)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_a_run_of_another_customer_is_not_found(): void
    {
        Queue::fake();
        $customer = $this->createCustomer('Kunde A');
        $other = $this->createCustomer('Kunde B');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run] = $this->createPartiallyCompletedRun($other, self::TRANSIENT_ERROR);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")->assertNotFound();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_cancelled_run_is_not_retried(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);
        $run->forceFill(['status' => EnterpriseWikiIngestRun::STATUS_CANCELLED])->save();

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")
            ->assertSessionHas('error', __('procynia.wiki.run_retry_failed_pages_not_allowed'));

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_run_whose_pages_all_generated_has_nothing_to_retry(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);
        $this->pivot($run, $pages['article'])->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, 'generation_error' => null]);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")
            ->assertSessionHas('error', __('procynia.wiki.run_retry_failed_pages_not_allowed'));
        Queue::assertNothingPushed();
    }

    public function test_the_runs_tab_offers_the_retry_only_when_the_service_would_resume(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $reader = $this->grantWikiPermissions($customer, $this->createBareUser($customer, User::BID_ROLE_CONTRIBUTOR), [CustomerPermissionCatalog::WIKI_VIEW]);
        [$transientRun] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);
        [$planDefectRun] = $this->createPartiallyCompletedRun($customer, self::PLAN_DEFECT_ERROR);

        $rows = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->assertOk()->viewData('page')['props']['runs'])->keyBy('id');

        $this->assertSame(EnterpriseWikiPageGenerationFailureRecoveryService::FAILURE_TRANSIENT, $rows[$transientRun->id]['failed_pages']['failure_kind']);
        $this->assertTrue($rows[$transientRun->id]['failed_pages']['can_retry']);
        $this->assertFalse($rows[$transientRun->id]['failed_pages']['blocked_by_review']);
        $this->assertSame(EnterpriseWikiPageGenerationFailureRecoveryService::FAILURE_PLAN_DEFECT, $rows[$planDefectRun->id]['failed_pages']['failure_kind']);
        $this->assertFalse($rows[$planDefectRun->id]['failed_pages']['can_retry']);
        $this->assertTrue($rows[$transientRun->id]['may_manage']);

        // A reader sees why the pages are incomplete, never the action.
        $readerRows = collect($this->actingAs($reader)->get('/app/wiki?tab=runs')->assertOk()->viewData('page')['props']['runs'])->keyBy('id');
        $this->assertFalse($readerRows[$transientRun->id]['failed_pages']['can_retry']);
        $this->assertSame(EnterpriseWikiPageGenerationFailureRecoveryService::FAILURE_TRANSIENT, $readerRows[$transientRun->id]['failed_pages']['failure_kind']);
        $this->assertFalse($readerRows[$transientRun->id]['may_manage'], 'the detail view can say someone else has to act');
    }

    // =========================================================================
    // An update held back by an open review (run 963)
    // =========================================================================

    public function test_a_review_deferral_is_its_own_kind_and_never_a_temporary_fault(): void
    {
        $this->assertSame(
            EnterpriseWikiPageGenerationFailureRecoveryService::FAILURE_REVIEW_DEFERRED,
            EnterpriseWikiPageGenerationFailureRecoveryService::failureKind(self::REVIEW_DEFERRAL_ERROR),
        );
    }

    public function test_the_row_names_the_page_in_review_links_to_it_and_offers_no_retry(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::REVIEW_DEFERRAL_ERROR);
        $pages['article']->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'title' => 'Incident Commander'])->save();

        $row = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->assertOk()->viewData('page')['props']['runs'])->keyBy('id')[$run->id];

        $this->assertSame(1, $row['failed_pages']['count']);
        $this->assertSame(EnterpriseWikiPageGenerationFailureRecoveryService::FAILURE_REVIEW_DEFERRED, $row['failed_pages']['failure_kind']);
        $this->assertFalse($row['failed_pages']['can_retry'], 'a retry while the review is open could only be refused again');
        $this->assertTrue($row['failed_pages']['blocked_by_review']);
        $this->assertSame([[
            'title' => 'Incident Commander',
            'url' => route('app.wiki.show', ['slug' => $pages['article']->slug, 'back_url' => '/app/wiki?tab=runs&focus_run='.$run->id], false),
            'in_review' => true,
        ]], $row['failed_pages']['pages']);
        $this->assertTrue($row['may_manage']);
        $this->assertSame(0, $row['findings_open_count']);
        $this->assertSame(0, $row['findings_resolved_count']);
        $this->assertFalse($row['follow_up_complete'], 'the update is still owed, so the run still needs follow-up');

        // The link opens the page, and the page leads back to this run.
        $this->actingAs($owner)->get($row['failed_pages']['pages'][0]['url'])
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('navigation_origin.context', 'runs')
                ->where('navigation_origin.back_url', '/app/wiki?tab=runs&focus_run='.$run->id));
    }

    public function test_a_direct_retry_while_the_review_is_open_is_refused_in_words_and_dispatches_nothing(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::REVIEW_DEFERRAL_ERROR);
        $pages['article']->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();
        $before = $run->fresh()->only(['status', 'qa_status', 'qa_last_error', 'finished_at', 'error_message']);

        $this->actingAs($owner)
            ->patch("/app/wiki/runs/{$run->id}/retry-failed-pages", ['tab' => 'runs'])
            ->assertRedirect('/app/wiki?tab=runs')
            ->assertSessionHas('error', __('procynia.wiki.run_retry_failed_pages_under_review'));

        // Nothing queued means no generation job and so no AI call; the run's history is untouched.
        Queue::assertNothingPushed();
        $this->assertEquals($before, $run->fresh()->only(['status', 'qa_status', 'qa_last_error', 'finished_at', 'error_message']));
        $pivot = $this->pivot($run, $pages['article']);
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $pivot->generation_status);
        $this->assertSame(self::REVIEW_DEFERRAL_ERROR, $pivot->generation_error);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $pages['article']->fresh()->status);
    }

    public function test_a_page_that_entered_review_after_a_transient_failure_blocks_the_retry_too(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);
        $pages['article']->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();

        $row = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->viewData('page')['props']['runs'])->keyBy('id')[$run->id];
        $this->assertFalse($row['failed_pages']['can_retry']);
        $this->assertTrue($row['failed_pages']['blocked_by_review']);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")
            ->assertSessionHas('error', __('procynia.wiki.run_retry_failed_pages_under_review'));
        Queue::assertNothingPushed();
    }

    public function test_once_the_review_is_closed_the_update_may_be_retried(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::REVIEW_DEFERRAL_ERROR);
        $pages['article']->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED])->save();

        $row = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->viewData('page')['props']['runs'])->keyBy('id')[$run->id];
        $this->assertSame(EnterpriseWikiPageGenerationFailureRecoveryService::FAILURE_REVIEW_DEFERRED, $row['failed_pages']['failure_kind']);
        $this->assertTrue($row['failed_pages']['can_retry']);
        $this->assertFalse($row['failed_pages']['blocked_by_review']);
        $this->assertFalse($row['failed_pages']['pages'][0]['in_review']);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")
            ->assertSessionHas('success', __('procynia.wiki.run_retry_failed_pages_success'));
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, fn ($job) => $job->pageId === $pages['article']->id);
    }

    public function test_a_closed_review_does_not_bypass_the_other_retry_rules(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::REVIEW_DEFERRAL_ERROR);
        $pages['article']->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED])->save();
        // Another page of the run still generating: the service refuses for that reason alone.
        $this->pivot($run, $pages['summary'])->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING]);

        $row = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->viewData('page')['props']['runs'])->keyBy('id')[$run->id];
        $this->assertFalse($row['failed_pages']['can_retry']);
        $this->assertFalse($row['failed_pages']['blocked_by_review']);
    }

    public function test_a_reader_sees_the_page_in_review_but_is_offered_no_action(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $reader = $this->grantWikiPermissions($customer, $this->createBareUser($customer, User::BID_ROLE_CONTRIBUTOR), [CustomerPermissionCatalog::WIKI_VIEW]);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::REVIEW_DEFERRAL_ERROR);
        $pages['article']->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED])->save();

        $row = collect($this->actingAs($reader)->get('/app/wiki?tab=runs')->assertOk()->viewData('page')['props']['runs'])->keyBy('id')[$run->id];

        $this->assertFalse($row['may_manage']);
        $this->assertFalse($row['failed_pages']['can_retry'], 'a closed review never hands the retry to someone who may not manage the document');
        $this->assertFalse($row['can_dismiss']);
        $this->assertFalse($row['can_cancel']);
        $this->assertCount(1, $row['failed_pages']['pages']);

        $this->actingAs($reader)->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_a_failed_page_of_another_customer_is_never_named(): void
    {
        $customer = $this->createCustomer('Kunde A');
        $other = $this->createCustomer('Kunde B');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run] = $this->createPartiallyCompletedRun($customer, self::REVIEW_DEFERRAL_ERROR);
        [, $otherPages] = $this->createPartiallyCompletedRun($other, self::REVIEW_DEFERRAL_ERROR);
        // A corrupt pivot pointing across customers must not leak the other customer's page.
        $this->pivot($run, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE)->firstOrFail())
            ->update(['enterprise_wiki_page_id' => $otherPages['article']->id]);

        $row = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->viewData('page')['props']['runs'])->keyBy('id')[$run->id];

        $this->assertSame([], $row['failed_pages']['pages']);
    }

    public function test_a_completed_run_carries_no_failed_pages_summary(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$run, $pages] = $this->createPartiallyCompletedRun($customer, self::TRANSIENT_ERROR);
        $this->pivot($run, $pages['article'])->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED]);
        $run->forceFill(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED])->save();

        $rows = collect($this->actingAs($owner)->get('/app/wiki?tab=runs')->assertOk()->viewData('page')['props']['runs'])->keyBy('id');

        $this->assertNull($rows[$run->id]['failed_pages']);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * The run-26 shape after controlled partial completion: the article failed, the summary and
     * a deferred concept generated, the run is escalated by post-ingest QA.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: array<string, EnterpriseWikiPage>}
     */
    private function createPartiallyCompletedRun(Customer $customer, string $articleError, ?int $documentOwnerId = null): array
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $documentOwnerId,
            'original_filename' => 'kilde-'.Str::lower(Str::random(4)).'.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_last_error' => 'Run has unfinished continuation step(s) or an active reservation: page_generation_incomplete.',
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'finished_at' => now(),
        ]);

        $pages = [];

        foreach ([
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => [EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $articleError],
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => [EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, null],
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => [EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, null],
        ] as $type => [$status, $error]) {
            $page = EnterpriseWikiPage::query()->create([
                'customer_id' => $customer->id,
                'slug' => $type.'-'.Str::lower(Str::random(6)),
                'title' => ucfirst($type),
                'page_type' => $type,
                'status' => EnterpriseWikiPage::STATUS_DRAFT,
                'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                'last_source_hash' => str_pad('hash', 64, '0'),
            ]);

            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => $status,
                'generation_error' => $error,
            ]);

            $pages[$type] = $page;
        }

        return [$run, $pages];
    }

    private function pivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->firstOrFail();
    }

    private function createCustomer(string $name = 'Retry AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

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
        return $this->grantWikiPermissions($customer, $this->createBareUser($customer, $bidRole));
    }

    private function createBareUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Retry Tester',
            'email' => Str::lower(Str::random(8)).'@retry-failed-pages.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
