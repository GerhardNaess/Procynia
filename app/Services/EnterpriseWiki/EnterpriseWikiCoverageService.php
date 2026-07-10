<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Facades\DB;

class EnterpriseWikiCoverageService
{
    public function computeForCustomer(int $customerId): array
    {
        return [
            'source_coverage' => $this->computeSourceCoverage($customerId),
            'page_quality'    => $this->computePageQuality($customerId),
            'claim_coverage'  => $this->computeClaimCoverage($customerId),
            'lint'            => $this->computeLint($customerId),
        ];
    }

    /**
     * Kildedekning: for hvert extracted document, finn hvilke page_types
     * som finnes via lineage Document → IngestRun → run_pages → Page.
     */
    private function computeSourceCoverage(int $customerId): array
    {
        $docs = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED)
            ->orderBy('created_at')
            ->get();

        if ($docs->isEmpty()) {
            return [
                'extracted_documents'            => 0,
                'documents_with_applied_run'     => 0,
                'documents_with_article'         => 0,
                'documents_with_summary'         => 0,
                'documents_with_article_content' => 0,
                'documents_with_summary_content' => 0,
                'gaps'                           => [],
            ];
        }

        $docIds = $docs->pluck('id');

        // All applied runs for these documents
        $appliedRuns = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('source_id', $docIds)
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('source_id');

        $allAppliedRunIds = $appliedRuns->flatten()->pluck('id');

        // Pages via run_pages pivot (batch — no per-doc N+1)
        $runPageLinks = $allAppliedRunIds->isNotEmpty()
            ? DB::table('enterprise_wiki_ingest_run_pages')
                ->whereIn('enterprise_wiki_ingest_run_id', $allAppliedRunIds)
                ->get()
                ->groupBy('enterprise_wiki_ingest_run_id')
            : collect();

        $allPageIds = $runPageLinks->flatten()->pluck('enterprise_wiki_page_id')->unique();

        $pageTypeById = $allPageIds->isNotEmpty()
            ? EnterpriseWikiPage::query()
                ->whereIn('id', $allPageIds)
                ->pluck('page_type', 'id')
            : collect();

        // Batch-fetch current versions for all linked pages
        $pageVersionInfo = $allPageIds->isNotEmpty()
            ? DB::table('enterprise_wiki_page_versions')
                ->whereIn('enterprise_wiki_page_id', $allPageIds)
                ->where('is_current', true)
                ->select('enterprise_wiki_page_id', 'content_markdown')
                ->get()
                ->keyBy('enterprise_wiki_page_id')
            : collect();

        $docsWithAppliedRun         = 0;
        $docsWithArticle            = 0;
        $docsWithSummary            = 0;
        $docsWithArticleContent     = 0;
        $docsWithSummaryContent     = 0;
        $gaps                       = [];

        foreach ($docs as $doc) {
            $docRuns = $appliedRuns->get($doc->id, collect());

            if ($docRuns->isEmpty()) {
                $gaps[] = [
                    'document_id' => $doc->id,
                    'filename'    => $doc->original_filename,
                    'missing'     => ['applied_run'],
                ];
                continue;
            }

            $docsWithAppliedRun++;

            // Collect page IDs by type across all applied runs for this document
            $articlePageIds = [];
            $summaryPageIds = [];
            foreach ($docRuns as $run) {
                foreach ($runPageLinks->get($run->id, collect()) as $link) {
                    $type = $pageTypeById->get($link->enterprise_wiki_page_id);
                    if ($type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE) {
                        $articlePageIds[] = $link->enterprise_wiki_page_id;
                    } elseif ($type === EnterpriseWikiPage::PAGE_TYPE_SUMMARY) {
                        $summaryPageIds[] = $link->enterprise_wiki_page_id;
                    }
                }
            }

            $hasArticle = ! empty($articlePageIds);
            $hasSummary = ! empty($summaryPageIds);

            // Content depth: any page of the type with a current version + content suffices
            $articleHasVersion = false;
            $articleHasContent = false;
            foreach ($articlePageIds as $pageId) {
                $v = $pageVersionInfo->get($pageId);
                if ($v !== null) {
                    $articleHasVersion = true;
                    if (! empty($v->content_markdown)) {
                        $articleHasContent = true;
                    }
                }
            }

            $summaryHasVersion = false;
            $summaryHasContent = false;
            foreach ($summaryPageIds as $pageId) {
                $v = $pageVersionInfo->get($pageId);
                if ($v !== null) {
                    $summaryHasVersion = true;
                    if (! empty($v->content_markdown)) {
                        $summaryHasContent = true;
                    }
                }
            }

            if ($hasArticle) {
                $docsWithArticle++;
            }
            if ($hasSummary) {
                $docsWithSummary++;
            }
            if ($articleHasContent) {
                $docsWithArticleContent++;
            }
            if ($summaryHasContent) {
                $docsWithSummaryContent++;
            }

            // Gap: structural first, then version, then content
            $missing = [];
            if (! $hasArticle) {
                $missing[] = 'article_missing';
            } elseif (! $articleHasVersion) {
                $missing[] = 'article_missing_current_version';
            } elseif (! $articleHasContent) {
                $missing[] = 'article_missing_content';
            }

            if (! $hasSummary) {
                $missing[] = 'summary_missing';
            } elseif (! $summaryHasVersion) {
                $missing[] = 'summary_missing_current_version';
            } elseif (! $summaryHasContent) {
                $missing[] = 'summary_missing_content';
            }

            if (! empty($missing)) {
                $gaps[] = [
                    'document_id' => $doc->id,
                    'filename'    => $doc->original_filename,
                    'missing'     => $missing,
                ];
            }
        }

