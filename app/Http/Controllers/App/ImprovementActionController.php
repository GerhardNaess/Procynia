<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Support\CustomerContext;
use App\Support\Improvements\ImprovementValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tiltak on one avvik or forbedring: Nytt tiltak, Rediger, Slett, Start, Fullfør, Avbryt, Gjenåpne.
 * They are shown on the case page (ImprovementCaseController::show()); this controller only writes.
 *
 * A tiltak is reached through its case only: the case comes from
 * ImprovementCaseAccessService::visibleCases(), and the tiltak must belong to that case. A case
 * outside the user's fagområder, another customer's case, or a tiltak id that is not the case's is
 * the same 404 — nothing says the tiltak exists.
 *
 * Every write takes improvement.edit in the case's area — except Verifiser effekt, which takes
 * improvement.close: judging whether a tiltak worked is a decision about the outcome, like closing
 * the case, not part of doing the work. improvement.edit alone never verifies.
 */
class ImprovementActionController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ImprovementCaseAccessService $access,
        private readonly ImprovementActionLifecycleService $lifecycle,
    ) {}

    public function store(Request $request, int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);
        $fields = $this->validated($request, $user, $case);

        $this->lifecycle->create($case, $user, $fields);

        return back()->with('success', __('procynia.improvements.actions.flash.created'));
    }

    public function update(Request $request, int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);
        $action = $this->actionOrFail($case, $actionId);
        $fields = $this->validated($request, $user, $case);

        $this->lifecycle->update($action, $user, $fields);

        return back()->with('success', __('procynia.improvements.actions.flash.updated'));
    }

    public function destroy(int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);
        $action = $this->actionOrFail($case, $actionId);

        try {
            $this->lifecycle->delete($action);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        return back()->with('success', __('procynia.improvements.actions.flash.deleted'));
    }

    /** Start tiltak: planned → in_progress. No note. */
    public function start(int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);

        $this->lifecycle->start($this->actionOrFail($case, $actionId), $user);

        return back()->with('success', __('procynia.improvements.actions.flash.started'));
    }

    /** Fullfør tiltak: «Hva ble gjort?» is the point of completing, so it is required. */
    public function complete(Request $request, int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);
        $action = $this->actionOrFail($case, $actionId);

        $validated = $request->validate([
            'completion_note' => ['required', 'string', 'max:5000'],
        ], $this->messages(), ImprovementValidationMessages::attributes());

        $this->lifecycle->complete($action, $user, $validated['completion_note']);

        return back()->with('success', __('procynia.improvements.actions.flash.completed'));
    }

    /** Avbryt tiltak: it will not be done. Always says why. */
    public function cancel(Request $request, int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);
        $action = $this->actionOrFail($case, $actionId);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], $this->messages(), ImprovementValidationMessages::attributes());

        $this->lifecycle->cancel($action, $user, $validated['reason']);

        return back()->with('success', __('procynia.improvements.actions.flash.cancelled'));
    }

    /** Gjenåpne tiltak: undoes a completion or a cancellation, which stays in the history. */
    public function reopen(Request $request, int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->editableCaseOrFail($user, $caseId);
        $action = $this->actionOrFail($case, $actionId);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], $this->messages(), ImprovementValidationMessages::attributes());

        $this->lifecycle->reopen($action, $user, $validated['reason']);

        return back()->with('success', __('procynia.improvements.actions.flash.reopened'));
    }

    /**
     * Verifiser effekt: Effekt bekreftet or Ikke effektivt for the tiltak's current completion, with
     * a comment either way. improvement.close in the case's area. Ikke effektivt leaves the tiltak
     * completed and says so; reopening it is the person's own next step.
     */
    public function verify(Request $request, int $caseId, int $actionId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->access->findVisible($user, $caseId) ?? abort(404);

        abort_unless($this->access->canClose($user, $case), 403);

        $action = $this->actionOrFail($case, $actionId);

        $validated = $request->validate([
            'result' => ['required', 'string', Rule::in(ImprovementActionVerification::RESULTS)],
            'note' => ['required', 'string', 'max:5000'],
        ], $this->messages(), ImprovementValidationMessages::attributes());

        $verification = $this->lifecycle->verify($action, $user, $validated['result'], $validated['note']);

        return back()->with('success', __('procynia.improvements.actions.flash.'.($verification->isEffective() ? 'verified_effective' : 'verified_not_effective')));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    /** A visible case (404 otherwise), and edit authority in its area (403 otherwise). */
    private function editableCaseOrFail(User $user, int $caseId): ImprovementCase
    {
        $case = $this->access->findVisible($user, $caseId) ?? abort(404);

        abort_unless($this->access->canEdit($user, $case), 403);

        return $case;
    }

    /** Only a tiltak of this very case. Any other id — another case's, another customer's — is a 404. */
    private function actionOrFail(ImprovementCase $case, int $actionId): ImprovementAction
    {
        return ImprovementAction::query()
            ->where('customer_id', (int) $case->customer_id)
            ->where('improvement_case_id', (int) $case->id)
            ->whereKey($actionId)
            ->first() ?? abort(404);
    }

    /**
     * The fields of Nytt tiltak and Rediger. Status is not among them: a tiltak is created planned
     * and moves only through the lifecycle actions. The owner must be able to read cases in the
     * case's area — being responsible gives no access of its own.
     *
     * @return array{title: string, description: ?string, owner_user_id: int, due_date: string}
     */
    private function validated(Request $request, User $user, ImprovementCase $case): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'owner_user_id' => ['required', 'integer'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ], $this->messages(), ImprovementValidationMessages::attributes());

        $owner = User::query()->where('customer_id', (int) $user->customer_id)->find((int) $validated['owner_user_id']);

        if (! $this->access->isValidOwner($owner, (int) $case->customer_id, (int) $case->business_area_id)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.improvements.actions.validation.owner_not_allowed'),
            ]);
        }

        $description = trim((string) ($validated['description'] ?? ''));

        return [
            'title' => trim($validated['title']),
            'description' => $description !== '' ? $description : null,
            'owner_user_id' => (int) $owner->id,
            'due_date' => $validated['due_date'],
        ];
    }

    /** @return array<string, mixed> */
    private function messages(): array
    {
        return ImprovementValidationMessages::messages() + [
            'completion_note.required' => __('procynia.improvements.actions.validation.completion_required'),
            'reason.required' => __('procynia.improvements.actions.validation.reason_required'),
            'result.required' => __('procynia.improvements.actions.validation.result_required'),
            'result.in' => __('procynia.improvements.actions.validation.result_required'),
            'note.required' => __('procynia.improvements.actions.validation.verification_note_required'),
        ];
    }
}
