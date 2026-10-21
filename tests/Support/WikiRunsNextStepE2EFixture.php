<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use Illuminate\Support\Str;

/**
 * Test-only fixture for tests/e2e/wiki-runs-next-step.spec.js: the top of a run's detail view
 * («Hva bør du gjøre?»). Three runs on three documents in the E2E customer:
 *
 *  - review: shaped like Wiki run 963 — escalated, «E2E Incident Commander» held back by its open
 *    review, both QA findings resolved.
 *  - findings: escalated with two open findings and an ordinary failed page.
 *  - completed: finished without findings.
 *  - settled: run 963 as it is now — two new draft articles and «E2E Referert side», published,
 *    which the decision only referenced (`preserve`). Old code recorded that reference as a
 *    failed, deferred update; a later retry sent it to page generation, which refused it. A
 *    document-owner row is still pending on one of the drafts. Nothing is left to do.
 *
 * No AI call and no queue job: the spec intercepts the one retry request it makes.
 */
class WikiRunsNextStepE2EFixture
{
    public const REVIEW_FILENAME = 'E2E Neste steg Gjennomgang.docx';

    public const FINDINGS_FILENAME = 'E2E Neste steg Funn.docx';

    public const COMPLETED_FILENAME = 'E2E Neste steg Fullført.docx';

    public const SETTLED_FILENAME = 'E2E Neste steg Ferdig fulgt opp.docx';

    public const REVIEW_PAGE_TITLE = 'E2E Incident Commander';

    private const SLUG_PREFIX = 'e2e-next-step-';

    private const REVIEW_DEFERRAL_ERROR = '[EnterpriseWikiPageUnderReviewException] Page [0] is in pending_review. An automated change never writes a new working version while a review is open — the update is deferred until the review is closed.';

