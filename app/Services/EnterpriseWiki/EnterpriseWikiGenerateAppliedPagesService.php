<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiPageContentAiClient;

/**
 * Generates content_markdown and EnterpriseWikiPageVersion records for article and summary
 * pages linked to an applied maintainer decision run.
 *
 * Idempotent: pages that already have any version record are skipped.
 * Does not touch concept/entity pages, claims, source references, or ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiGenerateAppliedPagesService
{
    private const ELIGIBLE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
    ];

    public function __construct(
        private readonly WikiPageContentAiClient $aiClient,
    ) {}

    /**
     * @return array{generated: int, skipped: int}
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

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $generated = 0;
        $skipped   = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            if (! in_array($page->page_type, self::ELIGIBLE_TYPES, true)) {
                continue;
            }

            $hasVersion = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->exists();

            if ($hasVersion) {
                $skipped++;
                continue;
            }

            $markdown = $this->aiClient->generateFromSource(
                pageTitle:    $page->title,
                pageType:     $page->page_type,
                sourceText:   $sourceText,
                languageCode: $languageCode,
            );

            $nextVersionNumber = ((int) EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->max('version_number')) + 1;

            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number'          => $nextVersionNumber,
                'is_current'              => true,
                'content_markdown'        => $markdown,
                'generated_by_model'      => WikiPageContentAiClient::MODEL,
            ]);

            $generated++;
        }

        return compact('generated', 'skipped');
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
