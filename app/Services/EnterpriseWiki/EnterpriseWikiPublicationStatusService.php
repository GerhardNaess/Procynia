<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;

/**
 * Where one Wiki page stands on its way to being published, and what has to happen next.
 *
 * This adds no rule. Every gate below is one WikiController already enforces — submit() requires a
 * draft page, a current version and a named reviewer who is not the submitter; approve() requires
 * the assigned reviewer, a version unchanged since submission, and every document-owner approval
 * settled. This class only reads those same conditions and says them in words, in one place, so the
 * list and the page cannot drift into telling the user two different stories.
 *
 * Two distinctions the whole class exists to preserve:
 *
 * 1. A PUBLICATION GATE is something that genuinely stops the page. Claim approval is not one:
 *    neither submit() nor approve() looks at claims. Claims are reported as quality information and
 *    never appear in blocking_reasons — saying "2 påstander må godkjennes" would invent a rule the
 *    domain does not have.
 *
 * 2. Document-owner sign-off only gates APPROVAL, not submission. The approval rows are created by
 *    submit() itself (EnterpriseWikiDocumentOwnerApprovalService::syncForPageVersion), so a draft
 *    page has none yet and its owner summary reads "awaiting_sync". Presenting that as something
 *    the owner must resolve before submitting would send people looking for work that does not
 *    exist, so draft never reports a document-owner blocker.
 */
class EnterpriseWikiPublicationStatusService
{
    /** Never published, still being worked on. */
    public const STATE_DRAFT = 'draft';

    /** Handed to a named reviewer. */
    public const STATE_IN_REVIEW = 'in_review';

    /** The current working version is the published one. */
    public const STATE_PUBLISHED = 'published';

    /** A published version is still serving readers, and newer work is not published yet. */
    public const STATE_PUBLISHED_WITH_CHANGES = 'published_with_changes';

    /** The reviewer sent it back. */
    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const STATE_ARCHIVED = 'archived';

    /** No version exists at all — nothing can be done with the page until one does. */
    public const STATE_NO_VERSION = 'no_version';

    /** The page is waiting on the person who owns it to hand it over. */
    public const ACTOR_PAGE_OWNER = 'page_owner';

    /** The page is waiting on the reviewer it was handed to. */
    public const ACTOR_REVIEWER = 'reviewer';

