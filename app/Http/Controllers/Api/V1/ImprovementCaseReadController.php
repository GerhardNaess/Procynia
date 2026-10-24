<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\ImprovementCasePresenter;
use App\Services\Improvements\ImprovementCaseAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The cases of one type in Avvik og forbedringer — a page at a time, and one with its tiltak.
 *
 * Avvik (DeviationController) and forbedringer (ImprovementController) are the same model,
 * ImprovementCase, read under the same rule: ImprovementCaseAccessService::visibleCases() for the
 * identity, exactly as the register builds it for a person — the customer, improvement.view and the
 * identity's fagområder. Each endpoint narrows that to its own type, so a case of the other type is
 * a 404 like any case the identity cannot see. Filters and the cursor narrow the list and cannot
 * widen it, so another customer's fagområde or cursor finds nothing.
 */
abstract class ImprovementCaseReadController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    /** ImprovementCase::TYPE_DEVIATION or TYPE_IMPROVEMENT. */
    abstract protected function type(): string;

    public function index(
        Request $request,
        ApiRequestContext $context,
        ImprovementCaseAccessService $access,
        ImprovementCasePresenter $presenter,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(ImprovementCase::STATUSES)],
            'business_area_id' => ['sometimes', 'integer', 'min:1'],
            'overdue' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $today = $this->today();

        $query = $this->cases($access, $context)->orderBy('improvement_cases.id');

        if (isset($validated['status'])) {
            $query->where('improvement_cases.status', $validated['status']);
        }

        if (isset($validated['business_area_id'])) {
            $query->where('improvement_cases.business_area_id', (int) $validated['business_area_id']);
        }

        if (isset($validated['overdue'])) {
            // Frist passert: an active case whose frist is before today — the attention rule.
            $overdue = fn ($inner) => $inner
                ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
                ->whereNotNull('improvement_cases.due_date')
                ->where('improvement_cases.due_date', '<', $today->toDateString());

            $validated['overdue'] === 'true' ? $query->where($overdue) : $query->whereNot($overdue);
        }

        if (isset($validated['cursor'])) {
            $query->where('improvement_cases.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->rows($page, $today),
            $context,
            [
                'limit' => $limit,
                'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
            ],
        );
    }

    public function show(
        ApiRequestContext $context,
        ImprovementCaseAccessService $access,
        ImprovementCasePresenter $presenter,
        int $caseId,
    ): JsonResponse {
        if (! $access->canOpenModule($context->actor)) {
            throw new AuthorizationException;
        }

        $case = $this->cases($access, $context)
            ->whereKey($caseId)
            ->with(['actions.owner:id,name', 'actions.verifications'])
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(ImprovementCase::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($case, $this->today()), $context);
    }

    /** The identity's visible cases of this endpoint's type, with what a row needs. */
    private function cases(ImprovementCaseAccessService $access, ApiRequestContext $context): Builder
    {
        return $access->visibleCases($context->actor)
            ->where('improvement_cases.type', $this->type())
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->withCount([
                'actions as action_count',
                'actions as open_action_count' => fn ($actions) => $actions->whereIn('status', ImprovementAction::ACTIVE_STATUSES),
            ]);
    }

    /** The day the register and «Trenger oppmerksomhet» judge a frist by. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(now()->toDateString());
    }
}
