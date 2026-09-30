<?php

namespace App\Services\Ted;

use App\Models\WatchProfile;
use App\Services\BidWorkflowNotificationService;
use App\Services\Doffin\DoffinImportControlService;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunityStatus;
use App\Services\OpportunitySources\WatchProfileInboxDiscoveryService;
use App\Services\OpportunitySources\WatchProfileRelevanceScorer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The nightly sweep, against TED.
 *
 * The same watch profiles, the same scoring, the same inbox — a record from here is a record like
 * any other, found by (source, external_id) with source 'ted'. What is different is everything the
 * base class asks a subclass about, and each difference is a consequence of TED being the whole
 * EU rather than one country's register.
 *
 * WHAT "STILL OPEN" MEANS HERE.
 *
 * Doffin says ACTIVE. TED has no status field at all, so a contract notice is not evidence that
 * anything is still open — it is evidence that a call was published, which was equally true of a
 * procurement that closed six weeks ago. Two things must therefore hold: the document must be a
 * contract notice (cn-*, never an award or a prior information notice), and the deadline for
 * requests must not have passed.
 *
 * A notice with no deadline Procynia can read is excluded. That is the conservative direction and
 * it is chosen deliberately: the cost of excluding a live opportunity is that a bid manager does
 * not see it in the inbox and may find it in live search, while the cost of including a closed one
 * is a watch inbox that quietly fills with work nobody can win. Each exclusion is logged with its
 * publication number, so "TED rarely gives us deadlines" is a thing somebody can discover and act
 * on rather than a silence.
 *
 * WHY IT IS OFF UNTIL SOMEBODY TURNS IT ON.
 *
 * Doffin's sweep covers one country; this one covers twenty-seven. Enabling it for every existing
 * watch profile on the day it is deployed would change what real people see in their inbox tomorrow
 * morning without anybody having decided to. TED_WATCH_DISCOVERY_ENABLED is that decision, and the
 * coordinator reports a skipped run rather than pretending there was nothing to do.
 */
class TedWatchProfileInboxDiscoveryService extends WatchProfileInboxDiscoveryService
{
    public function __construct(
        TedSourceAdapter $sourceAdapter,
        WatchProfileRelevanceScorer $relevance,
        BidWorkflowNotificationService $notifications,
        private readonly DoffinImportControlService $watchDiscoverySwitches,
    ) {
        parent::__construct($sourceAdapter, $relevance, $notifications);
    }

    protected function logChannel(): string
    {
        return '[TED][watch-inbox]';
    }

    /**
     * Three switches, checked cheapest first.
     *
     * The admin toggle is Procynia's master switch for nightly watch discovery. It lives in a
     * Doffin-named setting because Doffin was the only source when it was built; an operator who
     * has turned watch discovery off has turned it off, and honouring that matters more than the
     * name of the column it is stored in. Renaming that setting is a migration, and this phase has
     * none.
     */
    protected function skipReason(): ?string
    {
        if (! (bool) config('ted.watch_discovery.enabled', false)) {
            return 'source_disabled';
        }

        if (! $this->watchDiscoverySwitches->isWatchInboxDiscoveryAdminEnabled()) {
            return 'admin_disabled';
        }

        if (! filled(config('ted.search_url'))) {
            return 'api_missing';
        }

        return null;
    }

    protected function skipReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'source_disabled' => 'TED-bryteren for watch discovery er av.',
            'admin_disabled' => 'Admin-bryteren er av.',
            'api_missing' => 'TED-konfigurasjonen er ufullstendig.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    protected function skipLogContext(string $reason): array
    {
        return [
            'source_enabled' => (bool) config('ted.watch_discovery.enabled', false),
            'admin_enabled' => $reason === 'source_disabled'
                ? null
                : $this->watchDiscoverySwitches->isWatchInboxDiscoveryAdminEnabled(),
            'api_configured' => filled(config('ted.search_url')),
        ];
    }