    /**
     * @param  array{
     *     can_submit?: bool,
     *     eligible_reviewer_count?: int,
     *     final_approval_blocker?: string|null,
     *     is_assigned_reviewer?: bool
     * }  $reviewContext  what the detail view knows and the list deliberately does not compute per row
     * @param  array{total: int, approved: int}|null  $claimCounts  when the caller already holds the
     *                                                              claims, so this never re-queries them
     * @return array{
     *     state: string,
     *     state_label: string,
     *     has_published_version: bool,
     *     has_unpublished_changes: bool,
     *     published_version_number: int|null,
     *     working_version_number: int|null,
     *     reviewer_name: string|null,
     *     next_actor: array{name: string, role: string}|null,
     *     claims_total: int,
     *     claims_approved: int,
     *     next_step: string,
     *     next_step_label: string,
     *     blocking_reasons: list<string>
     * }
     */
    public function forPage(
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
        array $reviewContext = [],
        ?array $claimCounts = null,
    ): array {
        $publishedVersionId = $page->published_version_id !== null ? (int) $page->published_version_id : null;
        $currentVersionId = $currentVersion?->id !== null ? (int) $currentVersion->id : null;

        $hasPublishedVersion = $publishedVersionId !== null;
        // "Has a published version" and "the work in hand is published" are different questions.
        // A page keeps serving v1 while v2 is drafted, so the second one is what tells the user
        // whether their latest changes have reached anybody.
        $hasUnpublishedChanges = $hasPublishedVersion
            && $currentVersionId !== null
            && $publishedVersionId !== $currentVersionId;

        // Counted from whatever the caller already has: the detail view holds the claim collection
        // it renders, the list has them eager-loaded. Neither pays for a second query, and both
        // count the same (current-version) set the Påstander column does.
        $claims = $claimCounts === null && $currentVersion?->relationLoaded('claims') === true
            ? $currentVersion->claims
            : null;

        $state = $this->resolveState($page, $currentVersionId, $hasPublishedVersion, $hasUnpublishedChanges);
        [$nextStep, $blockingReasons] = $this->resolveNextStep($state, $page, $currentVersion, $reviewContext);

        return [
            'state' => $state,
            'state_label' => __('procynia.wiki.publication_state_'.$state),
            'has_published_version' => $hasPublishedVersion,
            'has_unpublished_changes' => $hasUnpublishedChanges,
            // Callers eager-load publishedVersion (the list) or read it in the same payload (the
            // page), so this resolves without adding a query per row.
            'published_version_number' => $hasPublishedVersion && $page->publishedVersion !== null
                ? (int) $page->publishedVersion->version_number
                : null,
            'working_version_number' => $currentVersion?->version_number !== null
                ? (int) $currentVersion->version_number
                : null,
            'reviewer_name' => $currentVersion?->relationLoaded('reviewer') === true
                ? $currentVersion->reviewer?->name
                : null,
            'claims_total' => $claimCounts['total'] ?? $claims?->count() ?? 0,
            'claims_approved' => $claimCounts['approved'] ?? $claims?->where('approval_status', 'approved')->count() ?? 0,
            'next_step' => $nextStep,
            'next_step_label' => $this->nextStepLabel($nextStep, $page, $currentVersion, $reviewContext),
            'next_actor' => $this->nextActor($nextStep, $page, $currentVersion),
            'blocking_reasons' => $blockingReasons,
        ];
    }

    private function resolveState(
        EnterpriseWikiPage $page,
        ?int $currentVersionId,
        bool $hasPublishedVersion,
        bool $hasUnpublishedChanges,
    ): string {
        if (in_array($page->status, [EnterpriseWikiPage::STATUS_ARCHIVED, EnterpriseWikiPage::STATUS_SUPERSEDED], true)) {
            return self::STATE_ARCHIVED;
        }

        if ($currentVersionId === null) {
            return self::STATE_NO_VERSION;
        }

        return match ($page->status) {
            EnterpriseWikiPage::STATUS_REJECTED => self::STATE_CHANGES_REQUESTED,
            EnterpriseWikiPage::STATUS_PENDING_REVIEW => self::STATE_IN_REVIEW,
            // approve() writes status and published_version_id in the same transaction, so an
            // approved page whose pointer has moved off the current version can only mean the
            // version was edited afterwards — published, but not what is in hand.
            EnterpriseWikiPage::STATUS_APPROVED => $hasUnpublishedChanges
                ? self::STATE_PUBLISHED_WITH_CHANGES
                : self::STATE_PUBLISHED,
            // A new ingest run returns a published page to draft while published_version_id keeps
            // pointing at the approved version, which is the ordinary "v1 live, v2 in progress".
            default => $hasPublishedVersion ? self::STATE_PUBLISHED_WITH_CHANGES : self::STATE_DRAFT,
        };
    }

    /**
     * @param  array<string, mixed>  $reviewContext
     * @return array{0: string, 1: list<string>}
     */
    private function resolveNextStep(
        string $state,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
        array $reviewContext,
    ): array {
        if ($state === self::STATE_NO_VERSION) {
            return ['blocked', [__('procynia.wiki.publication_blocker_no_version')]];
        }

        if ($state === self::STATE_ARCHIVED || $state === self::STATE_PUBLISHED) {
            return ['none', []];
        }

        if ($state === self::STATE_CHANGES_REQUESTED) {
            return [($reviewContext['can_submit'] ?? true) ? 'resolve_changes' : 'none', []];
        }

        if ($state === self::STATE_IN_REVIEW) {
            return $this->reviewNextStep($reviewContext);
        }

        // Draft, with or without an older published version behind it. The only case that cannot
        // move is an approved page someone edited afterwards: submit() accepts a draft page, so
        // that version has no route back into review. Saying so is the honest answer; changing the
        // rule is a separate decision.
        if ($page->status === EnterpriseWikiPage::STATUS_APPROVED) {
            return ['blocked', [__('procynia.wiki.publication_blocker_edited_after_publish')]];
        }

        return $this->draftNextStep($currentVersion, $reviewContext);
    }

