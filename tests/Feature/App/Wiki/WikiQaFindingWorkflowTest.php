<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * A QA finding followed from Kjøringer to its end state: the claim decision is stored, the
 * confirmation says what happened, the article and the findings list agree on the result, and a
 * run that escalated on the finding keeps its history while saying its follow-up is done.
 *
 * Synthetic, isolated fixtures only — one customer per test, no AI, no real run.
 */
class WikiQaFindingWorkflowTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const STRENGTHENED = 'Forbedringssløyfen skal måles som en egen prosess.';

    private const SOURCE_SENTENCE = 'Forbedringssløyfen bør måles som en egen prosess.';

    private const FAITHFUL = 'Den som ledet hendelsen, eier gjennomgangen.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── Scenario A: open normative finding → edit and approve ───────────────

    public function test_an_open_normative_finding_is_edited_and_approved_and_kjoringer_shows_it_handled(): void
    {
        $f = $this->fixture();

        $before = $this->findings($f);
        $finding = $this->findingFor($before, $f['claim']);
        $this->assertSame('open_for_qa_review', $finding['status']);
        $this->assertSame('open_and_handle', $finding['action']);
        $this->assertSame(1, $before['summary']['open_qa_review']);

        // The finding link opens the article on the claim.
        $show = $this->actingAs($f['owner'])->get($finding['url']);
        $show->assertOk();
        $this->assertSame('ready', data_get($show->viewData('page'), 'props.review_reference.status'));
        $this->assertSame('open', collect(data_get($show->viewData('page'), 'props.claims'))->firstWhere('id', $f['claim']->id)['finding_resolution']);

        $response = $this->actingAs($f['owner'])->patch(
            "/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve",
            ['approved_text' => self::SOURCE_SENTENCE, 'back_url' => $f['back_url']],
        );

        $response->assertRedirect(route('app.wiki.show', ['slug' => $f['page']->slug, 'claim_id' => $f['claim']->id, 'back_url' => $f['back_url']]));
        $response->assertSessionHas('success', 'Teksten er lagret, og påstanden er godkjent. QA-funnet er ferdigbehandlet.');
        $this->assertTrue($f['claim']->fresh()->isApproved());

        $after = $this->findings($f);
        $finding = $this->findingFor($after, $f['claim']);
        $this->assertSame('approved_edited', $finding['status']);
        $this->assertSame('view_review', $finding['action']);
        $this->assertFalse($finding['can_handle']);
        // The historical category and severity stay what the finding was.
        $this->assertSame('changed_normative_meaning', $finding['category']);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame(0, $after['summary']['open_qa_review']);
        $this->assertSame(1, $after['summary']['resolved']);

        // The article agrees: the claim's finding is handled.
        $show = $this->actingAs($f['owner'])->get($finding['url']);
        $this->assertSame('approved_edited', collect(data_get($show->viewData('page'), 'props.claims'))->firstWhere('id', $f['claim']->id)['finding_resolution']);
    }

    // ── Scenario B: approved claim with no blocking decision (finding #472) ──

    public function test_an_approved_claim_with_no_blocking_decision_needs_nothing_more(): void
    {
        $f = $this->fixture();
        $f['claim']->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_by_user_id' => $f['owner']->id,
            'approved_at' => now(),
            'blocking_override' => null,
        ]);

        $finding = $this->findingFor($this->findings($f), $f['claim']);

        $this->assertSame('approved', $finding['status']);
        $this->assertSame('pending', $finding['user_decision']);
        $this->assertSame('view_review', $finding['action']);
        $this->assertFalse($finding['can_handle']);
        $this->assertSame($f['owner']->name, $finding['decided_by_name']);
    }

    // ── Scenario C: keep the text with a reason ─────────────────────────────

    public function test_keeping_the_text_stores_the_reason_and_handles_the_finding(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['owner'])->patch(
            "/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve",
            ['comment' => 'Kilden er tolket strengere med vilje.', 'back_url' => $f['back_url']],
        )->assertSessionHas('success', 'Påstanden er godkjent, og teksten er beholdt. QA-funnet er ferdigbehandlet.');

        $claim = $f['claim']->fresh();
        $this->assertTrue($claim->isApproved());
        $this->assertSame('Kilden er tolket strengere med vilje.', $claim->approval_comment);
        $this->assertSame('Kilden er tolket strengere med vilje.', EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->latest('id')->value('comment'));
        $this->assertSame('approved', $this->findingFor($this->findings($f), $f['claim'])['status']);
    }

    // ── Scenario D: reject (remove the text) ────────────────────────────────

    public function test_rejecting_removes_the_text_and_kjoringer_shows_it_rejected(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['owner'])->patch(
            "/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/reject",
            ['back_url' => $f['back_url']],
        )->assertSessionHas('success', 'Påstanden er avvist, og teksten er fjernet fra artikkelen. QA-funnet er ferdigbehandlet.');

        $this->assertTrue($f['claim']->fresh()->isRejected());
        $this->assertStringNotContainsString(self::STRENGTHENED, (string) $f['version']->fresh()->content_markdown);
        $this->assertSame('rejected', $this->findingFor($this->findings($f), $f['claim'])['status']);
    }

    // ── Scenario E: undo the decision ───────────────────────────────────────

    public function test_undoing_an_approval_reopens_the_finding(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve", ['back_url' => $f['back_url']]);

        $this->actingAs($f['owner'])->patch(
            "/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/unapprove",
            ['back_url' => $f['back_url']],
        )->assertSessionHas('success', 'Beslutningen er angret. Påstanden må vurderes på nytt. QA-funnet er åpent igjen.');

        $this->assertTrue($f['claim']->fresh()->isPending());
        $finding = $this->findingFor($this->findings($f), $f['claim']);
        $this->assertSame('open_for_qa_review', $finding['status']);
        $this->assertSame('open_and_handle', $finding['action']);
        $this->assertTrue($finding['can_handle']);
    }

    // ── Explicit blocking decisions keep their meaning ──────────────────────

    public function test_an_explicit_blocking_decision_keeps_a_pending_finding_open(): void
    {
        $f = $this->fixture();
        $f['claim']->update(['blocking_override' => true, 'blocking_override_by_user_id' => $f['owner']->id, 'blocking_override_at' => now()]);

        $this->assertSame('flagged_for_review', $this->findingFor($this->findings($f), $f['claim'])['status']);
    }

    public function test_an_explicitly_accepted_deviation_is_handled_like_the_qa_gate_treats_it(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['owner'])->patch(
            "/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/blocking",
            ['blocking' => false, 'back_url' => $f['back_url']],
        )->assertSessionHas('success', 'Avviket er godkjent og blokkerer ikke lenger. QA-funnet er ferdigbehandlet.');

        $finding = $this->findingFor($this->findings($f), $f['claim']);
        $this->assertSame('accepted_deviation', $finding['status']);
        $this->assertSame('Avvik godkjent', $finding['status_label']);
        $this->assertSame(1, $this->findings($f)['summary']['resolved']);
    }

    public function test_a_decision_on_the_text_ends_the_finding_whatever_the_blocking_decision_says(): void
    {
        $f = $this->fixture();
        $f['claim']->update(['blocking_override' => true]);

        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve", ['back_url' => $f['back_url']])
            ->assertSessionHas('success', 'Påstanden er godkjent, og teksten er beholdt. QA-funnet er ferdigbehandlet.');

        $this->assertSame('approved', $this->findingFor($this->findings($f), $f['claim'])['status']);
        // The blocking decision itself is history, never deleted.
        $this->assertTrue($f['claim']->fresh()->blocking_override);
    }

    // ── Scenario F: refusals change nothing ─────────────────────────────────

    public function test_a_second_decision_on_an_already_decided_claim_is_a_conflict_and_changes_nothing(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve", ['comment' => 'Første beslutning.']);

        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/reject", ['comment' => 'Fra en gammel fane.'])
            ->assertStatus(409);

        $claim = $f['claim']->fresh();
        $this->assertTrue($claim->isApproved());
        $this->assertSame('Første beslutning.', $claim->approval_comment);
        $this->assertSame(1, EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
    }

    public function test_a_validation_error_leaves_the_claim_and_its_text_untouched(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['owner'])
            ->from(route('app.wiki.show', ['slug' => $f['page']->slug, 'claim_id' => $f['claim']->id]))
            ->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve", [
                'approved_text' => self::SOURCE_SENTENCE,
                'comment' => str_repeat('x', 1001),
            ])
            ->assertSessionHasErrors('comment')
            ->assertSessionMissing('success');

        $this->assertTrue($f['claim']->fresh()->isPending());
        $this->assertStringContainsString(self::STRENGTHENED, (string) $f['version']->fresh()->content_markdown);
        $this->assertSame('open_for_qa_review', $this->findingFor($this->findings($f), $f['claim'])['status']);
    }

    // ── Scenario G: a new version after editing the article ─────────────────

    public function test_editing_the_findings_paragraph_keeps_the_return_context_and_drops_the_old_finding_from_the_work_list(): void
    {
        $f = $this->fixture();

        $response = $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/working-version", [
            'expected_page_version_id' => $f['version']->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => self::SOURCE_SENTENCE]],
            'claim_id' => $f['claim']->id,
            'back_url' => $f['back_url'],
        ]);

        // The original claim id stays: the edit replaced its text, so nothing is carried over.
        $response->assertRedirect(route('app.wiki.show', ['slug' => $f['page']->slug, 'claim_id' => $f['claim']->id, 'back_url' => $f['back_url']]));
        $this->assertNotSame($f['version']->id, (int) EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $f['page']->id)->where('is_current', true)->value('id'));

        $show = $this->actingAs($f['owner'])->get(route('app.wiki.show', ['slug' => $f['page']->slug, 'claim_id' => $f['claim']->id, 'back_url' => $f['back_url']]));
        $this->assertSame('superseded', data_get($show->viewData('page'), 'props.review_reference.status'));
        $this->assertSame(1, data_get($show->viewData('page'), 'props.review_reference.version_number'));
        $this->assertSame($f['back_url'], data_get($show->viewData('page'), 'props.review_reference.back_url'));

        // The active work list is about the current version: the replaced claim is no task, is
        // counted neither open nor resolved, and offers no «Åpne og behandle».
        $findings = $this->findings($f);
        $this->assertNull(collect($findings['findings'])->firstWhere('claim_id', $f['claim']->id));
        $this->assertSame([], collect($findings['findings'])->where('status', 'superseded')->values()->all());
        $this->assertSame(0, $findings['summary']['open_qa_review'] + $findings['summary']['open_non_blocking']);
        $this->assertSame(0, $findings['summary']['resolved']);
        $this->assertSame(0, $findings['summary']['superseded']);

        // The run's history is untouched: status, QA result and the claim ids its QA recorded.
        $run = $f['run']->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertSame([$f['claim']->id], data_get($run->qa_result, 'checks.normative_fidelity.open_strengthened_claim_ids'));

        // The original claim and its version are kept as history.
        $this->assertSame($f['version']->id, (int) $f['claim']->fresh()->enterprise_wiki_page_version_id);
    }

    public function test_editing_another_paragraph_follows_the_claim_the_save_copied_forward(): void
    {
        $f = $this->fixture();

        $response = $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/working-version", [
            'expected_page_version_id' => $f['version']->id,
            'blocks' => [['block_key' => 'block-0002', 'markdown' => 'Den som ledet hendelsen, eier og leder gjennomgangen.']],
            'claim_id' => $f['claim']->id,
            'back_url' => $f['back_url'],
        ]);

        $copy = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_id', $f['page']->id)
            ->where('content_block_key', 'block-0001')
            ->where('id', '!=', $f['claim']->id)
            ->firstOrFail();

        $response->assertRedirect(route('app.wiki.show', ['slug' => $f['page']->slug, 'claim_id' => $copy->id, 'back_url' => $f['back_url']]));

        // One finding in Kjøringer: the copy on the current version, still open.
        $rows = collect($this->findings($f)['findings'])->whereIn('claim_id', [$f['claim']->id, $copy->id]);
        $this->assertCount(1, $rows);
        $this->assertSame($copy->id, $rows->first()['claim_id']);
        $this->assertSame('open_for_qa_review', $rows->first()['status']);
    }

    public function test_a_back_url_off_this_application_is_never_carried_through_the_save(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/working-version", [
            'expected_page_version_id' => $f['version']->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => self::SOURCE_SENTENCE]],
            'claim_id' => $f['claim']->id,
            'back_url' => 'https://evil.example.com/app/wiki?tab=runs',
        ])->assertRedirect(route('app.wiki.show', ['slug' => $f['page']->slug]));
    }

    // ── Scenario H: historically escalated, follow-up done ──────────────────

    public function test_an_escalated_run_keeps_its_history_while_its_qa_follow_up_completes(): void
    {
        $f = $this->fixture();

        $before = $this->findings($f)['summary'];
        $this->assertSame('open', $before['qa_follow_up']);
        $this->assertSame(__('procynia.wiki.runs_findings_explanation_quality.normative_strengthening'), $before['explanation']);

        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve", ['back_url' => $f['back_url']]);

        $after = $this->findings($f)['summary'];
        $this->assertSame('complete', $after['qa_follow_up']);
        $this->assertSame(__('procynia.wiki.runs_findings_explanation_follow_up_complete'), $after['explanation']);

        // What happened during the run is not rewritten.
        $run = $f['run']->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);

        // The Kjøringer row carries the follow-up state beside the historical status.
        $runs = $this->actingAs($f['owner'])->get('/app/wiki?tab=runs');
        $row = collect(data_get($runs->viewData('page'), 'props.runs'))->firstWhere('id', $f['run']->id);
        $this->assertSame('escalated', $row['status']);
        $this->assertSame('complete', $row['qa_follow_up']);
    }

    public function test_a_run_escalated_on_another_quality_finding_keeps_its_follow_up_open(): void
    {
        $f = $this->fixture(qualityFindings: [
            EnterpriseWikiPostIngestQaService::FINDING_NORMATIVE_STRENGTHENING,
            EnterpriseWikiPostIngestQaService::FINDING_SOURCE_SECTION_UNCOVERED,
        ]);

        $this->actingAs($f['owner'])->patch("/app/wiki/{$f['page']->slug}/claims/{$f['claim']->id}/approve", ['back_url' => $f['back_url']]);

        $this->assertSame('open', $this->findings($f)['summary']['qa_follow_up']);
    }

    public function test_a_run_that_did_not_escalate_reports_no_follow_up(): void
    {
        $f = $this->fixture();
        $f['run']->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED, 'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED]);

        $this->assertNull($this->findings($f)['summary']['qa_follow_up']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * One escalated run, one article with a strengthened (open) and a faithful paragraph, and the
     * strengthened paragraph's claim recorded by the run's QA — the state finding #472 was in.
     *
     * @param  list<string>  $qualityFindings
     * @return array{customer: Customer, owner: User, document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun, page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion, claim: EnterpriseWikiClaim, back_url: string}
     */
    private function fixture(array $qualityFindings = [EnterpriseWikiPostIngestQaService::FINDING_NORMATIVE_STRENGTHENING]): array
    {
        $customer = $this->createWikiCustomer('QA-arbeidsflyt testkunde');
        $owner = User::query()->create([
            'name' => 'QA Owner',
            'email' => Str::lower(Str::random(10)).'@qa-workflow-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'qa-arbeidsflyt.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => self::SOURCE_SENTENCE."\n\n".self::FAITHFUL,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        $blocks = [
            $this->sourceBlock('block-0001', 0, self::STRENGTHENED, $document, 'paragraph-1', self::SOURCE_SENTENCE),
            $this->sourceBlock('block-0002', 1, self::FAITHFUL, $document, 'paragraph-2', self::FAITHFUL),
        ];
        $page = $this->createWikiPage($customer, 'Forbedringssløyfe');
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => self::STRENGTHENED."\n\n".self::FAITHFUL,
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => self::STRENGTHENED,
            'page_excerpt' => self::STRENGTHENED,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => 'normative_strengthening',
            ],
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => self::SOURCE_SENTENCE,
        ]);
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => self::FAITHFUL,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'content_block_key' => 'block-0002',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ])->sourceReferences()->create([
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => self::FAITHFUL,
        ]);

        // What the run's own QA recorded when it escalated (EnterpriseWikiPostIngestQaService).
        $run->update(['qa_result' => [
            'quality_findings' => $qualityFindings,
            'checks' => ['normative_fidelity' => ['open_strengthened_claim_ids' => [$claim->id], 'ambiguous_claim_ids' => []]],
        ]]);

        return [
            'customer' => $customer,
            'owner' => $owner,
            'document' => $document,
            'run' => $run->fresh(),
            'page' => $page,
            'version' => $version,
            'claim' => $claim,
            'back_url' => route('app.wiki.index', [
                'tab' => 'runs',
                'run_src' => $document->id,
                'focus_run' => $run->id,
                'focus_finding' => 'claim-defect-'.$claim->id,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function sourceBlock(string $key, int $position, string $markdown, EnterpriseWikiDocument $document, string $elementKey, string $excerpt): array
    {
        return [
            'block_key' => $key,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_elements' => [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_element_key' => $elementKey,
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_excerpt' => $excerpt,
                'page_reference' => null,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{findings: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function findings(array $f): array
    {
        $response = $this->actingAs($f['owner'])->getJson("/app/wiki/runs/{$f['run']->id}/findings");
        $response->assertOk();

        return $response->json();
    }

    /**
     * @param  array{findings: list<array<string, mixed>>}  $findings
     * @return array<string, mixed>
     */
    private function findingFor(array $findings, EnterpriseWikiClaim $claim): array
    {
        $finding = collect($findings['findings'])->firstWhere('claim_id', $claim->id);
        $this->assertNotNull($finding, 'The claim should be listed as a finding.');

        return $finding;
    }
}
