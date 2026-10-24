<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\SupplierPresenter;
use App\Services\Suppliers\SupplierAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/suppliers (LEV-01) — the leverandørregister the integration identity may read, a page
 * at a time — GET /api/v1/suppliers/{supplierId} (LEV-02), one supplier with its requirements,
 * aktsomhet, follow-up plan and links, and GET /api/v1/suppliers/control-requirements (LEV-03), the
 * customer's kontrollkrav.
 *
 * Everything is SupplierAccessService for the identity: the customer's whole register under
 * supplier.view, which it holds only through a role the System Owner gave it (an explicit-grant
 * domain), and the module (canReadFromAnotherModule). Another customer's supplier or a missing one is
 * the same 404.
 */
class SupplierController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        SupplierAccessService $access,
        SupplierPresenter $presenter,
    ): JsonResponse {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Supplier::STATUSES)],
            'criticality' => ['sometimes', 'string', Rule::in(Supplier::CRITICALITIES)],
            'category' => ['sometimes', 'string', Rule::in(Supplier::CATEGORIES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $query = $this->suppliers($access, $context)->orderBy('suppliers.id');

        foreach (['status', 'criticality', 'category'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where("suppliers.{$filter}", $validated[$filter]);
            }
        }

        if (isset($validated['cursor'])) {
            $query->where('suppliers.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->rows($page),
            $context,
            [
                'limit' => $limit,
                'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
            ],
        );
    }

    public function show(ApiRequestContext $context, SupplierAccessService $access, SupplierPresenter $presenter, int $supplierId): JsonResponse
    {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $supplier = $this->suppliers($access, $context)->whereKey($supplierId)->first()
            ?? throw (new ModelNotFoundException)->setModel(Supplier::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($context->actor, $supplier), $context);
    }

    public function controlRequirements(ApiRequestContext $context, SupplierAccessService $access, SupplierPresenter $presenter): JsonResponse
    {
        // The catalogue has no access check of its own; the module's is asked here, as on Kontrollkrav.
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $catalogue = $presenter->catalogue($context->actor);
        $context->rows = count($catalogue);

        return ApiResponse::ok($catalogue, $context);
    }

    /** The identity's visible suppliers, with what a row needs — the register's own select. */
    private function suppliers(SupplierAccessService $access, ApiRequestContext $context): Builder
    {
        return $access->visibleSuppliers($context->actor)
            ->select('suppliers.*')
            ->addSelect(['latest_assessed_on' => SupplierPresenter::latestAssessedOn()])
            ->with('owner:id,name');
    }
}
