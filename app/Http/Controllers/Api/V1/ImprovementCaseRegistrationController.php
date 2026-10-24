<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\ImprovementCasePresenter;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseCreator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two registrations an integration may make in Avvik og forbedringer, each a named operation:
 *
 *   POST /api/v1/deviations, POST /api/v1/improvements                   improvement.case.create
 *   POST /api/v1/deviations/{caseId}/actions, …/improvements/{caseId}/actions  improvement.action.create
 *
 * The type comes from the path (route default), as for reading. Only the request is handled here.
 * Whether the integration may register is ImprovementCaseAccessService's answer (canRegisterIn,
 * canCreateAction: improvement.view in the fagområde and the operation the System Owner allowed),
 * asked again by the domain services, which do the work with the same rules as the web pages:
 * ImprovementCaseCreator::create() writes an open case, ImprovementActionLifecycleService::create()
 * a planned tiltak, the responsible person is checked there and told by the usual assignment
 * notification. Nothing is decided: closing, cancelling, verifying and deleting stay with people.
 *
 * Field names follow the read representation (occurred_on, due_on). Not idempotent: repeating a
 * request registers another case or tiltak.
 */
class ImprovementCaseRegistrationController extends Controller
{
    public function store(
        Request $request,
        ApiRequestContext $context,
        ImprovementCaseAccessService $access,
        ImprovementCaseCreator $creator,
        ImprovementCasePresenter $presenter,
        string $type,
    ): JsonResponse {
        $actor = $context->actor;

        // Not allowed to register at all: 403. Which fagområde is the creator's question (422).
        if (! $access->canOpenModule($actor) || ! $access->canRegister($actor)) {
            throw new AuthorizationException;
        }

        $rules = ImprovementCaseCreator::rules();
        $fields = [
            'title' => $rules['title'],
            'description' => $rules['description'],
            'business_area_id' => $rules['business_area_id'],
            'owner_user_id' => $rules['owner_user_id'],
            'due_on' => $rules['due_date'],
        ];

        // A forbedring has no Hendelsesdato; it is not accepted for one.
        if ($type === ImprovementCase::TYPE_DEVIATION) {
            $fields['occurred_on'] = $rules['occurred_at'];
        }

        $validated = $request->validate($fields);

        $case = $creator->create($actor, [
            'type' => $type,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'business_area_id' => (int) $validated['business_area_id'],
            'owner_user_id' => (int) $validated['owner_user_id'],
            'occurred_at' => $validated['occurred_on'] ?? null,
            'due_date' => $validated['due_on'] ?? null,
        ]);

        $created = $access->visibleCases($actor)
            ->whereKey($case->id)
            ->with(['businessArea:id,name', 'owner:id,name', 'actions.owner:id,name', 'actions.verifications'])
            ->withCount([
                'actions as action_count',
                'actions as open_action_count' => fn ($actions) => $actions->whereIn('status', ImprovementAction::ACTIVE_STATUSES),
            ])
            ->firstOrFail();
        $context->rows = 1;

        return ApiResponse::created($presenter->detail($created, $this->today()), $context);
    }

    public function storeAction(
        Request $request,
        ApiRequestContext $context,
        ImprovementCaseAccessService $access,
        ImprovementActionLifecycleService $actions,
        ImprovementCasePresenter $presenter,
        // Path parameters first, then the route default.
        int $caseId,
        string $type,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        // A case of the other type, another customer or outside the fagområder is not there.
        $case = $access->visibleCases($actor)
            ->where('improvement_cases.type', $type)
            ->whereKey($caseId)
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(ImprovementCase::class);

        if (! $access->canCreateAction($actor, $case)) {
            throw new AuthorizationException;
        }

        $rules = ImprovementActionLifecycleService::rules();
        $validated = $request->validate([
            'title' => $rules['title'],
            'description' => $rules['description'],
            'owner_user_id' => $rules['owner_user_id'],
            'due_on' => $rules['due_date'],
        ]);

        // A closed or cancelled case takes no tiltak: the service's 422, as on the case page.
        $action = $actions->create($case, $actor, ImprovementActionLifecycleService::fields([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'owner_user_id' => $validated['owner_user_id'],
            'due_date' => $validated['due_on'],
        ]));

        $action->load(['owner:id,name', 'verifications']);
        $context->rows = 1;

        return ApiResponse::created($presenter->createdAction($action, $case->fresh(), $this->today()), $context);
    }

    /** The day the register and «Trenger oppmerksomhet» judge a frist by. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(now()->toDateString());
    }
}
