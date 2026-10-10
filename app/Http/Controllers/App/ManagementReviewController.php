<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BusinessArea;
use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\ManagementReview\ManagementReviewBasisService;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewPresenter;
use App\Services\ManagementReview\ManagementReviewSectionCatalog;
use App\Services\ManagementReview\ManagementReviewService;
use App\Support\CustomerContext;
use App\Support\ManagementReview\ManagementReviewValidationMessages;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ledelsens gjennomgåelse (docs/management-review-v1-plan.md).
 *
 * Every read starts from ManagementReviewAccessService::visibleReviews(); another customer's review is
 * a 404 like an id that does not exist. Without management_review.view (or without the module) every
 * route is a 403 before any id is looked at. What a review shows from other modules is gated again,
 * per section and per reader, in ManagementReviewPresenter.
 */
class ManagementReviewController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ManagementReviewAccessService $access,
        private readonly ManagementReviewService $reviews,
        private readonly ManagementReviewDecisionService $decisions,
        private readonly ManagementReviewFinalizationService $finalization,
        private readonly ManagementReviewPresenter $presenter,
        private readonly ManagementReviewBasisService $basis,
        private readonly ManagementReviewSectionCatalog $catalog,
    ) {}

    public function index(): Response
    {
        $user = $this->authorizedUser();

        $reviews = $this->access->visibleReviews($user)
            ->with('owner:id,name')
            ->withCount([
                'decisions',
                'decisions as open_actions_count' => fn ($query) => $query->where('follow_up', ManagementReviewDecision::FOLLOW_UP_OWN)->where('status', ManagementReviewDecision::STATUS_OPEN),
            ])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ManagementReview::STATUS_DRAFT])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->get();

        $latestFinalized = $reviews->firstWhere('status', ManagementReview::STATUS_FINALIZED);

        return Inertia::render('App/ManagementReview/Index', [
            'reviews' => $reviews->map(fn (ManagementReview $review): array => [
                'id' => (int) $review->id,
                'title' => $review->title,
                'status' => $review->status,
                'period_start' => $review->period_start?->toDateString(),
                'period_end' => $review->period_end?->toDateString(),
                'meeting_date' => $review->meeting_date?->toDateString(),
                'owner_name' => $review->owner?->name,
                'decisions_count' => (int) $review->decisions_count,
                'open_actions_count' => (int) $review->open_actions_count,
                'finalized_at' => $review->finalized_at?->toIso8601String(),
                'url' => route('app.management-review.show', ['reviewId' => $review->id]),
            ])->values()->all(),
            'next_review' => $latestFinalized?->next_review_due_on !== null ? [
                'due_on' => $latestFinalized->next_review_due_on->toDateString(),
                'overdue' => $latestFinalized->next_review_due_on->toDateString() < CarbonImmutable::today()->toDateString(),
                'decided_in' => $latestFinalized->title,
                'has_draft' => $reviews->contains(fn (ManagementReview $review): bool => $review->isDraft()),
            ] : null,
            'open_actions' => $this->openActions($user),
            'comparison' => $this->comparison($user, $reviews->where('status', ManagementReview::STATUS_FINALIZED)->take(4)->reverse()->values()),
            'permissions' => ['can_create' => $this->access->canEdit($user)],
            'create_defaults' => $this->access->canEdit($user) ? $this->createDefaults($user, $latestFinalized) : null,
            'owner_options' => $this->access->canEdit($user) ? $this->access->ownerCandidates($user) : [],
            'participant_options' => $this->access->canEdit($user) ? $this->participantOptions($user) : [],
            'area_options' => $this->areaOptions($user),
            'framework_options' => array_keys((array) config('management_review.frameworks', [])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canEdit($user), 403);

        $review = $this->reviews->create($user, $this->validated($request, true));

        return redirect()->route('app.management-review.show', ['reviewId' => $review->id])
            ->with('success', __('procynia.management_review.flash.created'));
    }

    public function show(int $reviewId): Response
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        $canEdit = $review->isDraft() && $this->access->canEdit($user);

        return Inertia::render('App/ManagementReview/Show', $this->presenter->show($user, $review) + [
            'owner_options' => $this->access->canEdit($user) ? $this->access->ownerCandidates($user) : [],
            'participant_options' => $canEdit ? $this->participantOptions($user) : [],
            'area_options' => $canEdit ? $this->areaOptions($user) : [],
            'framework_options' => array_keys((array) config('management_review.frameworks', [])),
            'judgements' => ManagementReview::JUDGEMENTS,
            'handoff_options' => $canEdit ? $this->decisions->handOffOptions($user) : null,
        ]);
    }

    public function update(Request $request, int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $this->reviews->update($user, $review, $this->validated($request, false));

        return back()->with('success', __('procynia.management_review.flash.updated'));
    }

    public function conclusion(Request $request, int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'conclusion' => ['nullable', 'string', 'max:20000'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->reviews->updateConclusion($user, $review, $validated['conclusion'] ?? null);

        return back()->with('success', __('procynia.management_review.flash.saved'));
    }

    public function destroy(int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canDelete($user), 403);

        $this->reviews->delete($user, $review);

        return redirect()->route('app.management-review.index')->with('success', __('procynia.management_review.flash.deleted'));
    }

    public function storeParticipant(Request $request, int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255', 'required_without:user_id'],
            'role_label' => ['nullable', 'string', 'max:255'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->reviews->addParticipant($review, $validated);

        return back()->with('success', __('procynia.management_review.flash.participant_added'));
    }

    public function destroyParticipant(int $reviewId, int $participantId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $participant = $review->participants()->whereKey($participantId)->first() ?? abort(404);
        $this->reviews->removeParticipant($review, $participant);

        return back()->with('success', __('procynia.management_review.flash.participant_removed'));
    }

    public function updateSection(Request $request, int $reviewId, string $sectionKey): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'judgement' => ['nullable', 'string', Rule::in(ManagementReview::JUDGEMENTS)],
            'comment' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->reviews->saveSection($user, $review, $sectionKey, $validated['judgement'] ?? null, $validated['comment'] ?? null, $validated['notes'] ?? null);

        return back()->with('success', __('procynia.management_review.flash.saved'));
    }

    public function finalize(int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canFinalize($user), 403);

        $this->finalization->finalize($user, $review);

        return back()->with('success', __('procynia.management_review.flash.finalized'));
    }

    public function nextReview(Request $request, int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'next_review_due_on' => ['nullable', 'date_format:Y-m-d'],
            'owner_user_id' => ['nullable', 'integer'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->reviews->updateNextReview($user, $review, $validated['next_review_due_on'] ?? null);

        if (! empty($validated['owner_user_id']) && (int) $validated['owner_user_id'] !== (int) $review->owner_user_id) {
            $this->reviews->updateOwner($user, $review, (int) $validated['owner_user_id']);
        }

        return back()->with('success', __('procynia.management_review.flash.saved'));
    }

    public function storeAmendment(Request $request, int $reviewId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $review = $this->visibleOrFail($user, $reviewId);
        abort_unless($this->access->canFinalize($user), 403);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:10000'],
            'reason' => ['required', 'string', 'max:5000'],
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());

        $this->reviews->addAmendment($user, $review, $validated['text'], $validated['reason']);

        return back()->with('success', __('procynia.management_review.flash.amended'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();
        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleOrFail(User $user, int $reviewId): ManagementReview
    {
        return $this->access->findVisible($user, $reviewId) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'all_business_areas' => ['required', 'boolean'],
            'business_area_ids' => ['nullable', 'array'],
            'business_area_ids.*' => ['integer'],
            'frameworks' => ['nullable', 'array'],
            'frameworks.*' => ['string', Rule::in(array_keys((array) config('management_review.frameworks', [])))],
            'owner_user_id' => ['required', 'integer'],
            ...($creating ? ['participant_user_ids' => ['nullable', 'array'], 'participant_user_ids.*' => ['integer']] : []),
        ], ManagementReviewValidationMessages::messages(), ManagementReviewValidationMessages::attributes());
    }

    /** @return array<string, mixed> */
    private function createDefaults(User $user, ?ManagementReview $latestFinalized): array
    {
        $today = CarbonImmutable::today();
        $start = $latestFinalized !== null
            ? CarbonImmutable::parse($latestFinalized->period_end->toDateString())->addDay()
            : $today->subYear()->addDay();

        if ($start->gt($today)) {
            $start = $today;
        }

        return [
            'title' => __('procynia.management_review.default_title', ['year' => $today->year]),
            'period_start' => $start->toDateString(),
            'period_end' => $today->toDateString(),
            'frameworks' => array_values((array) ($latestFinalized?->frameworks ?? [])),
            'owner_user_id' => $this->access->isValidOwner($user, (int) $user->customer_id) ? (int) $user->id : null,
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function areaOptions(User $user): array
    {
        return BusinessArea::query()
            ->forCustomer((int) $user->customer_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessArea $area): array => ['id' => (int) $area->id, 'name' => $area->name])
            ->all();
    }

    /** Active people of the customer — a participant needs no access to Procynia's reviews. */
    private function participantOptions(User $user): array
    {
        return User::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $person): array => ['id' => (int) $person->id, 'name' => $person->name])
            ->all();
    }

    /**
     * «Åpne tiltak fra ledelsens gjennomgåelser»: every tiltak still open, whichever review it came
     * from. A tiltak in Avvik og forbedringer shows its case only to someone who can read it there.
     *
     * @return list<array<string, mixed>>
     */
    private function openActions(User $user): array
    {
        $today = CarbonImmutable::today();
        $caseAreas = $this->catalog->caseAreaIds($user);

        return ManagementReviewDecision::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('kind', ManagementReviewDecision::KIND_ACTION)
            ->where(fn ($query) => $query
                ->where('status', ManagementReviewDecision::STATUS_OPEN)
                ->orWhereHas('improvementCase', fn ($cases) => $cases->whereIn('status', ImprovementCase::ACTIVE_STATUSES)))
            ->with(['review:id,title', 'owner:id,name', 'improvementCase'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(function (ManagementReviewDecision $decision) use ($today, $caseAreas): array {
                $case = $decision->improvementCase;
                $visible = $case !== null && in_array((int) $case->business_area_id, $caseAreas, true);

                return [
                    'id' => (int) $decision->id,
                    'text' => $decision->text,
                    'review_title' => $decision->review?->title,
                    'url' => route('app.management-review.show', ['reviewId' => $decision->management_review_id]).'#decision-'.$decision->id,
                    'follow_up' => $decision->follow_up,
                    'owner_name' => $decision->isFollowedUpHere() ? $decision->owner?->name : ($visible ? $case->owner?->name : null),
                    'due_date' => $decision->isFollowedUpHere() ? $decision->due_date?->toDateString() : ($visible ? $case->due_date?->toDateString() : null),
                    'overdue' => $decision->isOverdue($today),
                    'case' => $decision->isInImprovements() ? ($visible ? ['title' => $case->title, 'url' => route('app.improvements.show', ['caseId' => $case->id]), 'status' => $case->status] : ['hidden' => true]) : null,
                ];
            })
            ->all();
    }

    /**
     * «Utvikling over tid»: the management's judgement per section in the latest finalized reviews,
     * each shown only where the reader may see that section's basis.
     *
     * @param  Collection<int, ManagementReview>  $reviews  oldest first
     * @return array{reviews: list<array{id: int, title: string}>, rows: list<array{key: string, cells: list<?string>}>}|null
     */
    private function comparison(User $user, $reviews): ?array
    {
        if ($reviews->count() < 2) {
            return null;
        }

        $rows = [];

        foreach ($reviews as $index => $review) {
            $views = $this->basis->snapshot($user, $review);
            $judgements = $review->sections()->pluck('judgement', 'section_key');

            foreach ($views as $key => $view) {
                $visible = ! $this->catalog->judgementFollowsBasis($key) || $view['state'] === ManagementReviewSectionCatalog::STATE_AVAILABLE;
                $rows[$key] ??= array_fill(0, $reviews->count(), null);
                $rows[$key][$index] = $visible ? ($judgements[$key] ?? null) : 'hidden';
            }
        }

        return [
            'reviews' => $reviews->map(fn (ManagementReview $review): array => ['id' => (int) $review->id, 'title' => $review->title])->values()->all(),
            'rows' => collect(array_keys(ManagementReviewSectionCatalog::DEFINITIONS))
                ->filter(fn (string $key): bool => isset($rows[$key]))
                ->map(fn (string $key): array => ['key' => $key, 'cells' => $rows[$key]])
                ->values()
                ->all(),
        ];
    }
}
