<?php

namespace App\Services\ManagementReview;

use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewEvent;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Beslutninger og tiltak (plan §9).
 *
 * ONE FOLLOW-UP PER TILTAK, NEVER TWO. A tiltak is followed up either here (follow_up = own) — owner,
 * due date and status on the decision itself, in the owner's Mine oppgaver through
 * ManagementReviewTaskSource — or in Avvik og forbedringer (follow_up = improvement_case), where the
 * case owns it and ImprovementTaskSource shows it. Handing off moves it; it never copies it. A tiltak
 * starts followed up here, so it works for every customer whatever modules they hold; handing it to
 * Avvik og forbedringer is offered when the customer holds the module and the person may register
 * cases there (improvement.edit in a fagområde — ImprovementCaseCreator checks it again).
 *
 * WHEN. Decisions are registered and changed while the review is a draft. A tiltak followed up here
 * is followed up — completed, cancelled, reopened, reassigned — also after the review is finalized;
 * each such change after finalization is an event in the review's audit trail, and what was decided
 * stays frozen. Handing off and linking happen while the review is a draft.
 *
 * WHO. management_review.edit for decisions and hand-offs. A tiltak's own follow-up may also be done
 * by its owner, who only needs to read reviews — the person responsible must be able to finish it.
 */
final class ManagementReviewDecisionService
{
    public function __construct(
        private readonly ManagementReviewService $reviews,
        private readonly ManagementReviewAccessService $access,
        private readonly ImprovementCaseAccessService $improvements,
        private readonly ImprovementCaseCreator $creator,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /** @param  array<string, mixed>  $data  validated: kind, text, section_key, owner_user_id, due_date */
    public function create(User $actor, ManagementReview $review, array $data): ManagementReviewDecision
    {
        return DB::transaction(function () use ($actor, $review, $data): ManagementReviewDecision {
            $locked = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->reviews->assertDraft($locked);

            return ManagementReviewDecision::query()->create($this->fields($actor, $data) + [
                'customer_id' => $locked->customer_id,
                'management_review_id' => $locked->id,
                'position' => (int) $locked->decisions()->max('position') + 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, ManagementReviewDecision $decision, array $data): void
    {
        DB::transaction(function () use ($actor, $decision, $data): void {
            $locked = $this->lockedEditable($decision);
            $fields = $this->fields($actor, $data);

            // A tiltak keeps its follow-up state when its text or owner is corrected.
            if ($locked->isAction() && $fields['kind'] === ManagementReviewDecision::KIND_ACTION) {
                unset($fields['status'], $fields['follow_up']);
            }

            $locked->fill($fields + ['updated_by' => $actor->id, 'completed_at' => $fields['kind'] === ManagementReviewDecision::KIND_DECISION ? null : $locked->completed_at])->save();
        });
    }

    public function delete(User $actor, ManagementReviewDecision $decision): void
    {
        DB::transaction(function () use ($decision): void {
            $this->lockedEditable($decision)->delete();
        });
    }

    /** Fullfør, Avbryt or Gjenåpne a tiltak followed up here. */
    public function changeStatus(User $actor, ManagementReviewDecision $decision, string $status, ?string $note): void
    {
        DB::transaction(function () use ($actor, $decision, $status, $note): void {
            $locked = ManagementReviewDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isFollowedUpHere() || $locked->status === $status) {
                throw ValidationException::withMessages(['status' => __('procynia.management_review.validation.status_not_allowed')]);
            }

            if ($status === ManagementReviewDecision::STATUS_OPEN && $locked->status === ManagementReviewDecision::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => __('procynia.management_review.validation.status_not_allowed')]);
            }

            $locked->forceFill([
                'status' => $status,
                'completed_at' => $status === ManagementReviewDecision::STATUS_COMPLETED ? now() : null,
                'completed_by_user_id' => $status === ManagementReviewDecision::STATUS_COMPLETED ? $actor->id : null,
                'completion_note' => $status === ManagementReviewDecision::STATUS_OPEN ? $locked->completion_note : (trim((string) $note) ?: null),
                'updated_by' => $actor->id,
            ])->save();

            $review = $locked->review()->firstOrFail();

            if ($review->isFinalized()) {
                $this->reviews->event($review, $actor, match ($status) {
                    ManagementReviewDecision::STATUS_COMPLETED => ManagementReviewEvent::ACTION_COMPLETED,
                    ManagementReviewDecision::STATUS_CANCELLED => ManagementReviewEvent::ACTION_CANCELLED,
                    default => ManagementReviewEvent::ACTION_REOPENED,
                }, array_filter(['note' => trim((string) $note) ?: null]), (int) $locked->id);
            }
        });
    }

    /** Owner and due date of a tiltak followed up here, after the review is finalized. */
    public function reassign(User $actor, ManagementReviewDecision $decision, int $ownerId, string $dueDate): void
    {
        $this->reviews->assertValidOwner($actor, $ownerId);

        DB::transaction(function () use ($actor, $decision, $ownerId, $dueDate): void {
            $locked = ManagementReviewDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isFollowedUpHere()) {
                throw ValidationException::withMessages(['owner_user_id' => __('procynia.management_review.validation.status_not_allowed')]);
            }

            $before = ['owner_user_id' => $locked->owner_user_id, 'due_date' => $locked->due_date?->toDateString()];
            $locked->forceFill(['owner_user_id' => $ownerId, 'due_date' => $dueDate, 'updated_by' => $actor->id])->save();
            $review = $locked->review()->firstOrFail();

            if ($review->isFinalized()) {
                $this->reviews->event($review, $actor, ManagementReviewEvent::ACTION_REASSIGNED, [
                    'from' => $before,
                    'to' => ['owner_user_id' => $ownerId, 'due_date' => $dueDate],
                ], (int) $locked->id);
            }
        });
    }

    /**
     * Whether the person may hand tiltak to Avvik og forbedringer at all: the customer holds the
     * module and the person may register cases in at least one fagområde.
     */
    public function canHandOff(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->entitlements->hasModule($customer, 'improvements')
            && $this->improvements->canOpenModule($user)
            && $this->improvements->editableAreas($user)->isNotEmpty();
    }

    /**
     * What the hand-off form needs: the fagområder the person may register cases in, who could own
     * the case in each, and the open cases they could link to instead.
     *
     * @return array{area_options: list<array{id: int, name: string}>, owner_options: list<array{id: int, name: string, area_ids: list<int>}>, case_options: list<array{id: int, title: string}>}|null
     */
    public function handOffOptions(User $user): ?array
    {
        if (! $this->canHandOff($user)) {
            return null;
        }

        $areas = $this->improvements->editableAreas($user);
        $areaIds = $areas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return [
            'area_options' => $areas->map(fn ($area): array => ['id' => (int) $area->id, 'name' => $area->name])->values()->all(),
            'owner_options' => $this->improvements->ownerCandidates($user, $areaIds),
            'case_options' => $this->improvements->visibleCases($user)
                ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
                ->whereIn('improvement_cases.business_area_id', $areaIds ?: [0])
                ->whereNotIn('improvement_cases.id', ManagementReviewDecision::query()->whereNotNull('improvement_case_id')->select('improvement_case_id'))
                ->orderBy('improvement_cases.title')
                ->limit(200)
                ->get(['improvement_cases.id', 'improvement_cases.title'])
                ->map(fn (ImprovementCase $case): array => ['id' => (int) $case->id, 'title' => $case->title])
                ->all(),
        ];
    }

    /**
     * Følg opp i Avvik og forbedringer: a new case (a forbedring) for this tiltak, registered through
     * ImprovementCaseCreator exactly like one registered there. The tiltak's own follow-up ends; the
     * case's begins.
     *
     * @param  array<string, mixed>  $validated  title, description, business_area_id, owner_user_id, due_date
     */
    public function handOff(User $actor, ManagementReviewDecision $decision, array $validated): ImprovementCase
    {
        abort_unless($this->canHandOff($actor), 403);

        return DB::transaction(function () use ($actor, $decision, $validated): ImprovementCase {
            $locked = $this->lockedForHandOff($decision);

            $case = $this->creator->create($actor, [
                'type' => ImprovementCase::TYPE_IMPROVEMENT,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'business_area_id' => $validated['business_area_id'],
                'owner_user_id' => $validated['owner_user_id'],
                'occurred_at' => null,
                'due_date' => $validated['due_date'] ?? null,
            ]);

            $this->attach($actor, $locked, $case, ManagementReviewDecision::ORIGIN_HANDOFF);

            return $case;
        });
    }

    /** Følg opp i en eksisterende sak: the follow-up already exists in Avvik og forbedringer. */
    public function link(User $actor, ManagementReviewDecision $decision, int $caseId): ImprovementCase
    {
        abort_unless($this->canHandOff($actor), 403);

        return DB::transaction(function () use ($actor, $decision, $caseId): ImprovementCase {
            $locked = $this->lockedForHandOff($decision);
            $case = $this->improvements->findVisible($actor, $caseId);

            if ($case === null || ! $case->isActive() || ! $this->improvements->canEdit($actor, $case)
                || ManagementReviewDecision::query()->where('improvement_case_id', $case->id)->exists()) {
                throw ValidationException::withMessages(['improvement_case_id' => __('procynia.management_review.validation.case_not_allowed')]);
            }

            $this->attach($actor, $locked, $case, ManagementReviewDecision::ORIGIN_LINKED);

            return $case;
        });
    }

    /**
     * «Fra Ledelsens gjennomgåelse …» on the case page — only for someone who can read reviews. Null
     * for everyone else and for a case no review led to.
     *
     * @return array{review_title: string, review_url: string, decision: string}|null
     */
    public function provenanceFor(User $user, ImprovementCase $case): ?array
    {
        $decision = ManagementReviewDecision::query()
            ->where('customer_id', $case->customer_id)
            ->where('improvement_case_id', $case->id)
            ->first();

        $review = $decision !== null ? $this->access->findVisible($user, (int) $decision->management_review_id) : null;

        if ($review === null) {
            return null;
        }

        return [
            'review_title' => $review->title,
            'review_url' => route('app.management-review.show', ['reviewId' => $review->id]).'#decision-'.$decision->id,
            'decision' => $decision->text,
        ];
    }

    /**
     * Whether the person may follow up this tiltak here: an editor of reviews, or its owner.
     */
    public function canFollowUp(User $user, ManagementReviewDecision $decision): bool
    {
        return $decision->isFollowedUpHere()
            && ($this->access->canEdit($user) || ((int) $decision->owner_user_id === (int) $user->id && $this->access->canOpenModule($user)));
    }

    private function attach(User $actor, ManagementReviewDecision $decision, ImprovementCase $case, string $origin): void
    {
        $decision->forceFill([
            'follow_up' => ManagementReviewDecision::FOLLOW_UP_IMPROVEMENT_CASE,
            'status' => null,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'improvement_case_id' => $case->id,
            'improvement_origin' => $origin,
            'handoff_key' => (string) Str::uuid(),
            'handed_off_at' => now(),
            'handed_off_by_user_id' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        $this->reviews->event($decision->review()->firstOrFail(), $actor,
            $origin === ManagementReviewDecision::ORIGIN_HANDOFF ? ManagementReviewEvent::DECISION_HANDED_OFF : ManagementReviewEvent::DECISION_LINKED,
            ['improvement_case_id' => (int) $case->id], (int) $decision->id);
    }

    private function lockedForHandOff(ManagementReviewDecision $decision): ManagementReviewDecision
    {
        $review = ManagementReview::query()->whereKey($decision->management_review_id)->lockForUpdate()->firstOrFail();
        $this->reviews->assertDraft($review);
        $locked = ManagementReviewDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isAction() || $locked->isInImprovements()) {
            throw ValidationException::withMessages(['decision' => __('procynia.management_review.validation.already_handed_off')]);
        }

        return $locked;
    }

    private function lockedEditable(ManagementReviewDecision $decision): ManagementReviewDecision
    {
        $review = ManagementReview::query()->whereKey($decision->management_review_id)->lockForUpdate()->firstOrFail();
        $this->reviews->assertDraft($review);
        $locked = ManagementReviewDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();

        if ($locked->isInImprovements()) {
            throw ValidationException::withMessages(['decision' => __('procynia.management_review.validation.handed_off_locked')]);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(User $actor, array $data): array
    {
        $kind = $data['kind'];

        if ($kind === ManagementReviewDecision::KIND_DECISION) {
            return [
                'kind' => $kind,
                'text' => trim((string) $data['text']),
                'section_key' => $data['section_key'] ?? null,
                'owner_user_id' => null,
                'due_date' => null,
                'follow_up' => null,
                'status' => null,
            ];
        }

        $this->reviews->assertValidOwner($actor, (int) $data['owner_user_id']);

        return [
            'kind' => $kind,
            'text' => trim((string) $data['text']),
            'section_key' => $data['section_key'] ?? null,
            'owner_user_id' => (int) $data['owner_user_id'],
            'due_date' => $data['due_date'],
            'follow_up' => ManagementReviewDecision::FOLLOW_UP_OWN,
            'status' => ManagementReviewDecision::STATUS_OPEN,
        ];
    }
}
