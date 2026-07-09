<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;

/**
 * Extracts claims from generated EnterpriseWikiPageVersion.content_markdown records
 * linked to an applied maintainer decision run, and writes EnterpriseWikiClaim rows.
 *
 * Idempotent: pages whose current version already has claims are skipped.
 * Does not create source references, lint findings, or touch ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiExtractPageClaimsService
{
    public function __construct(
        private readonly WikiPageClaimExtractionAiClient $aiClient,
    ) {}

    /**
     * @return array{pages: int, claims: int, skipped: int}
     * @throws \InvalidArgumentException if the run is not in a state that permits extraction
     * @throws \RuntimeException if AI is unavailable or extraction fails
     */
    public function extract(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have claims extracted."
            );
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $pages   = 0;
        $claims  = 0;
        $skipped = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                $skipped++;
                continue;
            }

            // Idempotency: skip if claims already exist for this version
            $hasExistingClaims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists();

            if ($hasExistingClaims) {
                $skipped++;
                continue;
            }

            $result = $this->aiClient->extractClaims(
                pageTitle:       $page->title,
                pageType:        $page->page_type,
                contentMarkdown: (string) ($version->content_markdown ?? ''),
                languageCode:    $languageCode,
            );

            foreach ($result['claims'] as $i => $claim) {
                EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id'         => $page->id,
                    'enterprise_wiki_page_version_id'  => $version->id,
                    'claim_text'                       => $claim['text'],
                    'position_order'                   => $i,
                    'confidence'                       => $claim['confidence'] ?? EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'conflict_flag'                    => ($claim['conflict_note'] ?? null) !== null,
                    'approval_status'                  => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                ]);

                $claims++;
            }

            $pages++;
        }

        return compact('pages', 'claims', 'skipped');
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
