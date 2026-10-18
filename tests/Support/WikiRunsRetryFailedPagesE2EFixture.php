<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Str;

/**
 * Test-only fixture for tests/e2e/wiki-runs-retry-failed-pages.spec.js: two partially completed,
 * escalated runs (arbeidspakke A, docs/enterprise-wiki-knowledge-integration-plan.md §4.2) — one
 * whose article failed transiently, which offers «Prøv igjen for feilede sider», and one whose
 * article failed on a plan defect (Wiki run 26), which never does. The spec intercepts the retry
 * request itself, so nothing here is ever dispatched.
 */
class WikiRunsRetryFailedPagesE2EFixture
{
    private const TRANSIENT_FILENAME = 'E2E Retry Failed Pages Transient.docx';

    private const PLAN_DEFECT_FILENAME = 'E2E Retry Failed Pages Plan Defect.docx';

    private const SLUG_PREFIX = 'e2e-retry-failed-pages-';

    /**
     * @return array{transient_run_id: int, plan_defect_run_id: int}
     */
    public static function seed(int $customerId): array
    {
        self::cleanup($customerId);

        return [
            'transient_run_id' => self::createPartiallyCompletedRun(
                $customerId,
                self::TRANSIENT_FILENAME,
                '[RuntimeException] OpenAI request failed: HTTP status [503]: upstream unavailable',
            ),
            'plan_defect_run_id' => self::createPartiallyCompletedRun(
                $customerId,
                self::PLAN_DEFECT_FILENAME,
                '[EnterpriseWikiPlannedSectionEvidenceMissingException] planned_section_no_evidence before generation: Formål.',
            ),
        ];
    }

    private static function createPartiallyCompletedRun(int $customerId, string $filename, string $articleError): int
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => $filename,
            'file_path' => 'e2e-retry-failed-pages/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E retry failed pages content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-retry-failed-pages-'.$filename),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_last_error' => 'Run has unfinished continuation step(s) or an active reservation: page_generation_incomplete.',
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);

        foreach ([
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => [EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $articleError],
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => [EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, null],
        ] as $type => [$status, $error]) {
            $page = EnterpriseWikiPage::query()->create([
                'customer_id' => $customerId,
                'slug' => self::SLUG_PREFIX.$type.'-'.Str::lower(Str::random(6)),
                'title' => 'E2E '.ucfirst($type),
                'page_type' => $type,
                'status' => EnterpriseWikiPage::STATUS_DRAFT,
                'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                'last_source_hash' => str_pad('e2e', 64, '0'),
            ]);

            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => $status,
                'generation_error' => $error,
            ]);
        }

        return $run->id;
    }

    public static function cleanup(int $customerId): void
    {
        $documentIds = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('original_filename', [self::TRANSIENT_FILENAME, self::PLAN_DEFECT_FILENAME])
            ->pluck('id');

        $runIds = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('source_id', $documentIds)
            ->pluck('id');

        EnterpriseWikiIngestRunPage::query()->whereIn('enterprise_wiki_ingest_run_id', $runIds)->delete();
        EnterpriseWikiIngestRun::query()->whereIn('id', $runIds)->delete();
        EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->delete();
        EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->delete();
    }
}
