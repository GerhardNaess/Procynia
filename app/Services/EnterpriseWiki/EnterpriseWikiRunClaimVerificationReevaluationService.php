<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Collection;

/**
 * A narrowly-scoped, single-run re-evaluation for the cross-language/paraphrase claim
 * verification fix — deliberately separate from the broader, customer-wide
 * `wiki:repair-claim-integrity` and from `wiki:reevaluate-run-best-practice-claims` (Del 7 of the
 * best-practice classification fix), which addresses a different, unrelated problem.
 *
 * Only ever re-checks claims currently unsupported_generated_content, anchored to a source_based
 * block in the page's CURRENT version — never touches best_practice, internal_error, or already-
 * source_based claims (Del 1 found the anchor/internal_error bucket to be an unrelated defect,
 * and best-practice classification is explicitly out of scope for this task). Delegates the
 * actual re-verification (AI call + deterministic safety net + claim update) entirely to
 * EnterpriseWikiVerifyPageClaimsService::reevaluateClaimForRun() — this service only scopes the
 * run's claims and aggregates the before/after distribution; it never re-implements verification.
 *
 * Read-only by default; only writes when explicitly told to apply. Never regenerates pages,
 * page versions, or claims, and never runs against any run other than the one given.
 */
class EnterpriseWikiRunClaimVerificationReevaluationService
{
    public function __construct(
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyService,
    ) {}

    /**
     * @return array{
     *     run_id: int, applied: bool,
     *     before: array<string, int>, after: array<string, int>,
     *     checked: int, newly_supported: int, still_partially_supported: int,
     *     still_contradicted: int, still_not_supported: int, deterministic_conflicts: int,
     *     skipped: array<string, int>,
     *     candidates: list<array{claim_id: int, page_id: int, ai_verdict: string, final_verdict: string, deterministic_override: bool, reason: string}>
     * }
     */
    public function reevaluate(EnterpriseWikiIngestRun $run, bool $apply): array
    {
        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $currentVersionIds = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id');

        $allClaims = $currentVersionIds->isEmpty()
            ? collect()
            : EnterpriseWikiClaim::query()
                ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
                ->get();

        $before = $this->distribution($allClaims);

        $checked = 0;
        $newlySupported = 0;
        $stillPartiallySupported = 0;
        $stillContradicted = 0;
        $stillNotSupported = 0;
        $deterministicConflicts = 0;
        $skipped = [];
        $candidates = [];

        $unsupportedClaims = $allClaims
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT)
            ->sortBy('id');

        foreach ($unsupportedClaims as $claim) {
            $result = $this->verifyService->reevaluateClaimForRun($claim, $run, $apply);

            if (! $result['eligible']) {
                $reason = (string) $result['skipped_reason'];
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $checked++;

            if ($result['deterministic_override']) {
                $deterministicConflicts++;
            }

            match ($result['final_verdict']) {
                'supported' => $newlySupported++,
                'partially_supported' => $stillPartiallySupported++,
                'contradicted' => $stillContradicted++,
                default => $stillNotSupported++,
            };

            $candidates[] = [
                'claim_id' => $claim->id,
                'page_id' => $claim->enterprise_wiki_page_id,
                'ai_verdict' => $result['ai_verdict'],
                'final_verdict' => $result['final_verdict'],
                'deterministic_override' => $result['deterministic_override'],
                'reason' => (string) $result['reason'],
            ];
        }

        $after = $apply
            ? $this->distribution(EnterpriseWikiClaim::query()->whereIn('id', $allClaims->pluck('id'))->get())
            : $before;

        return [
            'run_id' => $run->id,
            'applied' => $apply,
            'before' => $before,
            'after' => $after,
            'checked' => $checked,
            'newly_supported' => $newlySupported,
            'still_partially_supported' => $stillPartiallySupported,
            'still_contradicted' => $stillContradicted,
            'still_not_supported' => $stillNotSupported,
            'deterministic_conflicts' => $deterministicConflicts,
            'skipped' => $skipped,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  Collection<int, EnterpriseWikiClaim>  $claims
     * @return array<string, int>
     */
    private function distribution($claims): array
    {
        return $claims
            ->groupBy('content_origin')
            ->map(fn ($group) => $group->count())
            ->sortKeys()
            ->all();
    }
}
