<?php

namespace App\Services\ManagementReview;

use App\Models\BusinessArea;
use App\Models\ManagementReview;
use App\Models\ManagementReviewAmendment;
use App\Models\ManagementReviewEvent;
use App\Models\ManagementReviewParticipant;
use App\Models\ManagementReviewSection;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\FrameworkCatalog;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing a review: creating and changing a draft, its participants and the management's assessment
 * per section, deleting a draft, and — after finalization — the next review date and corrections.
 *
 * Authorization is the caller's (the controller, through ManagementReviewAccessService); this keeps
 * the rules that hold whoever calls: a finalized review is never changed here (the database refuses it
 * too), owners and participants are people of the same customer, fagområder are the customer's own.
 * Every write that matters to the audit trail writes a ManagementReviewEvent in the same transaction.
 */
final class ManagementReviewService
{
    public function __construct(
        private readonly ManagementReviewAccessService $access,
        private readonly ManagementReviewSectionCatalog $catalog,
        private readonly FrameworkCatalog $frameworks,
        private readonly HumanDecisionGuard $guard,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(User $actor, array $data): ManagementReview
    {
        if (! $this->access->canEdit($actor)) {
            throw new AuthorizationException('This user may not create management reviews.');
        }

        return DB::transaction(function () use ($actor, $data): ManagementReview {
            $review = ManagementReview::query()->create($this->fields($actor, $data) + [
                'customer_id' => (int) $actor->customer_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncAreas($review, $data);

            foreach ($this->participantUserIds($review, $data) as $index => $userId) {
                $this->insertParticipant($review, ['user_id' => $userId], $index);
            }

            $this->event($review, $actor, ManagementReviewEvent::CREATED);

            return $review;
        });
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(User $actor, ManagementReview $review, array $data): void
    {
        DB::transaction(function () use ($actor, $review, $data): void {
            $locked = $this->lockedDraft($review, $actor);
            $locked->fill($this->fields($actor, $data) + ['updated_by' => $actor->id])->save();
            $this->syncAreas($locked, $data);

            if (array_key_exists('participant_user_ids', $data)) {
                $this->syncParticipantUsers($locked, $this->participantUserIds($locked, $data));
            }
        });
    }

    /** The conclusion and meeting facts, saved on their own from the overview. */
    public function updateConclusion(User $actor, ManagementReview $review, ?string $conclusion): void
    {
        DB::transaction(function () use ($actor, $review, $conclusion): void {
            $locked = $this->lockedDraft($review, $actor);
            $locked->forceFill(['conclusion' => $this->text($conclusion), 'updated_by' => $actor->id])->save();
        });
    }

    /** @param  array{user_id?: ?int, name?: ?string, role_label?: ?string}  $data */
    public function addParticipant(User $actor, ManagementReview $review, array $data): ManagementReviewParticipant
    {
        return DB::transaction(fn (): ManagementReviewParticipant => $this->insertParticipant($this->lockedDraft($review, $actor), $data));
    }

    public function removeParticipant(User $actor, ManagementReview $review, ManagementReviewParticipant $participant): void
    {
        DB::transaction(function () use ($actor, $review, $participant): void {
            $locked = $this->lockedDraft($review, $actor);

            ManagementReviewParticipant::query()
                ->where('management_review_id', $locked->id)
                ->whereKey($participant->id)
                ->firstOrFail()
                ->delete();
        });
    }

    /** @param  array{user_id?: ?int, name?: ?string, role_label?: ?string}  $data */
    private function insertParticipant(ManagementReview $review, array $data, ?int $position = null): ManagementReviewParticipant
    {
        $this->assertDraft($review);
        $user = null;

        if (! empty($data['user_id'])) {
            $user = User::query()->where('customer_id', $review->customer_id)->where('is_active', true)->find((int) $data['user_id']);

            if ($user === null) {
                throw ValidationException::withMessages(['user_id' => __('procynia.management_review.validation.participant_not_allowed')]);
            }

            if ($review->participants()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => __('procynia.management_review.validation.participant_exists')]);
            }
        }

        $name = $user?->name ?? trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('procynia.management_review.validation.participant_name')]);
        }

