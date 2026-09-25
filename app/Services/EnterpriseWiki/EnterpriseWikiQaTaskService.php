<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Wiki quality work that is still on one person, for the Info Center to show.
 *
 * Read-only aggregation, deliberately. The assignment already lives on the page version and the
 * progress already lives in the claims, so a mirrored task row would be a second copy of both —
 * one that has to be created, updated on reassignment, closed when the last claim is handled, and
 * re-opened when a new version arrives. Every one of those is a chance for the two to disagree,
 * and a task list that disagrees with the page it points at is worse than no task list.
 *
 * The distinction this exists to serve: the bell says something happened, and goes quiet once it
 * is read. This says the work is still yours, and goes quiet only when the work is done.
 *
 * "Done" is not a status anybody sets. A claim that is approved or rejected has had its QA decision
 * made — rejected is a decision, not an omission — so the task is active exactly while claims are
 * still pending. A version nobody wrote claims for has no quality work to do and produces no task,
 * matching what the Wiki page itself says.
 */
class EnterpriseWikiQaTaskService
{
    /**
     * Every Wiki version this person has been asked to quality assure and has not finished.
     *
     * Scoped to the user's own customer and to their own assignment: the Info Center shows what is
     * on you, and grants nothing — opening the page still goes through the Wiki's own authorization.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function openTasksFor(User $user, int $customerId): Collection
    {
        if (! $user->is_active || (int) $user->customer_id !== $customerId) {
            return collect();
        }

        return EnterpriseWikiPageVersion::query()
            ->where('qa_user_id', $user->id)
            // Only the version in hand. An assignment on a superseded version described work on
            // content that is no longer current, and chasing it would waste the person's time.
            ->where('is_current', true)
            ->whereHas('page', fn ($query) => $query
                ->where('customer_id', $customerId)
                ->whereNotIn('status', [
                    EnterpriseWikiPage::STATUS_ARCHIVED,
                    EnterpriseWikiPage::STATUS_SUPERSEDED,
                ]))
            ->with('page:id,slug,title,customer_id,status')
            ->withCount([
                'claims as claims_total',
                'claims as claims_pending' => fn ($query) => $query
                    ->where('approval_status', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING),
                'claims as claims_handled' => fn ($query) => $query
                    ->whereIn('approval_status', [
                        EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
                        EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
                    ]),
            ])
            ->orderBy('qa_assigned_at')
            ->get()
            // Nothing left to decide means nothing left to do. Filtered in PHP because the counts
            // are what decides, and they are already loaded.
            ->filter(static fn (EnterpriseWikiPageVersion $version): bool => $version->claims_pending > 0)
            ->map(fn (EnterpriseWikiPageVersion $version): array => $this->taskPayload($version))
            ->values();
    }

    /** How many of them, for the "Mine oppgaver" counter. */
    public function openTaskCountFor(User $user, int $customerId): int
    {
        return $this->openTasksFor($user, $customerId)->count();
    }

    /** @return array<string, mixed> */
    private function taskPayload(EnterpriseWikiPageVersion $version): array
    {
        $page = $version->page;

        return [
            // Prefixed so it can never collide with a SavedNoticeInfoItem id in the same list.
            'id' => 'wiki-qa-'.$version->id,
            'type' => 'wiki_qa',
            'type_label' => 'Wiki QA',
            'subject_label' => 'Kvalitetssikre Wiki-side',
            'page_title' => $page?->title,
            'page_slug' => $page?->slug,
            'assigned_at' => optional($version->qa_assigned_at)?->toIso8601String(),
            'claims_total' => (int) $version->claims_total,
            'claims_handled' => (int) $version->claims_handled,
            'claims_pending' => (int) $version->claims_pending,
            'action_url' => $page !== null
                ? route('app.wiki.show', ['slug' => $page->slug], false)
                : null,
        ];
    }
}