    /**
     * @param  array<string, mixed>  $reviewContext
     * @return array{0: string, 1: list<string>}
     */
    private function draftNextStep(?EnterpriseWikiPageVersion $currentVersion, array $reviewContext): array
    {
        // A version that already names a reviewer while the page sits in draft was handed over and
        // then sent back by a route that left the assignment behind; it is waiting on that person,
        // not on a new submission.
        if ($currentVersion?->reviewer_user_id !== null) {
            return ['awaiting_review', []];
        }

        if (array_key_exists('can_submit', $reviewContext) && $reviewContext['can_submit'] !== true) {
            return ['awaiting_owner', []];
        }

        // Somebody who can publish this draft outright is not waiting to send it anywhere. Saying
        // "ready to be sent for review" would name the longer of two routes as the only one.
        if (($reviewContext['can_publish_draft'] ?? false) === true) {
            return ['publish', []];
        }

        // submit() refuses a reviewer who is the submitter, so a customer with nobody else able to
        // approve Wiki pages cannot move any page forward. That is worth saying out loud rather
        // than leaving an action that always fails.
        if (array_key_exists('eligible_reviewer_count', $reviewContext)
            && (int) $reviewContext['eligible_reviewer_count'] === 0) {
            return ['blocked', [__('procynia.wiki.publication_blocker_no_reviewer')]];
        }

        return ['submit', []];
    }

    /**
     * @param  array<string, mixed>  $reviewContext
     * @return array{0: string, 1: list<string>}
     */
    private function reviewNextStep(array $reviewContext): array
    {
        // Source sign-off is deliberately absent. A document owner vouching for their own material
        // is provenance, recorded and visible on the source document — it is not a level of
        // approval the Wiki page has to clear, so it is not a reason the page cannot be published.
        $reasons = [];
        $blocker = $reviewContext['final_approval_blocker'] ?? null;

        if ($blocker === 'missing_assignment') {
            $reasons[] = __('procynia.wiki.publication_blocker_version_changed');
        }

        // Only the person whose turn it is gets an action; everyone else is told who they are
        // waiting for. The list never claims to know, because it does not compute this per row.
        if (($reviewContext['is_assigned_reviewer'] ?? false) === true && $blocker === null) {
            return ['approve', []];
        }

        return ['awaiting_review', []];
    }

    /**
     * The sentence under "Neste steg", said to whoever is reading it.
     *
     * Two things decide it: whether we know the person the page is waiting on, and whether the
     * caller told us this viewer may act. A waiting sentence that names nobody sends the reader
     * off to find out who — "Sideeier må sende siden til gjennomgang" is true and useless when the
     * payload already holds the owner's name.
     *
     * @param  array<string, mixed>  $reviewContext
     */
    private function nextStepLabel(
        string $nextStep,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
        array $reviewContext,
    ): string {
        $actor = $this->nextActor($nextStep, $page, $currentVersion);

        if ($actor !== null) {
            return __('procynia.wiki.publication_next_'.$nextStep.'_named', ['name' => $actor['name']]);
        }

        // "Du" only where the caller said this viewer may submit. The list computes no viewer
        // context at all, so a row there says what the page is ready for, never what the reader
        // may do about it — most rows on that screen belong to somebody else.
        if ($nextStep === 'submit' && ($reviewContext['can_submit'] ?? null) === true) {
            return __('procynia.wiki.publication_next_submit_self');
        }

        return __('procynia.wiki.publication_next_'.$nextStep);
    }

