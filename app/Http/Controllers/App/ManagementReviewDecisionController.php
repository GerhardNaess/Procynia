<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Services\ManagementReview\ManagementReviewSectionCatalog;
use App\Support\CustomerContext;
use App\Support\ManagementReview\ManagementReviewValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Beslutninger og tiltak on a review (plan §9). A decision is reached through its review, and its
 * review through visibleReviews(): another customer's decision is a 404.
 *
 * management_review.edit registers, changes, deletes and hands off. The follow-up of a tiltak
 * followed up here — Fullfør, Avbryt, Gjenåpne — is also open to its owner; a new owner or due date
 * after the meeting takes management_review.edit.
 */
class ManagementReviewDecisionController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ManagementReviewAccessService $access,
        private readonly ManagementReviewDecisionService $decisions,
    ) {}

    public function store(Request $request, int $reviewId): RedirectResponse
    {
        [$user, $review] = $this->review($reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $this->decisions->create($user, $review, $this->validated($request));

        return back()->with('success', __('procynia.management_review.flash.decision_added'));
    }

    public function update(Request $request, int $reviewId, int $decisionId): RedirectResponse
    {
        [$user, $review, $decision] = $this->decision($reviewId, $decisionId);
        abort_unless($this->access->canEdit($user), 403);

        $this->decisions->update($user, $decision, $this->validated($request));

        return back()->with('success', __('procynia.management_review.flash.decision_updated'));
    }

    public function destroy(int $reviewId, int $decisionId): RedirectResponse
    {
        [$user, $review, $decision] = $this->decision($reviewId, $decisionId);
        abort_unless($this->access->canEdit($user), 403);

        $this->decisions->delete($user, $decision);

        return back()->with('success', __('procynia.management_review.flash.decision_deleted'));
    }

    public function status(Request $request, int $reviewId, int $decisionId): RedirectResponse
    {
        [$user, $review, $decision] = $this->decision($reviewId, $decisionId);
        abort_unless($this->decisions->canFollowUp($user, $decision), 403);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ManagementReviewDecision::STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->decisions->changeStatus($user, $decision, $validated['status'], $validated['note'] ?? null);

        return back()->with('success', __('procynia.management_review.flash.action_'.$validated['status']));
    }

    public function reassign(Request $request, int $reviewId, int $decisionId): RedirectResponse
    {
        [$user, $review, $decision] = $this->decision($reviewId, $decisionId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->decisions->reassign($user, $decision, (int) $validated['owner_user_id'], $validated['due_date']);

        return back()->with('success', __('procynia.management_review.flash.decision_updated'));
    }

    public function handOff(Request $request, int $reviewId, int $decisionId): RedirectResponse
    {
        [$user, $review, $decision] = $this->decision($reviewId, $decisionId);
        abort_unless($this->access->canEdit($user), 403);

        $rules = ImprovementCaseCreator::rules();
        $validated = $request->validate([
            'title' => $rules['title'],
            'description' => $rules['description'],
            'business_area_id' => $rules['business_area_id'],
            'owner_user_id' => $rules['owner_user_id'],
            'due_date' => $rules['due_date'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->decisions->handOff($user, $decision, $validated);

        return back()->with('success', __('procynia.management_review.flash.handed_off'));
    }

    public function link(Request $request, int $reviewId, int $decisionId): RedirectResponse
    {
        [$user, $review, $decision] = $this->decision($reviewId, $decisionId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'improvement_case_id' => ['required', 'integer'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->decisions->link($user, $decision, (int) $validated['improvement_case_id']);

        return back()->with('success', __('procynia.management_review.flash.linked'));
    }

    /** @return array{0: User, 1: ManagementReview} */
    private function review(int $reviewId): array
    {
        $user = $this->customerContext->currentUser();
        abort_unless($this->access->canOpenModule($user), 403);

        return [$user, $this->access->findVisible($user, $reviewId) ?? abort(404)];
    }

    /** @return array{0: User, 1: ManagementReview, 2: ManagementReviewDecision} */
    private function decision(int $reviewId, int $decisionId): array
    {
        [$user, $review] = $this->review($reviewId);
        $decision = $review->decisions()->whereKey($decisionId)->first() ?? abort(404);

        return [$user, $review, $decision];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $action = $request->input('kind') === ManagementReviewDecision::KIND_ACTION;

        return $request->validate([
            'kind' => ['required', 'string', Rule::in(ManagementReviewDecision::KINDS)],
            'text' => ['required', 'string', 'max:2000'],
            'section_key' => ['nullable', 'string', Rule::in(array_keys(ManagementReviewSectionCatalog::DEFINITIONS))],
            'owner_user_id' => [$action ? 'required' : 'nullable', 'integer'],
            'due_date' => [$action ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());
    }
}
