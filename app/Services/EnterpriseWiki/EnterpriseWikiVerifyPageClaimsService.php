<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use Illuminate\Support\Facades\DB;

/**
 * Verifies existing EnterpriseWikiClaim rows against the originating source document
 * and writes EnterpriseWikiSourceReference rows with verbatim supporting excerpts.
 *
 * Idempotency checkpoint: EnterpriseWikiClaim.verified_at, set once per claim regardless of
 * the verdict — a claim that AI found unsupported never gets a source reference, so "a
 * reference exists" cannot distinguish "not yet verified" from "verified and found
 * unsupported". Without this, every continuation pass would re-call AI for every
 * unsupported claim indefinitely. The AI call happens outside any transaction; writing the
 * reference (when supported) and the checkpoint together inside one transaction guarantees
 * a crash between the AI call and persistence never leaves verified_at set without its
 * reference, or a reference without verified_at.
 *
 * Does not touch claim text, page versions, lint findings, or ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiVerifyPageClaimsService
{
    public function __construct(
        private readonly WikiClaimVerificationAiClient $aiClient,
    ) {}

    /**
     * @return array{pages: int, claims: int, references: int, skipped: int, no_support: int}
     *
     * @throws \InvalidArgumentException if the run is not applied or the source document is missing
     * @throws \RuntimeException if AI is unavailable or verification fails
     */
    public function verify(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have claims verified."
            );
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new \InvalidArgumentException(
                "Source document [{$run->source_id}] not found for run [{$run->id}]."
            );
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);
        $sourceText = (string) ($document->extracted_text ?? '');

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $pages = 0;
        $claims = 0;
        $references = 0;
        $skipped = 0;
        $noSupport = 0;

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
                continue;
            }

            $pageClaims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->get();

            if ($pageClaims->isEmpty()) {
                continue;
            }

            $pages++;

            foreach ($pageClaims as $claim) {
                // Authoritative checkpoint: this claim already completed verification —
                // supported or not — so skip without an AI call.
                if ($claim->verified_at !== null) {
                    $skipped++;

                    continue;
                }

                // Defensive fallback for a claim that already has a reference but predates
                // the checkpoint column being set: record the checkpoint instead of
                // calling AI again.
                $hasRef = EnterpriseWikiSourceReference::query()
                    ->where('enterprise_wiki_claim_id', $claim->id)
                    ->exists();

                if ($hasRef) {
                    $claim->update(['verified_at' => now()]);
                    $skipped++;

                    continue;
                }

                $claims++;

                $result = $this->aiClient->verifyClaim(
                    claimText: $claim->claim_text,
                    sourceText: $sourceText,
                    languageCode: $languageCode,
                );

                if (! $result['supported']) {
                    $claim->update(['verified_at' => now()]);
                    $noSupport++;

                    continue;
                }

                DB::transaction(function () use ($claim, $document, $result): void {
                    EnterpriseWikiSourceReference::query()->create([
                        'enterprise_wiki_claim_id' => $claim->id,
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_label' => $document->original_filename,
                        'excerpt' => $result['excerpt'],
                        'source_hash' => $document->file_hash_sha256 ?? '',
                    ]);

                    $claim->update(['verified_at' => now()]);
                });

                $references++;
            }
        }

        return [
            'pages' => $pages,
            'claims' => $claims,
            'references' => $references,
            'skipped' => $skipped,
            'no_support' => $noSupport,
        ];
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
