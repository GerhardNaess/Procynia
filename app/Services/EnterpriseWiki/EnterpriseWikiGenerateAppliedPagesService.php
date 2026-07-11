<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generates content_markdown and EnterpriseWikiPageVersion records for all four wiki page types
 * (article, summary, concept, entity) linked to an applied maintainer decision run.
 *
 * Processing order: article and summary first, then concept and entity — so article/summary
 * content is available as context when generating concept/entity pages.
 * Idempotent: pages that already have any version record are skipped.
 * Does not touch claims, source references, or ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiGenerateAppliedPagesService
{
    private const ARTICLE_SUMMARY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
    ];

    private const CONCEPT_ENTITY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    public function __construct(
        private readonly WikiPageContentAiClient $aiClient,
    ) {}

    /**
     * @return array{article: int, summary: int, concept: int, entity: int, skipped: int}
     * @throws \InvalidArgumentException if the run is not in a state that permits generation
     * @throws \RuntimeException if AI is unavailable or generation fails
     */
    public function generate(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have pages generated."
            );
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] source_type is not enterprise_wiki_document."
            );
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new \InvalidArgumentException(
                "Source document [{$run->source_id}] not found for customer [{$run->customer_id}]."
            );
        }

        $sourceText   = (string) ($document->extracted_text ?? '');
        $languageCode = $this->resolveLanguageCode($run->customer_id);
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $counts = [
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 0,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY  => 0,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT  => 0,
            EnterpriseWikiPage::PAGE_TYPE_ENTITY   => 0,
        ];
        $skipped               = 0;
        $articleSummaryPageIds = [];

        // --- Pass 1: article and summary ---
        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null || ! in_array($page->page_type, self::ARTICLE_SUMMARY_TYPES, true)) {
                continue;
            }

            $articleSummaryPageIds[] = $page->id;

            if ($this->pageHasVersion($page->id)) {
                $skipped++;
                continue;
            }

            $markdown = $this->aiClient->generateFromSource(
                pageTitle:    $page->title,
                pageType:     $page->page_type,
                sourceText:   $sourceText,
                languageCode: $languageCode,
            );

            $this->writeVersion($page->id, $markdown);
            $counts[$page->page_type]++;
        }

        // Load article/summary content to use as context in concept/entity generation
        $sharedContext = $this->loadSharedContext($articleSummaryPageIds);

        // --- Pass 2: concept and entity ---
        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null || ! in_array($page->page_type, self::CONCEPT_ENTITY_TYPES, true)) {
                continue;
            }

            if ($this->pageHasVersion($page->id)) {
                $skipped++;
                continue;
            }

            $additionalContext = $this->buildConceptEntityContext($page, $decisionJson, $sharedContext);

            $markdown = $this->aiClient->generateFromSource(
                pageTitle:         $page->title,
                pageType:          $page->page_type,
                sourceText:        $sourceText,
                languageCode:      $languageCode,
                additionalContext: $additionalContext,
            );

            $this->writeVersion($page->id, $markdown);
            $counts[$page->page_type]++;
        }

        return array_merge($counts, ['skipped' => $skipped]);
    }

    /**
     * Generate content_markdown and an EnterpriseWikiPageVersion for a single applied page
     * belonging to a run, without touching any other page.
     *
     * Idempotent per run/page pair via the enterprise_wiki_ingest_run_pages pivot: once
     * generated_page_version_id is set for this run/page, subsequent calls are a no-op. This
     * intentionally differs from generate()'s pageHasVersion() check (any version, any run) so
     * that a new run can regenerate a page even though an older run already produced a version.
     *
     * @throws InvalidArgumentException if the run is not in a state that permits generation,
     *                                   or the page is not linked to the run
     * @throws \RuntimeException if AI is unavailable or generation fails
     */
    public function generatePageForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have pages generated."
            );
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] source_type is not enterprise_wiki_document."
            );
        }

        $claimed = DB::transaction(function () use ($run, $page): ?EnterpriseWikiIngestRunPage {
            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw new InvalidArgumentException(
                    "Page [{$page->id}] is not linked to run [{$run->id}]."
                );
            }

            if ($pivot->generated_page_version_id !== null) {
                return null;
            }

            $pivot->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
                'generation_started_at' => now(),
                'generation_error' => null,
            ]);

            return $pivot;
        });

        if ($claimed === null) {
            return;
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new InvalidArgumentException(
                "Source document [{$run->source_id}] not found for customer [{$run->customer_id}]."
            );
        }

        $sourceText   = (string) ($document->extracted_text ?? '');
        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $additionalContext = in_array($page->page_type, self::CONCEPT_ENTITY_TYPES, true)
            ? $this->buildConceptEntityContextForRun($run, $page)
            : '';

        $markdown = $this->aiClient->generateFromSource(
            pageTitle:         $page->title,
            pageType:          $page->page_type,
            sourceText:        $sourceText,
            languageCode:      $languageCode,
            additionalContext: $additionalContext,
        );

        DB::transaction(function () use ($run, $page, $markdown): void {
            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null || $pivot->generated_page_version_id !== null) {
                // Another worker already registered a version for this run/page — discard this result.
                return;
            }

            $version = $this->writeNewCurrentVersion($page->id, $markdown);

            $pivot->update([
                'generated_page_version_id' => $version->id,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'generation_completed_at' => now(),
                'generation_error' => null,
            ]);
        });
    }

    private function buildConceptEntityContextForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): string
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        $articleSummaryPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', self::ARTICLE_SUMMARY_TYPES))
            ->pluck('enterprise_wiki_page_id');

        $sharedContext = $this->loadSharedContext($articleSummaryPageIds->all());

        return $this->buildConceptEntityContext($page, $decisionJson, $sharedContext);
    }

    private function writeNewCurrentVersion(int $pageId, string $markdown): EnterpriseWikiPageVersion
    {
        $next = ((int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->max('version_number')) + 1;

        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pageId,
            'version_number'          => $next,
            'is_current'              => true,
            'content_markdown'        => $markdown,
            'generated_by_model'      => WikiPageContentAiClient::MODEL,
        ]);
    }

    private function pageHasVersion(int $pageId): bool
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->exists();
    }

    private function writeVersion(int $pageId, string $markdown): void
    {
        $next = ((int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->max('version_number')) + 1;

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pageId,
            'version_number'          => $next,
            'is_current'              => true,
            'content_markdown'        => $markdown,
            'generated_by_model'      => WikiPageContentAiClient::MODEL,
        ]);
    }

    private function loadSharedContext(array $pageIds): string
    {
        if (empty($pageIds)) {
            return '';
        }

        return EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('content_markdown')
            ->filter()
            ->implode("\n\n---\n\n");
    }

    private function buildConceptEntityContext(EnterpriseWikiPage $page, array $decisionJson, string $sharedContext): string
    {
        $parts = [];

        $entries = array_merge(
            (array) data_get($decisionJson, 'concept_pages', []),
            (array) data_get($decisionJson, 'entity_pages', []),
        );

        $match = collect($entries)->firstWhere('title', $page->title);

        if ($match !== null && ! empty($match['reason'])) {
            $parts[] = "Maintainer note for this page: {$match['reason']}";
        }

        if ($sharedContext !== '') {
            $parts[] = "Content from related article and summary pages:\n\n{$sharedContext}";
        }

        return implode("\n\n", $parts);
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