        return [
            'extracted_documents'            => $docs->count(),
            'documents_with_applied_run'     => $docsWithAppliedRun,
            'documents_with_article'         => $docsWithArticle,
            'documents_with_summary'         => $docsWithSummary,
            'documents_with_article_content' => $docsWithArticleContent,
            'documents_with_summary_content' => $docsWithSummaryContent,
            'gaps'                           => $gaps,
        ];
    }

    private function computePageQuality(int $customerId): array
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->withCount('claims')
            ->get();

        $total      = $pages->count();
        $pageIds    = $pages->pluck('id');
        $byPageType = $pages->groupBy('page_type')->map->count()->toArray();
        $byStatus   = $pages->groupBy('status')->map->count()->toArray();

        if ($pageIds->isEmpty()) {
            return [
                'total'                    => 0,
                'by_page_type'             => [],
                'by_status'                => [],
                'with_current_version'     => 0,
                'without_current_version'  => 0,
                'without_content'          => 0,
                'with_claims'              => 0,
                'without_claims'           => 0,
            ];
        }

        $withCurrentVersion = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->distinct()
            ->count('enterprise_wiki_page_id');

        $withContent = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->distinct()
            ->count('enterprise_wiki_page_id');

        $withClaims    = $pages->filter(fn ($p) => $p->claims_count > 0)->count();
        $withoutClaims = $total - $withClaims;

        return [
            'total'                   => $total,
            'by_page_type'            => $byPageType,
            'by_status'               => $byStatus,
            'with_current_version'    => $withCurrentVersion,
            'without_current_version' => $total - $withCurrentVersion,
            'without_content'         => $total - $withContent,
            'with_claims'             => $withClaims,
            'without_claims'          => $withoutClaims,
        ];
    }

    private function computeClaimCoverage(int $customerId): array
    {
        $pageIds = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->pluck('id');

        if ($pageIds->isEmpty()) {
            return $this->emptyClaims();
        }

        $claimIds = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->pluck('id');

        $total = $claimIds->count();

        if ($total === 0) {
            return $this->emptyClaims();
        }

        $claimsWithSource = EnterpriseWikiSourceReference::query()
            ->whereIn('enterprise_wiki_claim_id', $claimIds)
            ->whereNotNull('enterprise_wiki_claim_id')
            ->distinct('enterprise_wiki_claim_id')
            ->count('enterprise_wiki_claim_id');

        return [
            'claims_total'                  => $total,
            'claims_with_source_reference'  => $claimsWithSource,
            'claims_without_source_reference' => $total - $claimsWithSource,
            'claim_coverage_pct'            => round($claimsWithSource / $total * 100, 1),
        ];
    }

    private function emptyClaims(): array
    {
        return [
            'claims_total'                    => 0,
            'claims_with_source_reference'    => 0,
            'claims_without_source_reference' => 0,
            'claim_coverage_pct'              => null,
        ];
    }

    private function computeLint(int $customerId): array
    {
        $lintCounts = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $customerId)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->selectRaw('severity, count(*) as cnt')
            ->groupBy('severity')
            ->pluck('cnt', 'severity');

        $pageIds = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->pluck('id');

        $orphanCount = 0;
        if ($pageIds->isNotEmpty()) {
            $linkedToIds = EnterpriseWikiPageLink::query()
                ->whereIn('to_page_id', $pageIds)
                ->pluck('to_page_id')
                ->unique();

            $orphanCount = $pageIds->diff($linkedToIds)->count();
        }

        return [
            'open_errors'   => (int) ($lintCounts[EnterpriseWikiLintFinding::SEVERITY_ERROR]   ?? 0),
            'open_warnings' => (int) ($lintCounts[EnterpriseWikiLintFinding::SEVERITY_WARNING] ?? 0),
            'open_info'     => (int) ($lintCounts[EnterpriseWikiLintFinding::SEVERITY_INFO]    ?? 0),
            'orphan_pages'  => $orphanCount,
        ];
    }
}
