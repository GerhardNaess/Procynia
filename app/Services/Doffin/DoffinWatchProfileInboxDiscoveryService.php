<?php

namespace App\Services\Doffin;

use App\Models\WatchProfile;
use App\Services\BidWorkflowNotificationService;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunityStatus;
use App\Services\OpportunitySources\WatchProfileInboxDiscoveryService;
use App\Services\OpportunitySources\WatchProfileRelevanceScorer;

/**
 * The nightly Doffin sweep — now only the parts that are actually Doffin's.
 *
 * The run loop, the pagination, the upsert and the notification moved to the base class unchanged,
 * and the relevance scoring moved to WatchProfileRelevanceScorer unchanged. What stays here is the
 * three things a second register answers differently: which switches decide whether it may run,
 * what its criteria look like, and what counts as something somebody can still bid on.
 *
 * Behaviour is identical to before the extraction, down to the log messages and the summary keys.
 */
class DoffinWatchProfileInboxDiscoveryService extends WatchProfileInboxDiscoveryService
{
    public function __construct(
        DoffinSourceAdapter $sourceAdapter,
        WatchProfileRelevanceScorer $relevance,
        BidWorkflowNotificationService $notifications,
        private readonly DoffinImportControlService $importControlService,
    ) {
        parent::__construct($sourceAdapter, $relevance, $notifications);
    }

    protected function logChannel(): string
    {
        return '[DOFFIN][watch-inbox]';
    }

    protected function skipReason(): ?string
    {
        return $this->importControlService->watchInboxDiscoverySkipReason();
    }

    protected function skipReasonLabel(?string $reason): ?string
    {
        return $this->importControlService->watchInboxDiscoverySkipReasonLabel($reason);
    }

    /** @return array<string, mixed> */
    protected function skipLogContext(string $reason): array
    {
        $environmentEnabled = $this->importControlService->isWatchInboxDiscoveryEnvironmentEnabled();
        $adminEnabled = $environmentEnabled ? $this->importControlService->isWatchInboxDiscoveryAdminEnabled() : null;
        $apiConfigured = $environmentEnabled && $adminEnabled
            ? $this->importControlService->hasRequiredWatchInboxDiscoveryApiConfiguration()
            : null;

        return [
            'environment_enabled' => $environmentEnabled,
            'admin_enabled' => $adminEnabled,
            'api_configured' => $apiConfigured,
        ];
    }

    /**
     * What this watch profile is watching for, said without naming a register.
     *
     * The three settings that used to be written in Doffin's own words — keywords_mode 'any',
     * publication_period '1', status 'ACTIVE' — are the same three questions here: match any of
     * the keywords, published in the last day, still open for offers. What changes is that the
     * adapter now decides how to say them, which is what lets a second register answer the same
     * profile without Doffin's parameter names being part of the contract.
     */
    protected function buildCriteria(WatchProfile $watchProfile): OpportunitySearchCriteria
    {
        return new OpportunitySearchCriteria(
            keywords: $this->relevance->searchKeywords($watchProfile),
            // A watch profile casts a net: any keyword is a hit, not all of them.
            matchAllKeywords: false,
            cpvCodes: $this->relevance->searchCpvCodes($watchProfile),
            // Only what is still open, and only what appeared since the last nightly sweep.
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
     * Only what somebody can still bid on.
     *
     * Three cases, and the middle one is the reason this is not a single comparison. A register
     * that says nothing about status tells us nothing to exclude on, and a hit is kept — that has
     * always been the behaviour. A register that does say something must mean "open". And a word
     * Procynia cannot read is not treated as open: an unrecognised status is a notice whose state
     * we do not know, and putting it in somebody's watch inbox as if it were live would be a guess
     * dressed up as a fact.
     *
     * This reading belongs to Doffin, not to watch discovery in general. It rests on Doffin having
     * a status field at all; TED does not, so TedWatchProfileInboxDiscoveryService answers the
     * same question from different evidence rather than inheriting an assumption that would let
     * every award notice in.
     */
    protected function hasEligibleStatus(NormalizedNotice $notice): bool
    {
        if ($notice->providerStatusLabel() === null) {
            return true;
        }

        return $notice->status === OpportunityStatus::Open;
    }

    protected function legacyDoffinNoticeId(string $externalId): ?string
    {
        return $externalId;
    }
}