        return ManagementReviewParticipant::query()->create([
            'customer_id' => $review->customer_id,
            'management_review_id' => $review->id,
            'user_id' => $user?->id,
            'name' => $name,
            'role_label' => $this->text($data['role_label'] ?? null),
            'position' => $position ?? ((int) $review->participants()->max('position') + 1),
        ]);
    }

    /**
     * The management's assessment of one section: judgement, optional comment, and for a manual
     * section the text that is its basis. The person must be able to see the section — nobody judges
     * what they cannot read.
     */
    public function saveSection(User $actor, ManagementReview $review, string $key, ?string $judgement, ?string $comment, ?string $notes): ManagementReviewSection
    {
        return DB::transaction(function () use ($actor, $review, $key, $judgement, $comment, $notes): ManagementReviewSection {
            $locked = $this->lockedDraft($review, $actor);

            if (! in_array($key, $this->catalog->keysFor($locked), true)) {
                abort(404);
            }

            if ($this->catalog->judgementFollowsBasis($key) && $this->catalog->liveState($actor, $key) !== ManagementReviewSectionCatalog::STATE_AVAILABLE) {
                abort(403);
            }

            $section = ManagementReviewSection::query()->firstOrNew([
                'management_review_id' => $locked->id,
                'section_key' => $key,
            ]);

            $section->fill([
                'customer_id' => $locked->customer_id,
                'judgement' => $judgement,
                'comment' => $this->text($comment),
                'notes' => $this->catalog->definition($key)['type'] === ManagementReviewSectionCatalog::TYPE_MODULE ? null : $this->text($notes),
                'updated_by' => $actor->id,
            ])->save();

            $locked->forceFill(['updated_by' => $actor->id])->touch();

            return $section;
        });
    }

    /**
     * Deleting a draft (ManagementReview::isDeletable()). A human decision (phase 0 step 11):
     * HumanDecisionGuard, then management_review.delete for a review of the person's own customer,
     * asked of the locked review. The deletion itself is an event, which outlives the review.
     */
    public function delete(User $actor, ManagementReview $review): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::MANAGEMENT_REVIEW_DELETE);

        DB::transaction(function () use ($actor, $review): void {
            $locked = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->canDeleteReview($actor, $locked)) {
                throw new AuthorizationException('This user may not delete this management review.');
            }

            if (! $locked->isDeletable()) {
                throw ValidationException::withMessages(['review' => __('procynia.management_review.validation.not_deletable')]);
            }

            $this->event($locked, $actor, ManagementReviewEvent::DELETED, ['title' => $locked->title]);
            $locked->delete();
        });
    }

    /** Open after finalization too: planning the next review is not part of what was decided. */
    public function updateNextReview(User $actor, ManagementReview $review, ?string $date): void
    {
        DB::transaction(function () use ($actor, $review, $date): void {
            $locked = $this->lockedEditable($review, $actor);
            $before = $locked->next_review_due_on?->toDateString();

            if ($before === $date) {
                return;
            }

            $locked->forceFill(['next_review_due_on' => $date, 'updated_by' => $actor->id])->save();

            if ($locked->isFinalized()) {
                $this->event($locked, $actor, ManagementReviewEvent::NEXT_REVIEW_CHANGED, ['from' => $before, 'to' => $date]);
            }
        });
    }

    /** Who is responsible for the review. Open after finalization: they plan the next one. */
    public function updateOwner(User $actor, ManagementReview $review, int $ownerId): void
    {
        DB::transaction(function () use ($actor, $review, $ownerId): void {
            $locked = $this->lockedEditable($review, $actor);
            $this->assertValidOwner($actor, $ownerId);
            $locked->forceFill(['owner_user_id' => $ownerId, 'updated_by' => $actor->id])->save();
        });
    }

    /**
     * A correction after finalization. The original stays as it was. A human decision, under the same
     * key as finalizing (phase 0 step 11): HumanDecisionGuard, then management_review.finalize for a
     * review of the person's own customer, asked of the locked review.
     */
    public function addAmendment(User $actor, ManagementReview $review, string $text, string $reason): ManagementReviewAmendment
    {
        $actor = $this->guard->assert($actor, HumanDecision::MANAGEMENT_REVIEW_FINALIZE);

        return DB::transaction(function () use ($actor, $review, $text, $reason): ManagementReviewAmendment {
            $locked = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->canFinalizeReview($actor, $locked)) {
                throw new AuthorizationException('This user may not amend this management review.');
            }

            if (! $locked->isFinalized()) {
                throw ValidationException::withMessages(['text' => __('procynia.management_review.validation.amend_only_finalized')]);
            }

            $amendment = ManagementReviewAmendment::query()->create([
                'customer_id' => $locked->customer_id,
                'management_review_id' => $locked->id,
                'text' => trim($text),
                'reason' => trim($reason),
                'created_by_user_id' => $actor->id,
                'created_by_name' => $actor->name,
                'created_at' => now(),
            ]);

            $this->event($locked, $actor, ManagementReviewEvent::AMENDMENT_ADDED, ['amendment_id' => (int) $amendment->id]);

            return $amendment;
        });
    }

    /** @param  array<string, mixed>  $metadata */
    public function event(ManagementReview $review, User $actor, string $event, array $metadata = [], ?int $decisionId = null): void
    {
        ManagementReviewEvent::query()->create([
            'customer_id' => $review->customer_id,
            'management_review_id' => $review->id,
            'decision_id' => $decisionId,
            'event' => $event,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'metadata' => (object) $metadata,
            'occurred_at' => now(),
        ]);
    }

    public function assertValidOwner(User $actor, int $ownerId, string $field = 'owner_user_id'): void
    {
        $owner = User::query()->where('customer_id', (int) $actor->customer_id)->find($ownerId);

        if (! $this->access->isValidOwner($owner, (int) $actor->customer_id)) {
            throw ValidationException::withMessages([$field => __('procynia.management_review.validation.owner_not_allowed')]);
        }
    }

    public function assertDraft(ManagementReview $review): void
    {
        if (! $review->isDraft()) {
            throw ValidationException::withMessages(['review' => __('procynia.management_review.validation.finalized_locked')]);
        }
    }

    /**
     * The review, locked, if this person may edit it: management_review.edit, the module and view, in
     * their own customer (phase 0 step 11). Ordinary editing — not a human-only decision.
     */
    public function lockedEditable(ManagementReview $review, User $actor): ManagementReview
    {
        $locked = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

        if (! $this->access->canEditReview($actor, $locked)) {
            throw new AuthorizationException('This user may not change this management review.');
        }

        return $locked;
    }

    /** As lockedEditable(), and still a draft. */
    private function lockedDraft(ManagementReview $review, User $actor): ManagementReview
    {
        $locked = $this->lockedEditable($review, $actor);
        $this->assertDraft($locked);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(User $actor, array $data): array
    {
        $this->assertValidOwner($actor, (int) $data['owner_user_id']);
        $frameworks = array_values(array_intersect($this->frameworks->selectableKeys('management_review'), (array) ($data['frameworks'] ?? [])));

        return [
            'title' => trim((string) $data['title']),
            'purpose' => $this->text($data['purpose'] ?? null),
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'meeting_date' => $data['meeting_date'] ?? null,
            'all_business_areas' => (bool) ($data['all_business_areas'] ?? true),
            'frameworks' => $frameworks,
            'owner_user_id' => (int) $data['owner_user_id'],
        ];
    }

    /**
     * The people chosen in the form become the review's participants who are users: new ones are
     * added at the end, ones no longer chosen are removed. A participant registered by name only
     * (someone outside Procynia) is not part of the choice and stays; one still chosen keeps its role.
     *
     * @param  list<int>  $userIds
     */
    private function syncParticipantUsers(ManagementReview $review, array $userIds): void
    {
        $review->participants()->whereNotNull('user_id')->whereNotIn('user_id', $userIds ?: [0])->delete();
        $existing = $review->participants()->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_diff($userIds, $existing) as $userId) {
            $this->insertParticipant($review, ['user_id' => $userId]);
        }
    }

    /**
     * The chosen participant ids, each an active person of the review's customer or someone already
     * on this review (kept even if deactivated since) — one that is neither refuses the whole form,
     * so nothing is saved halfway.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function participantUserIds(ManagementReview $review, array $data): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($data['participant_user_ids'] ?? []))));
        $existing = $review->participants()->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $valid = User::query()
            ->where('customer_id', $review->customer_id)
            ->whereIn('id', $ids ?: [0])
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $existing ?: [0]))
            ->count();

        if ($valid !== count($ids)) {
            throw ValidationException::withMessages(['participant_user_ids' => __('procynia.management_review.validation.participant_not_allowed')]);
        }

        return $ids;
    }

    /** @param  array<string, mixed>  $data */
    private function syncAreas(ManagementReview $review, array $data): void
    {
        $ids = (bool) ($data['all_business_areas'] ?? true) ? [] : array_values(array_unique(array_map('intval', $data['business_area_ids'] ?? [])));
        $valid = BusinessArea::query()->forCustomer((int) $review->customer_id)->whereIn('id', $ids ?: [0])->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (count($valid) !== count($ids) || (! $review->all_business_areas && $ids === [])) {
            throw ValidationException::withMessages(['business_area_ids' => __('procynia.management_review.validation.areas')]);
        }

        $review->businessAreas()->sync(collect($valid)->mapWithKeys(fn (int $id): array => [$id => ['customer_id' => $review->customer_id]])->all());
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
