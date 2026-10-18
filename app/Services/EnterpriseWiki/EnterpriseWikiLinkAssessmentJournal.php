<?php

namespace App\Services\EnterpriseWiki;

/**
 * What EnterpriseWikiLinkIntentMaterializer actually did with the link intents of one page
 * generation — every AI response for that page (first generation, section repair, figure repair)
 * feeds the same journal.
 *
 * Only facts the backend observed are recorded: how many intents the model proposed, which were
 * rejected and why, and which valid intents could not be placed because their anchor was not in
 * the prose. What was finally materialized is NOT kept here: it is read from the persisted content
 * blocks, so a later step that drops a block can never leave a link counted that the page does not
 * contain. EnterpriseWikiLinkAssessmentRecorder turns both into the pivot's link_assessment.
 */
class EnterpriseWikiLinkAssessmentJournal
{
    private int $proposed = 0;

    /** @var list<array{target_page_id: ?int, intent_id: ?string, outcome: string}> */
    private array $rejected = [];

    /** @var list<array{target_page_id: int, intent_id: string}> */
    private array $skippedAnchorNotFound = [];

    public function recordProposed(int $count): void
    {
        $this->proposed += max(0, $count);
    }

    public function recordRejected(?int $targetPageId, ?string $intentId, string $outcome): void
    {
        $this->rejected[] = [
            'target_page_id' => $targetPageId,
            'intent_id' => $intentId,
            'outcome' => $outcome,
        ];
    }

    public function recordAnchorNotFound(int $targetPageId, string $intentId): void
    {
        $this->skippedAnchorNotFound[] = [
            'target_page_id' => $targetPageId,
            'intent_id' => $intentId,
        ];
    }

    public function proposed(): int
    {
        return $this->proposed;
    }

    /** @return list<array{target_page_id: ?int, intent_id: ?string, outcome: string}> */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** @return list<array{target_page_id: int, intent_id: string}> */
    public function skippedAnchorNotFound(): array
    {
        return $this->skippedAnchorNotFound;
    }
}