    /**
     * @return array{review_run_id: int, findings_run_id: int, completed_run_id: int, settled_run_id: int, review_page_slug: string}
     */
    public static function seed(int $customerId): array
    {
        self::cleanup($customerId);

        $review = self::run($customerId, self::REVIEW_FILENAME, EnterpriseWikiIngestRun::STATUS_ESCALATED);
        $reviewPage = self::page($customerId, $review, 'review', self::REVIEW_PAGE_TITLE, EnterpriseWikiPage::STATUS_PENDING_REVIEW, self::REVIEW_DEFERRAL_ERROR);
        $summary = self::page($customerId, $review, 'review-summary', 'E2E Sammendrag', EnterpriseWikiPage::STATUS_DRAFT);
        self::finding($customerId, $review, $summary, EnterpriseWikiLintFinding::STATUS_RESOLVED);
        self::finding($customerId, $review, $summary, EnterpriseWikiLintFinding::STATUS_RESOLVED);

        $findings = self::run($customerId, self::FINDINGS_FILENAME, EnterpriseWikiIngestRun::STATUS_ESCALATED);
        self::page($customerId, $findings, 'findings-article', 'E2E Feilet artikkel', EnterpriseWikiPage::STATUS_DRAFT, '[RuntimeException] OpenAI request failed: HTTP status [503]: upstream unavailable');
        $findingsSummary = self::page($customerId, $findings, 'findings-summary', 'E2E Funnside', EnterpriseWikiPage::STATUS_DRAFT);
        self::finding($customerId, $findings, $findingsSummary, EnterpriseWikiLintFinding::STATUS_OPEN);
        self::finding($customerId, $findings, $findingsSummary, EnterpriseWikiLintFinding::STATUS_OPEN);

        $completed = self::run($customerId, self::COMPLETED_FILENAME, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        self::page($customerId, $completed, 'completed', 'E2E Fullført side', EnterpriseWikiPage::STATUS_DRAFT);

        $settled = self::settledRun($customerId);

        return [
            'review_run_id' => $review->id,
            'findings_run_id' => $findings->id,
            'completed_run_id' => $completed->id,
            'settled_run_id' => $settled->id,
            'review_page_slug' => $reviewPage->slug,
        ];
    }

    private static function settledRun(int $customerId): EnterpriseWikiIngestRun
    {
        $run = self::run($customerId, self::SETTLED_FILENAME, EnterpriseWikiIngestRun::STATUS_ESCALATED);

        $referenced = self::page($customerId, $run, 'settled-referenced', 'E2E Referert side', EnterpriseWikiPage::STATUS_APPROVED, null, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiIngestRunPage::ACTION_PATCHED);
        $referenced->forceFill(['published_version_id' => $referenced->currentVersion()->value('id')])->save();
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $referenced->id)
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
                'generated_page_version_id' => null,
                'generation_error' => '[EnterpriseWikiPatchTargetRegenerationBlockedException] Run names page as a patch target, so it must not be regenerated from source.',
                'link_assessment' => null,
                'links_assessed_at' => null,
            ]);

        $article = self::page($customerId, $run, 'settled-article', 'E2E Ny artikkel', EnterpriseWikiPage::STATUS_DRAFT, null, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        self::page($customerId, $run, 'settled-summary', 'E2E Nytt sammendrag', EnterpriseWikiPage::STATUS_DRAFT, null, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $customerId,
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $article->currentVersion()->value('id'),
            'document_owner_user_id' => null,
            'source_document_ids' => [$run->source_id],
            'source_documents_hash' => hash('sha256', 'e2e-settled'),
            'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
        ]);

        $run->forceFill([
            // qa_last_error stays as run() records it: the historical reason, under «Vis tekniske detaljer».
            'qa_result' => ['incomplete_steps' => ['page_generation_incomplete'], 'critical_defects' => [], 'quality_findings' => []],
            'maintainer_decision_json' => ['patch_targets' => [[
                'target_page_id' => $referenced->id,
                'target_page_title' => $referenced->title,
                'target_page_type' => $referenced->page_type,
                'target_topic' => 'Responstider',
                'target_heading' => 'Beredskap',
                'operation' => 'preserve',
                'relationship' => 'reference_only',
                'superseded_substance' => null,
                'replacement_substance' => null,
                'source_element_keys' => [],
                'preserve_topics' => [],
            ]]],
        ])->save();

        return $run;
    }

    /** The review of «E2E Incident Commander» closed (or reopened) — what a reviewer's decision does to it. */
    public static function setReviewOpen(int $customerId, bool $open): void
    {
        EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', 'like', self::SLUG_PREFIX.'review-%')
            ->where('title', self::REVIEW_PAGE_TITLE)
            ->update(['status' => $open ? EnterpriseWikiPage::STATUS_PENDING_REVIEW : EnterpriseWikiPage::STATUS_APPROVED]);
    }

    private static function run(int $customerId, string $filename, string $status): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => $filename,
            'file_path' => 'e2e-next-step/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E next step content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $escalated = $status === EnterpriseWikiIngestRun::STATUS_ESCALATED;

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-next-step-'.$filename),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
            'qa_status' => $escalated ? EnterpriseWikiIngestRun::QA_STATUS_ESCALATED : EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_last_error' => $escalated
                ? 'Run has unfinished continuation step(s) or an active reservation: page_generation_incomplete, extraction_incomplete.'
                : null,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(2),
        ]);
    }

    private static function page(
        int $customerId,
        EnterpriseWikiIngestRun $run,
        string $key,
        string $title,
        string $status,
        ?string $generationError = null,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        string $action = EnterpriseWikiIngestRunPage::ACTION_UPDATED,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => self::SLUG_PREFIX.$key.'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('e2e', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nE2E-innhold.",
            'content_blocks_json' => [['block_key' => 'block-0001', 'position' => 0, 'markdown' => 'E2E-innhold.', 'content_origin' => 'source_based']],
            'generated_by_model' => 'e2e-next-step-fixture',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
            'generation_status' => $generationError === null
                ? EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED
                : EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
            'generation_error' => $generationError,
            'generated_page_version_id' => $generationError === null ? $version->id : null,
            'claims_extracted_at' => now()->subMinutes(3),
            'link_assessment' => $generationError === null ? ['schema' => 1, 'outcome' => 'no_relevant_links'] : null,
            'links_assessed_at' => $generationError === null ? now()->subMinutes(3) : null,
        ]);

        return $page;
    }

    private static function finding(int $customerId, EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $status): void
    {
        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customerId,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'E2E next step finding',
            'status' => $status,
            'detected_at' => now()->subMinutes(3),
            'resolved_at' => $status === EnterpriseWikiLintFinding::STATUS_RESOLVED ? now() : null,
        ]);
    }

    public static function cleanup(int $customerId): void
    {
        $documentIds = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('original_filename', [self::REVIEW_FILENAME, self::FINDINGS_FILENAME, self::COMPLETED_FILENAME, self::SETTLED_FILENAME])
            ->pluck('id');

        $runIds = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('source_id', $documentIds)
            ->pluck('id');

        $pageIds = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        EnterpriseWikiLintFinding::query()->whereIn('enterprise_wiki_ingest_run_id', $runIds)->delete();
        EnterpriseWikiIngestRunPage::query()->whereIn('enterprise_wiki_ingest_run_id', $runIds)->delete();
        EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', $pageIds)->delete();
        EnterpriseWikiIngestRun::query()->whereIn('id', $runIds)->delete();
        EnterpriseWikiPage::query()->whereIn('id', $pageIds)->delete();
        EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->delete();
    }
}