    /**
     * A profile that names nothing is not a search against TED.
     *
     * Against Doffin an empty profile means "everything published in Norway yesterday", which is
     * scored to zero and stored nowhere. Against TED it means every procurement published in the
     * union yesterday, paged through one page at a time, to store nothing. The profile is skipped
     * and said so.
     */
    protected function profileSkipReason(WatchProfile $watchProfile): ?string
    {
        return $this->relevance->hasSearchableCriteria($watchProfile)
            ? null
            : 'no_keywords_or_cpv_codes';
    }

    /**
     * The profile's own terms, and nothing wider.
     *
     * The adapter turns this into an expert query that ANDs the clauses, so a profile with both
     * keywords and CPV codes asks TED for the intersection. That is narrower than Doffin's reading
     * of the same profile, and narrower is the right error to make against a register this size:
     * the alternative is pulling the union and discarding most of it after the fact.
     */
    protected function buildCriteria(WatchProfile $watchProfile): OpportunitySearchCriteria
    {
        return new OpportunitySearchCriteria(
            keywords: $this->relevance->searchKeywords($watchProfile),
            matchAllKeywords: false,
            cpvCodes: $this->relevance->searchCpvCodes($watchProfile),
            // Contract notices only, which is as close to "open" as TED can be asked.
            status: OpportunityStatus::Open,
            publishedWithinDays: $this->publicationWindowDays(),
        );
    }

    protected function shouldIncludeNotice(WatchProfile $watchProfile, NormalizedNotice $notice): bool
    {
        return $this->hasEligibleStatus($notice)
            && $this->publishedWithinWindow($notice)
            && $this->relevance->score($watchProfile, $notice) > 0;
    }

    /**
     * A live call for offers, established from the two things TED actually provides.
     *
     * Nothing here falls back to Open. An award notice, a prior information notice, a document
     * kind Procynia has not seen before and a notice whose deadline has passed are all excluded by
     * the same rule: a watch inbox entry is a claim that somebody can still bid, and it is only
     * made when the evidence supports it.
     */
    protected function hasEligibleStatus(NormalizedNotice $notice): bool
    {
        if ($notice->status !== OpportunityStatus::Open) {
            return false;
        }

        $deadline = $this->dateTimeOrNull($notice->deadline);

        if (! $deadline instanceof Carbon) {
            Log::info($this->logChannel().' Excluding a contract notice with no readable deadline.', [
                'external_id' => $notice->externalId,
                'notice_type' => $notice->rawPayload['notice-type'] ?? null,
            ]);

            return false;
        }

        // TED gives the day, not the hour, so a deadline falling today is still ahead: the day is
        // not over. Comparing against this moment would close it at one minute past midnight.
        return $deadline->greaterThanOrEqualTo(now()->startOfDay());
    }

    /**
     * TED dates a notice by the day, not the hour, and the query asks for the same boundary.
     *
     * The base class compares against this moment minus the window, which is right for a register
     * that timestamps its publications. Applied to a date-only value it would quietly exclude
     * almost everything published yesterday — a notice dated yesterday parses to yesterday at
     * midnight, which is more than a day ago for every sweep that does not run at midnight. The
     * day is the unit TED gave us, so the day is the unit compared.
     */
    protected function publishedWithinWindow(NormalizedNotice $notice): bool
    {
        $publicationDate = $this->dateTimeOrNull($notice->publicationDate);

        if (! $publicationDate instanceof Carbon) {
            return false;
        }

        return $publicationDate->greaterThanOrEqualTo(
            now()->subDays($this->publicationWindowDays())->startOfDay()
        );
    }

    protected function perPage(): int
    {
        return max(1, (int) config('ted.watch_discovery.per_page', 50));
    }

    /**
     * A hard ceiling on how much one profile may pull.
     *
     * Doffin has none, because a Norwegian day is a known quantity. TED reports totals in the
     * thousands for a broad enough query, and a sweep that pages through all of them would spend
     * the night doing it. Anything beyond the cap is logged with the total, so a profile that
     * routinely overflows is visible as a profile that needs narrowing rather than as a silently
     * truncated result.
     */
    protected function maxPages(): ?int
    {
        return max(1, (int) config('ted.watch_discovery.max_pages', 4));
    }

    protected function publicationWindowDays(): int
    {
        return max(1, (int) config('ted.watch_discovery.window_days', 1));
    }
}
