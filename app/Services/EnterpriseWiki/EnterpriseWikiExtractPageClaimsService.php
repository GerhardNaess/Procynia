<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Support\Facades\DB;

/**
 * Extracts claims from generated EnterpriseWikiPageVersion.content_markdown records
 * linked to an applied maintainer decision run, and writes EnterpriseWikiClaim rows.
 *
 * Idempotency checkpoint: EnterpriseWikiIngestRunPage.claims_extracted_at, set once per
 * (run, page) — not merely "claims exist" — because a page can legitimately extract zero
 * claims, and an existence check alone cannot distinguish "not started" from "started and
 * genuinely produced nothing". The AI call happens outside any transaction; writing the
 * claim rows and the checkpoint together inside one transaction guarantees a crash between
 * the AI call and persistence leaves no partial claim set for that page to be mistaken for
 * a completed extraction on the next run.
 *
 * Does not create source references, lint findings, or touch ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiExtractPageClaimsService
{
    public function __construct(
        private readonly WikiPageClaimExtractionAiClient $aiClient,
    ) {}

    /**
     * @return array{pages: int, claims: int, skipped: int}
     *
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

        $pages = 0;
        $claims = 0;
        $skipped = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            // Authoritative checkpoint: this (run, page) already completed extraction —
            // regardless of whether it produced any claims — so skip without an AI call.
            if ($row->claims_extracted_at !== null) {
                $skipped++;

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

            // Defensive fallback for rows that already have claims but predate the
            // checkpoint column being set (e.g. an interrupted write from before this
            // checkpoint existed): record the checkpoint instead of calling AI again.
            $hasExistingClaims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists();

            if ($hasExistingClaims) {
                $row->update(['claims_extracted_at' => now()]);
                $skipped++;

                continue;
            }

            $result = $this->aiClient->extractClaims(
                pageTitle: $page->title,
                pageType: $page->page_type,
                contentMarkdown: (string) ($version->content_markdown ?? ''),
                languageCode: $languageCode,
            );

            $pageClaimsCreated = DB::transaction(function () use ($page, $version, $row, $result): int {
                $created = 0;

                foreach ($result['claims'] as $i => $claim) {
                    EnterpriseWikiClaim::query()->create([
                        'enterprise_wiki_page_id' => $page->id,
                        'enterprise_wiki_page_version_id' => $version->id,
                        'claim_text' => $claim['text'],
                        'position_order' => $i,
                        'confidence' => $claim['confidence'] ?? EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                        'conflict_flag' => ($claim['conflict_note'] ?? null) !== null,
                        'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                    ]);

                    $created++;
                }

                $row->update(['claims_extracted_at' => now()]);

                return $created;
            });

            $claims += $pageClaimsCreated;
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
