<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageGenerationFailureRecoveryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Run 963: a run that created four draft articles and merely referenced an existing, published one
 * (`preserve`). Code before 6419ccb3 recorded that reference as a deferred update because the page
 * was in review; a later retry sent the row to page generation, which refused it and rewrote the
 * error. Nothing was ever going to change on the page, so Kjøringer must not keep reporting a
 * failure, offer a retry, or hold the run in «Krever oppfølging» — while the historical row stays
 * exactly as it was, and a real replace, an unreadable plan or a QA control that does not pass
 * today still keep the follow-up open.
 *
 * All fixtures are synthetic; run 963's real data is never read or written.
 */
class WikiRunSettledOutcomeTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const REGENERATION_BLOCKED_ERROR = '[EnterpriseWikiPatchTargetRegenerationBlockedException] Run names the page as a patch target, so it must not be regenerated from source.';

    // =========================================================================
    // A — a historical preserve failure
    // =========================================================================

    public function test_a_historical_preserve_failure_is_no_active_failure_and_the_follow_up_is_complete(): void
    {
        [$owner, $run, $pages] = $this->run963Shape();
        $before = $this->rawPivot($run, $pages['referenced']);

        $row = $this->row($owner, $run);

        $this->assertNull($row['failed_pages'], 'no failed page is counted, named or offered a retry');
        $this->assertTrue($row['follow_up_complete']);
        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_settled').' '
                .trans_choice('procynia.wiki.runs_findings_explanation_settled_drafts', 2, ['count' => 2]),
            $row['findings_explanation'],
        );
        $this->assertFalse(app(EnterpriseWikiPageGenerationFailureRecoveryService::class)->evaluate($run->id)->isResumed());

        // History is untouched: the stored status and the row as the old code left it.
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->fresh()->qa_status);
        $this->assertSame($before, $this->rawPivot($run, $pages['referenced']));
    }

    public function test_the_retry_endpoint_refuses_a_preserve_row_and_changes_nothing(): void
    {
        [$owner, $run, $pages] = $this->run963Shape();

        $this->actingAs($owner)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch("/app/wiki/runs/{$run->id}/retry-failed-pages")
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        $this->assertSame(self::REGENERATION_BLOCKED_ERROR, $this->pivotFor($run, $pages['referenced'])->generation_error);
    }

    public function test_a_replace_beside_the_preserve_keeps_the_row_a_real_failure(): void
    {
        // A replace on the same page, beside its preserve.
        [$owner, $run] = $this->run963Shape(extraTargets: [[]]);

        $row = $this->row($owner, $run);

        $this->assertSame(1, $row['failed_pages']['count']);
        $this->assertFalse($row['follow_up_complete']);
    }

    public function test_a_derived_reconciliation_replace_keeps_the_row_a_real_failure(): void
    {
        [$owner, $run, $pages] = $this->run963Shape();
        $decision = (array) $run->maintainer_decision_json;
        $decision['cross_page_reconciliation'] = [
            'derived_patch_targets' => [$this->target($pages['referenced'], 'replace')],
            'unresolved' => [],
        ];
        $run->forceFill(['maintainer_decision_json' => $decision])->save();

        $row = $this->row($owner, $run);

        $this->assertSame(1, $row['failed_pages']['count']);
        $this->assertFalse($row['follow_up_complete']);
    }

    public function test_an_unreadable_operation_is_never_read_as_no_change(): void
    {
        [$owner, $run, $pages] = $this->run963Shape(extraTargets: [
            ['operation' => 'rewrite'],
        ]);

        $this->assertFalse($this->pivotFor($run, $pages['referenced'])->isFailedNoChangePatch((array) $run->maintainer_decision_json));
        $this->assertFalse($this->row($owner, $run)['follow_up_complete']);
    }

    // =========================================================================
    // C — QA without a content finding
    // =========================================================================

    public function test_a_qa_control_that_does_not_pass_today_keeps_the_follow_up_open(): void
    {
        [$owner, $run, $pages] = $this->run963Shape();
        // Verification is unfinished on one of the run's pages: QA judged now does not pass.
        $this->createWikiClaim($pages['article'], 'En påstand som aldri ble verifisert.', ['verified_at' => null]);

        $row = $this->row($owner, $run);

        $this->assertNull($row['failed_pages']);
        $this->assertFalse($row['follow_up_complete']);
        $this->assertNotSame(__('procynia.wiki.runs_findings_explanation_settled'), $row['findings_explanation']);
    }

    public function test_an_open_finding_keeps_the_follow_up_open(): void
    {
        [$owner, $run, $pages] = $this->run963Shape();
        // The same shape WikiRunDismissalTest uses for an undecided finding.
        $this->createWikiClaim($pages['article'], 'Avvik skal registreres.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0001',
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_metadata' => ['classification_basis' => 'semantic_verification', 'verdict' => 'not_supported', 'deterministic_reason' => 'normative_strengthening'],
        ]);

        $this->assertFalse($this->row($owner, $run)['follow_up_complete']);
    }

    // =========================================================================
    // D + E — what the run did to each page, and the article as it is now
    // =========================================================================

    public function test_the_pages_list_shows_the_actual_outcome_and_the_current_wiki_status(): void
    {
        [$owner, $run, $pages, $customer] = $this->run963Shape();

        $patched = $this->createWikiPage($customer, 'Faktisk oppdatert', ['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW]);
        $patchedVersion = $this->version($patched, 2);
        $this->pivot($run, $patched, EnterpriseWikiIngestRunPage::ACTION_PATCHED, EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $patchedVersion);

        $deferred = $this->createWikiPage($customer, 'Utsatt oppdatering', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $this->version($deferred, 1);
        $this->pivot($run, $deferred, EnterpriseWikiIngestRunPage::ACTION_PATCHED, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, null, '[EnterpriseWikiPageUnderReviewException] Page is in review.');
        $this->addTarget($run, $this->target($deferred, 'replace'));

        $updatedFailed = $this->createWikiPage($customer, 'Ikke oppdatert', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $this->version($updatedFailed, 1);
        $this->pivot($run, $updatedFailed, EnterpriseWikiIngestRunPage::ACTION_UPDATED, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, null, 'RuntimeException: model returned nothing');

        $createdFailed = $this->createWikiPage($customer, 'Ikke opprettet', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $this->pivot($run, $createdFailed, EnterpriseWikiIngestRunPage::ACTION_CREATED, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, null, 'RuntimeException: model returned nothing');

        // A document-owner row still pending on the published page: kept, and never the status shown.
        EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $pages['referenced']->id,
            'enterprise_wiki_page_version_id' => $pages['referenced']->currentVersion->id,
            'document_owner_user_id' => $owner->id,
            'source_document_ids' => [$run->source_id],
            'source_documents_hash' => hash('sha256', 'x'),
            'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
        ]);

        $rows = collect($this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/pages")->assertOk()->json('pages'))->keyBy('page_id');

        $this->assertSame('created', $rows[$pages['article']->id]['result']);
        $this->assertSame('draft', $rows[$pages['article']->id]['page_status']);
        $this->assertSame('no_change', $rows[$pages['referenced']->id]['result']);
        $this->assertSame('approved', $rows[$pages['referenced']->id]['page_status'], 'the article as it is now, not the document-owner row');
        $this->assertSame('updated', $rows[$patched->id]['result']);
        $this->assertSame('pending_review', $rows[$patched->id]['page_status']);
        $this->assertSame('deferred', $rows[$deferred->id]['result']);
        $this->assertSame('not_updated', $rows[$updatedFailed->id]['result']);
        $this->assertSame('not_created', $rows[$createdFailed->id]['result']);
    }

    public function test_a_superseded_version_is_not_counted_as_handled(): void
    {
        [$owner, $run, $pages] = $this->run963Shape();
        // A later version replaced what the run produced for the summary.
        $this->version($pages['summary'], 2);

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/pages")->assertOk();

        $this->assertSame(1, $response->json('summary.superseded'));
        $this->assertSame(0, $response->json('summary.done'), 'nobody decided anything on a superseded version');
    }

    // =========================================================================
    // F — the whole journey: drafts are ordinary Wiki work, not a gap in the run
    // =========================================================================

    public function test_a_run_whose_new_articles_are_still_drafts_is_completed_with_remarks_and_may_be_cleared(): void
    {
        [$owner, $run] = $this->run963Shape();

        $ids = fn (string $filter): array => collect(data_get(
            $this->actingAs($owner)->get('/app/wiki?tab=runs&run_status='.$filter)->viewData('page'),
            'props.runs',
        ))->pluck('id')->all();

        $this->assertContains($run->id, $ids('completed'));
        $this->assertContains($run->id, $ids('completed_with_remarks'));
        $this->assertNotContains($run->id, $ids('needs_follow_up'));
        $this->assertTrue($this->row($owner, $run)['can_dismiss']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Two new draft pages (article + summary) and one published page the decision only referenced
     * (`preserve`), whose row old code recorded as failed and a later retry rewrote.
     *
     * @param  list<array<string, mixed>>  $extraTargets  more targets naming the referenced page
     * @return array{0: User, 1: EnterpriseWikiIngestRun, 2: array<string, EnterpriseWikiPage>, 3: Customer}
     */
    private function run963Shape(array $extraTargets = []): array
    {
        $customer = $this->createWikiCustomer('Kjøring 963 testkunde');
        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(10)).'@run-settled-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'etterhendelsesgjennomgang.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $referenced = $this->createWikiPage($customer, 'Incident Commander', [
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
        ]);
        $referencedVersion = $this->version($referenced, 1);
        $referenced->forceFill(['published_version_id' => $referencedVersion->id])->save();

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_json' => [
                'patch_targets' => [
                    $this->target($referenced, 'preserve'),
                    ...array_map(fn (array $target): array => array_merge($this->target($referenced, 'replace'), $target), $extraTargets),
                ],
            ],
            'qa_result' => [
                'incomplete_steps' => ['page_generation_incomplete'],
                'critical_defects' => [],
                'quality_findings' => [],
            ],
            'finished_at' => now(),
        ]);

        $pages = ['referenced' => $referenced];

        foreach ([EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 'article', EnterpriseWikiPage::PAGE_TYPE_SUMMARY => 'summary'] as $type => $key) {
            $page = $this->createWikiPage($customer, ucfirst($key).' etterhendelsesgjennomgang', [
                'page_type' => $type,
                'status' => EnterpriseWikiPage::STATUS_DRAFT,
            ]);
            $this->pivot($run, $page, EnterpriseWikiIngestRunPage::ACTION_CREATED, EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $this->version($page, 1));
            $pages[$key] = $page;
        }

        $this->pivot($run, $referenced, EnterpriseWikiIngestRunPage::ACTION_PATCHED, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, null, self::REGENERATION_BLOCKED_ERROR);

        return [$owner, $run->fresh(), $pages, $customer];
    }

    /** @return array<string, mixed> */
    private function target(?EnterpriseWikiPage $page, string $operation): array
    {
        return [
            'target_page_id' => $page?->id,
            'target_page_title' => $page?->title,
            'target_page_type' => $page?->page_type,
            'target_topic' => 'Responstider',
            'target_heading' => 'Beredskap og responstider',
            'operation' => $operation,
            'relationship' => $operation === 'preserve' ? 'reference_only' : 'substance_changed',
            'superseded_substance' => $operation === 'replace' ? 'innen 30 minutter' : null,
            'replacement_substance' => $operation === 'replace' ? 'innen 15 minutter' : null,
            'source_element_keys' => [],
            'preserve_topics' => [],
        ];
    }

    /** @param array<string, mixed> $target */
    private function addTarget(EnterpriseWikiIngestRun $run, array $target): void
    {
        $decision = (array) $run->fresh()->maintainer_decision_json;
        $decision['patch_targets'][] = $target;
        $run->forceFill(['maintainer_decision_json' => $decision])->save();
    }

    private function version(EnterpriseWikiPage $page, int $number): EnterpriseWikiPageVersion
    {
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => true,
            'content_markdown' => "# {$page->title}\n\nInnhold i versjon {$number}.",
        ]);
    }

    private function pivot(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $action,
        string $status,
        ?EnterpriseWikiPageVersion $version,
        ?string $error = null,
    ): EnterpriseWikiIngestRunPage {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
            'generated_page_version_id' => $version?->id,
            'generation_status' => $status,
            'generation_error' => $error,
            'claims_extracted_at' => now(),
            'link_assessment' => $version !== null ? ['schema' => 1, 'outcome' => 'no_relevant_links'] : null,
            'links_assessed_at' => $version !== null ? now() : null,
        ]);
    }

    private function pivotFor(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> the stored row exactly as the database holds it */
    private function rawPivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): array
    {
        return (array) DB::table('enterprise_wiki_ingest_run_pages')
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function row(User $user, EnterpriseWikiIngestRun $run): array
    {
        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');
        $response->assertOk();

        $row = collect(data_get($response->viewData('page'), 'props.runs'))->firstWhere('id', $run->id);
        $this->assertIsArray($row, 'the run is listed');

        return $row;
    }
}
