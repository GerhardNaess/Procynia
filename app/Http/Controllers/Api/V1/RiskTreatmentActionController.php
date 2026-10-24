<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RiskTreatmentAction;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskTreatmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * POST /api/v1/risks/{riskId}/treatment-actions — the first write operation of the API.
 *
 * Only the request is handled here. The risk is found through RiskAccessService::findVisible(), so
 * a risk of another customer or outside the identity's fagområder is a 404 exactly as in the list.
 * Whether the integration may create a tiltak there is RiskAccessService's answer
 * (canCreateTreatmentAction: risk.view in the area and the operation the System Owner allowed),
 * and the tiltak is created by RiskTreatmentService::create() — the same service, rules,
 * responsible-person check and owner notification as the risk page. It is a real, open tiltak.
 *
 * Not idempotent: repeating the request creates another tiltak.
 */
class RiskTreatmentActionController extends Controller
{
    /** The fields the API accepts. outcome_note is written when a tiltak is completed, which the API cannot do. */
    private const FIELDS = ['title', 'owner_user_id', 'due_at'];

    public function store(
        Request $request,
        ApiRequestContext $context,
        RiskAccessService $access,
        RiskTreatmentService $treatments,
        int $riskId,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $risk = $access->findVisible($actor, $riskId)
            ?? throw (new ModelNotFoundException)->setModel(RiskTreatmentAction::class);

        if (! $access->canCreateTreatmentAction($actor, $risk)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate(Arr::only(RiskTreatmentService::rules(), self::FIELDS));

        $action = $treatments->create($actor, $risk, Arr::only($validated, self::FIELDS));
        $action->load('owner:id,name');
        $context->rows = 1;

        return ApiResponse::created([
            'id' => (int) $action->id,
            'risk_id' => (int) $action->risk_id,
            'title' => $action->title,
            'status' => $action->status,
            'owner' => $action->owner !== null ? ['id' => (int) $action->owner->id, 'name' => $action->owner->name] : null,
            'due_at' => $action->due_at?->toDateString(),
            'created_at' => $action->created_at?->utc()->toIso8601ZuluString(),
        ], $context);
    }
}
