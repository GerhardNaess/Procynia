<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Wiki pages one person has been handed to review and has not decided on.
 *
 * Same shape and the same reasoning as [EnterpriseWikiQaTaskService], and deliberately a separate
 * class: reviewing an article and quality assuring its claims are different jobs with different
 * authority. A reviewer decides whether the page is published; QA decides whether individual claims
 * hold. Merging them into one "Wiki task" query would make the two indistinguishable in code the
 * moment either rule changes.
 *
 * Read-only aggregation, no task row. `reviewer_user_id` on the current version and the page's
 * pending_review status already say everything a task list needs, so approving or sending the page
 * back retires the task by itself — there is no second record that could be left open.
 *
 * "Done" is therefore not a status anybody sets here either: approve() moves the page to approved
 * and reject() to rejected, and both take it out of this query.
 */
class EnterpriseWikiReviewTaskService
{
    /**
     * Every Wiki page waiting on this person's review decision.
     *
     * Scoped to their own customer and their own assignment. Grants nothing — opening the page
     * still goes through the Wiki's own authorization, and deciding on it still goes through
     * approve()/reject().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function openTasksFor(User $user, int $customerId): Collection
    {
        if (! $user->is_active || (int) $user->customer_id !== $customerId) {
            return collect();
        }

        return EnterpriseWikiPageVersion::query()
            ->where('reviewer_user_id', $user->id)
            // The handover was of this version. An assignment left on a superseded version refers
            // to content nobody is waiting on any more.
            ->where('is_current', true)
            ->whereHas('page', fn ($query) => $query
                ->where('customer_id', $customerId)
                // The page status is the decision state: only pending_review is still open, which
                // is what makes approve() and reject() close the task without touching it.
                ->where('status', EnterpriseWikiPage::STATUS_PENDING_REVIEW))
            ->with([
                'page:id,slug,title,customer_id,status',
                'submittedBy:id,name,customer_id',
            ])
            ->orderBy('submitted_at')
            ->get()
            // A base collection: these are payload rows, not models, and Eloquent's merge()
            // keys by primary key — which is not a key these rows have.
            ->toBase()
            ->map(fn (EnterpriseWikiPageVersion $version): array => $this->taskPayload($version))
            ->values();
    }

    /** @return array<string, mixed> */
    private function taskPayload(EnterpriseWikiPageVersion $version): array
    {
        $page = $version->page;

        return [
            // Prefixed so it can never collide with a SavedNoticeInfoItem id, or with a QA task.
            'id' => 'wiki-review-'.$version->id,
            'type' => 'wiki_review',
            'type_label' => 'Wiki gjennomgang',
            'subject_label' => 'Gjennomgå Wiki-side',
            'page_title' => $page?->title,
            'page_slug' => $page?->slug,
            'submitted_by' => $version->submittedBy?->name,
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'action_url' => $page !== null
                ? route('app.wiki.show', ['slug' => $page->slug], false)
                : null,
        ];
    }
}
