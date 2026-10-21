<?php

namespace Tests\Feature\App\Wiki;

use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunDismissalService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * «Fjern fra oversikten» in Wiki → Kjøringer: a finished run with nothing left to follow up can be
 * cleared from the list. Visibility only — the run, its status and everything referring to it stay.
 */
class WikiRunDismissalTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const RUN_26_MESSAGE = '1 of 2 initial page(s) failed to generate: Vurdering av betalingsbetingelser og økonomisk informasjon. Details — Vurdering av betalingsbetingelser og økonomisk informasjon (article): [EnterpriseWikiPlannedSectionEvidenceMissingException] Run [26] page [110] (article): planned_section_no_evidence before generation: Formål og tidspunkt for aktiviteten, Ansvar.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── A: a failed run shaped like #26 ─────────────────────────────────────

    public function test_a_failed_run_is_offered_cleared_and_kept_in_the_database(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        $run = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED, ['error_message' => self::RUN_26_MESSAGE]);
        $updatedAt = $run->fresh()->updated_at->toIso8601String();

        $this->assertTrue($this->row($owner, $run)['can_dismiss']);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss", ['tab' => 'runs'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Kjøringen er fjernet fra oversikten.');

        $this->assertNull($this->row($owner, $run));

        $fresh = $run->fresh();
        $this->assertNotNull($fresh->dismissed_at);
        $this->assertSame($owner->id, $fresh->dismissed_by_user_id);
        // Visibility only: status, message and the run's own progress time are as they were.
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(self::RUN_26_MESSAGE, $fresh->error_message);
        $this->assertSame($updatedAt, $fresh->updated_at->toIso8601String());
    }

    public function test_clearing_again_changes_nothing_and_answers_the_same(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        $run = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED);
        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss");
        $first = $run->fresh()->dismissed_at->toIso8601String();

        $this->travel(5)->minutes();
        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss")
            ->assertSessionHas('success', 'Kjøringen er fjernet fra oversikten.');

        $this->assertSame($first, $run->fresh()->dismissed_at->toIso8601String());
    }

    // ── B / C: completed and cancelled runs ─────────────────────────────────

    public function test_completed_and_cancelled_runs_can_be_cleared(): void
    {
        [$customer, $owner, $document] = $this->tenant();

        foreach ([EnterpriseWikiIngestRun::STATUS_COMPLETED, EnterpriseWikiIngestRun::STATUS_CANCELLED] as $status) {
            $run = $this->ingestRun($customer, $document, $status, ['qa_status' => $status === EnterpriseWikiIngestRun::STATUS_COMPLETED ? EnterpriseWikiIngestRun::QA_STATUS_PASSED : null]);
            $this->assertTrue($this->row($owner, $run)['can_dismiss'], $status);

            $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss")->assertSessionHasNoErrors();
            $this->assertNull($this->row($owner, $run), $status);
            $this->assertSame($status, $run->fresh()->status);
        }
    }

    // ── D: active and waiting runs ──────────────────────────────────────────

    public function test_running_and_waiting_runs_are_never_offered_and_are_refused(): void
    {
        [$customer, $owner, $document] = $this->tenant();

        foreach ([
            EnterpriseWikiIngestRun::STATUS_QUEUED,
            EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
        ] as $status) {
            $run = $this->ingestRun($customer, $document, $status);
            $this->assertFalse($this->row($owner, $run)['can_dismiss'], $status);

            $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss")
                ->assertSessionHasErrors(['run' => 'Kjøringen kan ikke fjernes fra oversikten nå, fordi den fortsatt behandles eller har oppfølging som gjenstår. Siden er oppdatert.']);
            $this->assertNull($run->fresh()->dismissed_at, $status);
            $this->assertNotNull($this->row($owner, $run), $status);
        }
    }

    // ── E: escalated runs ───────────────────────────────────────────────────

    public function test_an_escalated_run_with_open_follow_up_stays(): void
    {
        [$customer, $owner, $document] = $this->tenant();

        // Escalated on a quality finding that cannot be re-checked from current data.
        $otherFinding = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_ESCALATED, [
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_result' => ['quality_findings' => [EnterpriseWikiPostIngestQaService::FINDING_SOURCE_SECTION_UNCOVERED]],
        ]);
        // Escalated with no recorded quality finding at all: uncertain, so it stays.
        $unknown = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_ESCALATED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);
        // Escalated on normative strengthening with that finding still open.
        [$openNormative] = $this->escalatedNormativeRun($customer, $document, decided: false);

        foreach ([$otherFinding, $unknown, $openNormative] as $run) {
            $this->assertFalse($this->row($owner, $run)['can_dismiss'], "run {$run->id}");
            $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss")->assertSessionHasErrors('run');
            $this->assertNull($run->fresh()->dismissed_at);
        }
    }

    public function test_an_escalated_run_whose_follow_up_is_complete_can_be_cleared(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        [$run] = $this->escalatedNormativeRun($customer, $document, decided: true);

        $this->assertTrue($this->row($owner, $run)['can_dismiss']);
        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss")->assertSessionHasNoErrors();
        $this->assertNull($this->row($owner, $run));
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->fresh()->qa_status);
    }

    public function test_an_escalated_run_with_a_failed_page_stays_even_when_qa_follow_up_is_complete(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        [$run, $page] = $this->escalatedNormativeRun($customer, $document, decided: true);
        EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->where('enterprise_wiki_page_id', $page->id)
            ->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, 'generation_error' => 'unknown']);

        $this->assertSame(EnterpriseWikiRunDismissalService::REFUSAL_FOLLOW_UP_OPEN, app(EnterpriseWikiRunDismissalService::class)->refusal($run->fresh()));
    }

    // ── F: authorization ────────────────────────────────────────────────────

    public function test_a_person_who_may_not_manage_the_document_gets_403_and_nothing_changes(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        $run = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($contributor)->patch("/app/wiki/runs/{$run->id}/dismiss")->assertForbidden();
        $this->assertNull($run->fresh()->dismissed_at);

        $row = $this->row($contributor, $run);
        $this->assertTrue($row === null || $row['can_dismiss'] === false);
    }

    public function test_another_customers_run_is_404(): void
    {
        [$customer, , $document] = $this->tenant();
        [, $otherOwner] = $this->tenant('Annen kunde');
        $run = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED);

        $this->actingAs($otherOwner)->patch("/app/wiki/runs/{$run->id}/dismiss")->assertNotFound();
        $this->assertNull($run->fresh()->dismissed_at);
    }

    // ── G: nothing that refers to the run is touched ────────────────────────

    public function test_clearing_leaves_pages_claims_qa_data_usage_and_the_documents_latest_run_untouched(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        $run = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED]);
        $page = $this->createWikiPage($customer, 'Betalingsbetingelser');
        $version = EnterpriseWikiPageVersion::query()->create(['enterprise_wiki_page_id' => $page->id, 'version_number' => 1, 'is_current' => true, 'content_markdown' => 'Tekst.']);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
        $claim = $this->createWikiClaim($page, 'Fakturaer betales innen 30 dager.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
        ]);
        DB::table('enterprise_wiki_qa_snapshots')->insert($this->snapshotRow($run));
        $usageId = DB::table('ai_usage_attempts')->insertGetId([
            'customer_id' => $customer->id,
            'feature' => 'enterprise_wiki',
            'operation_key' => 'enterprise_wiki.page_generation',
            'enterprise_wiki_ingest_run_id' => $run->id,
            'status' => 'succeeded',
            'endpoint' => 'responses',
            'model' => 'gpt-5',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->referencesTo($run, $page, $claim);
        $latestBefore = $this->latestRunFor($owner, $document);

        $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/dismiss")->assertSessionHasNoErrors();

        $this->assertSame($before, $this->referencesTo($run, $page, $claim));
        $this->assertSame($run->id, (int) DB::table('ai_usage_attempts')->where('id', $usageId)->value('enterprise_wiki_ingest_run_id'));
        // Kildedokumenter still shows the document's real latest run.
        $this->assertSame($latestBefore, $this->latestRunFor($owner, $document));
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $latestBefore['status']);
        $this->assertSame($run->created_at->toJSON(), $latestBefore['created_at']);
        // Internal queries that select runs by status still see it.
        $this->assertTrue(EnterpriseWikiIngestRun::query()->where('status', EnterpriseWikiIngestRun::STATUS_COMPLETED)->whereKey($run->id)->exists());
    }

    // ── Status names in Kjøringer ───────────────────────────────────────────

    public function test_each_row_carries_whether_an_escalated_run_still_needs_follow_up(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        [$done] = $this->escalatedNormativeRun($customer, $document, decided: true);
        [$open] = $this->escalatedNormativeRun($customer, $document, decided: false);
        $uncertain = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_ESCALATED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);
        $completed = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $this->assertTrue($this->row($owner, $done)['follow_up_complete']);
        $this->assertFalse($this->row($owner, $open)['follow_up_complete']);
        $this->assertFalse($this->row($owner, $uncertain)['follow_up_complete']);
        $this->assertNull($this->row($owner, $completed)['follow_up_complete']);
        // The stored status is untouched.
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $done->fresh()->status);
    }

    public function test_the_status_filter_offers_the_groups_the_rows_show(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        [$done] = $this->escalatedNormativeRun($customer, $document, decided: true);
        [$open] = $this->escalatedNormativeRun($customer, $document, decided: false);
        $queued = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_QUEUED);
        $generating = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);
        $completed = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $ids = function (string $filter) use ($owner): array {
            $response = $this->actingAs($owner)->get('/app/wiki?tab=runs&run_status='.$filter);

            return collect(data_get($response->viewData('page'), 'props.runs'))->pluck('id')->sort()->values()->all();
        };

        $this->assertSame([$open->id], $ids('needs_follow_up'));
        // «Fullført» is one word: an escalated run whose follow-up is done is listed with the
        // completed ones. The older completed_with_remarks link still finds it.
        $this->assertSame(collect([$completed->id, $done->id])->sort()->values()->all(), $ids('completed'));
        $this->assertSame([$done->id], $ids('completed_with_remarks'));
        $this->assertSame([$queued->id], $ids('waiting'));
        $this->assertSame([$generating->id], $ids('in_progress'));
        // A link with a stored status still works.
        $this->assertSame(collect([$done->id, $open->id])->sort()->values()->all(), $ids('escalated'));
    }

    /**
     * Runs #27–#29: recorded as completed, but by their own record never ran — no start, no end,
     * no page plan, no pages, no error. They have nothing to show or follow up, so the list leaves
     * them out. Anything that started, failed, or produced a page stays.
     */
    public function test_a_run_recorded_as_completed_that_never_ran_is_left_out_of_the_list(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        $neverRan = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, ['maintainer_decision_status' => null]);
        $started = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, ['maintainer_decision_status' => null, 'started_at' => now()->subMinute()]);
        $withError = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, ['maintainer_decision_status' => null, 'error_message' => 'Noe gikk galt.']);
        $withPage = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, ['maintainer_decision_status' => null]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $withPage->id,
            'enterprise_wiki_page_id' => $this->createWikiPage($customer, 'Produsert')->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
        $failed = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED, ['maintainer_decision_status' => null]);

        $ids = collect(data_get($this->actingAs($owner)->get('/app/wiki?tab=runs')->viewData('page'), 'props.runs'))->pluck('id');

        $this->assertNotContains($neverRan->id, $ids);
        foreach ([$started, $withError, $withPage, $failed] as $run) {
            $this->assertContains($run->id, $ids);
        }
        // Left out of the list only: the record itself is untouched.
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $neverRan->fresh()->status);
        $this->assertNull($neverRan->fresh()->dismissed_at);
    }

    public function test_the_documents_latest_run_is_read_the_same_way(): void
    {
        [$customer, $owner, $document] = $this->tenant();
        $this->escalatedNormativeRun($customer, $document, decided: true);

        $this->assertTrue($this->latestRunFor($owner, $document)['follow_up_complete']);
    }

    public function test_the_english_confirmation_follows_the_users_language(): void
    {
        app()->setLocale('en');
        $this->assertSame('Requires follow-up', __('procynia.wiki.run_status_needs_follow_up'));
        $this->assertSame('Follow-up completed', __('procynia.wiki.run_status_completed_with_remarks'));
        $this->assertSame('The progress shows how the original run ended. The QA follow-up has been completed since.', __('procynia.wiki.run_progress_history_note'));
        $this->assertSame('In progress', __('procynia.wiki.run_status_in_progress'));
        $this->assertSame('Waiting', __('procynia.wiki.run_status_waiting'));
        app()->setLocale('no');
        $this->assertSame('Oppfølging fullført', __('procynia.wiki.run_status_completed_with_remarks'));
        $this->assertSame('Fremdriften viser resultatet fra den opprinnelige kjøringen. QA-oppfølgingen er senere fullført.', __('procynia.wiki.run_progress_history_note'));
        $this->assertSame('Krever oppfølging', __('procynia.wiki.run_status_needs_follow_up'));
        app()->setLocale('en');
        $this->assertSame('The run has been removed from the overview.', __('procynia.wiki.run_dismiss_success'));
        $this->assertSame('Remove the run from the overview?', __('procynia.wiki.run_dismiss_confirm_title'));
        $this->assertSame('Remove from overview', __('procynia.wiki.run_dismiss_button'));
    }

    // =========================================================================

    /** @return array{0: Customer, 1: User, 2: EnterpriseWikiDocument} */
    private function tenant(string $name = 'Kjøringsopprydding testkunde'): array
    {
        $customer = $this->createWikiCustomer($name);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'betalingsbetingelser-'.Str::lower(Str::random(4)).'.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Tekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return [$customer, $owner, $document];
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Run Dismissal '.$bidRole,
            'email' => Str::lower(Str::random(10)).'@run-dismissal-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function ingestRun(Customer $customer, EnterpriseWikiDocument $document, string $status, array $attributes = []): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ], $attributes));
    }

    /**
     * A run escalated on normative strengthening, with its strengthened claim on the current
     * version either still undecided or approved.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage}
     */
    private function escalatedNormativeRun(Customer $customer, EnterpriseWikiDocument $document, bool $decided): array
    {
        $run = $this->ingestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_ESCALATED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);
        $page = $this->createWikiPage($customer, 'Avvik');
        $version = EnterpriseWikiPageVersion::query()->create(['enterprise_wiki_page_id' => $page->id, 'version_number' => 1, 'is_current' => true, 'content_markdown' => 'Avvik skal registreres.']);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
        $claim = $this->createWikiClaim($page, 'Avvik skal registreres.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0001',
            'approval_status' => $decided ? EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED : EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_metadata' => ['classification_basis' => 'semantic_verification', 'verdict' => 'not_supported', 'deterministic_reason' => 'normative_strengthening'],
        ]);
        $run->update(['qa_result' => [
            'quality_findings' => [EnterpriseWikiPostIngestQaService::FINDING_NORMATIVE_STRENGTHENING],
            'checks' => ['normative_fidelity' => ['open_strengthened_claim_ids' => [$claim->id], 'ambiguous_claim_ids' => []]],
        ]]);

        return [$run->fresh(), $page];
    }

    /** @return array<string, mixed>|null */
    private function row(User $user, EnterpriseWikiIngestRun $run): ?array
    {
        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        if (! $response->isOk()) {
            return null;
        }

        return collect(data_get($response->viewData('page'), 'props.runs'))->firstWhere('id', $run->id);
    }

    /** @return array<string, mixed>|null */
    private function latestRunFor(User $user, EnterpriseWikiDocument $document): ?array
    {
        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $latest = collect(data_get($response->viewData('page'), 'props.sources'))->firstWhere('id', $document->id)['latest_ingest_run'] ?? null;

        return $latest === null ? null : json_decode(json_encode($latest), true);
    }

    /** @return array<string, mixed> */
    private function referencesTo(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): array
    {
        return [
            'run_pages' => EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->get()->toArray(),
            'snapshots' => EnterpriseWikiQaSnapshot::query()->where('enterprise_wiki_ingest_run_id', $run->id)->count(),
            'usage' => AiUsageAttempt::query()->where('enterprise_wiki_ingest_run_id', $run->id)->count(),
            'page' => $page->fresh()->toArray(),
            'versions' => EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->get()->toArray(),
            'claim' => $claim->fresh()->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotRow(EnterpriseWikiIngestRun $run): array
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('enterprise_wiki_qa_snapshots');
        $row = [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => 1,
            'snapshotted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return array_intersect_key($row, array_flip($columns));
    }
}
