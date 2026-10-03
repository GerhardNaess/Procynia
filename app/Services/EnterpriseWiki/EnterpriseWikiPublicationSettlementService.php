<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;

/**
 * What writing a new version does to a page's publication, for every writer that is not the
 * ordinary review flow.
 *
 * One rule, stated once, because the alternative is each writer deciding for itself and the answers
 * drifting apart: a new current version never silently becomes the published one, and an older
 * published version never silently stops serving.
 *
 *   A HUMAN produces a working version — whoever they are, System Owner and Wiki approvers
 *   included. The published one keeps serving, and the page goes back to draft so it can be sent
 *   for review (submit() only accepts a draft page). Somebody other than the editor then approves
 *   it: the four-eyes rule has no exception for the person who wrote the change.
 *
 *   A MACHINE produces a working version, always. It may propose knowledge; it may not approve it.
 *   Automated repair and relinking reach real, already-published pages, and an AI revision that
 *   published itself would put text nobody read into tender answers.
 *
 * Neither path ever moves published_version_id backwards, rewrites an earlier version, or touches
 * review history. A page that was never published is left alone entirely: first publication is a
 * decision of its own, and no writer here is a way around it.
 */
class EnterpriseWikiPublicationSettlementService
{
    /**
     * Settle publication after a person edited the page by hand.
     *
     * Never publishes. The edit is a working version like any other, and the editor's authority
     * does not change that — saving is not approving.
     *
     * @return bool whether the page was returned to draft
     */
    public function afterManualEdit(EnterpriseWikiPage $page): bool
    {
        $locked = $this->settleablePage($page->id);

        if ($locked === null) {
            return false;
        }

        // published_version_id is untouched: the approved version keeps serving until somebody
        // other than the editor approves the new one.
        $locked->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        return true;
    }

    /**
     * Settle publication after an automated service changed what the page says.
     *
     * Call this from writers that alter the article's meaning — an AI revision, an added or removed
     * wikilink in the prose, a patched requirement, content withdrawn with its source document. Do
     * not call it from purely technical maintenance: restoring block provenance or repairing a
     * structural article/summary pairing changes no knowledge, and sending a published page back
     * for review over it would be bureaucracy without a question to answer.
     *
     * Deliberately takes an id and re-reads: callers hold the page row lock from the version write,
     * and the instance they started with may predate it.
     *
     * @return bool whether the page was returned to draft
     */
    public function afterAutomatedContentChange(int $pageId): bool
    {
        $locked = $this->settleablePage($pageId);

        if ($locked === null) {
            return false;
        }

        // published_version_id is untouched on purpose: the approved version keeps serving readers
        // and tender drafting until a person approves what the machine produced.
        $locked->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        return true;
    }

    /**
     * The page, when it is one where publication has anything to settle: published at least once,
     * and currently at rest rather than mid-review.
     *
     * A page in pending_review is excluded on purpose. It has a named reviewer holding an open
     * handover, and neither publishing under them nor cancelling their assignment belongs in a
     * writer — the review flow's own guards already refuse to approve a version that changed after
     * submission, which is the honest outcome there.
     */
    private function settleablePage(int $pageId): ?EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->whereKey($pageId)->first();

        if (! $page instanceof EnterpriseWikiPage
            || $page->published_version_id === null
            || $page->status !== EnterpriseWikiPage::STATUS_APPROVED) {
            return null;
        }

        return $page;
    }
}