    /**
     * Who the page is waiting on, when the payload already knows.
     *
     * Only ever somebody a gate actually names. The page owner is the one person besides a System
     * Owner that canSubmitEnterpriseWikiPage() lets through, so a draft this viewer may not submit
     * is waiting on them by definition; a version in review is waiting on its assigned reviewer.
     *
     * A page with no owner returns null rather than a role with nobody in it: telling somebody
     * "the page owner must send this" when there is no page owner sends them looking for a person
     * who does not exist. The generic sentence stays the honest answer there.
     *
     * Both relations are read only when already loaded, the way reviewer_name is. The list loads
     * neither and never reaches an awaiting_owner step, so this costs it no query per row.
     *
     * @return array{name: string, role: string}|null
     */
    private function nextActor(
        string $nextStep,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
    ): ?array {
        if ($nextStep === 'awaiting_owner') {
            $ownerName = $page->relationLoaded('owner') ? $page->owner?->name : null;

            return $ownerName !== null
                ? ['name' => $ownerName, 'role' => self::ACTOR_PAGE_OWNER]
                : null;
        }

        if ($nextStep === 'awaiting_review') {
            $reviewerName = $currentVersion?->relationLoaded('reviewer') === true
                ? $currentVersion->reviewer?->name
                : null;

            return $reviewerName !== null
                ? ['name' => $reviewerName, 'role' => self::ACTOR_REVIEWER]
                : null;
        }

        return null;
    }

    /**
     * How far the whole Wiki has come, for the summary above the page list.
     *
     * Counted over every page the caller can see rather than the current filter or page of results:
     * the question it answers is "is our knowledge base in use yet", which a filtered subset cannot
     * answer. One aggregate query, joined to the current version so that "published" means the work
     * in hand is published — not merely that the page was published at some point.
     *
     * @param  list<string>  $visibleStatuses
     * @return array{total: int, published: int, in_review: int, draft: int, changes_requested: int, unpublished_changes: int}
     */
    public function summaryForCustomer(int $customerId, array $visibleStatuses): array
    {
        $row = EnterpriseWikiPage::query()
            ->where('enterprise_wiki_pages.customer_id', $customerId)
            ->whereIn('enterprise_wiki_pages.status', $visibleStatuses)
            ->leftJoin('enterprise_wiki_page_versions as cv', function ($join): void {
                $join->on('cv.enterprise_wiki_page_id', '=', 'enterprise_wiki_pages.id')
                    ->where('cv.is_current', '=', true);
            })
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where enterprise_wiki_pages.status = ? and enterprise_wiki_pages.published_version_id = cv.id) as published', [EnterpriseWikiPage::STATUS_APPROVED])
            ->selectRaw('count(*) filter (where enterprise_wiki_pages.status = ?) as in_review', [EnterpriseWikiPage::STATUS_PENDING_REVIEW])
            ->selectRaw('count(*) filter (where enterprise_wiki_pages.status = ?) as draft', [EnterpriseWikiPage::STATUS_DRAFT])
            ->selectRaw('count(*) filter (where enterprise_wiki_pages.status = ?) as changes_requested', [EnterpriseWikiPage::STATUS_REJECTED])
            ->selectRaw('count(*) filter (where enterprise_wiki_pages.published_version_id is not null and enterprise_wiki_pages.published_version_id is distinct from cv.id) as unpublished_changes')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'published' => (int) ($row->published ?? 0),
            'in_review' => (int) ($row->in_review ?? 0),
            'draft' => (int) ($row->draft ?? 0),
            'changes_requested' => (int) ($row->changes_requested ?? 0),
            'unpublished_changes' => (int) ($row->unpublished_changes ?? 0),
        ];
    }
}
