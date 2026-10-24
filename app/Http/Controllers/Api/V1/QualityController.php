<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\QualityItem;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\QualityPresenter;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/quality/items (KVA-01) — Kvalitet's register: policies, processes, procedures, work
 * instructions, checklists and controls, a page at a time — and GET /api/v1/quality/controls
 * (KVA-03), the controls with frequency, evidence and tools. Both carry the attention codes
 * (KVA-04).
 *
 * GET /api/v1/quality/items/{itemId} (KVA-02) is one item with its relations and publication, and
 * for a process its approved flow — never the working version (D15).
 *
 * Kvalitet is read customer-wide, with no fagområder, under the rule Kvalitet applies to every reader
 * (QualityProcessContextReader::canRead(): the module and quality.view). Another customer's item or
 * a missing one is the same 404.
 */
class QualityController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function items(Request $request, ApiRequestContext $context, QualityProcessContextReader $quality, QualityPresenter $presenter): JsonResponse
    {
        $validated = $this->validated($request, $quality, $context, true);
        [$page, $meta] = $this->page($this->scoped($context)->when(isset($validated['type']), fn (Builder $query) => $query->where('quality_items.quality_type', $validated['type'])), $validated, $context);

        return ApiResponse::ok($presenter->rows($page, (int) $context->actor->customer_id), $context, $meta);
    }

    public function controls(Request $request, ApiRequestContext $context, QualityProcessContextReader $quality, QualityPresenter $presenter): JsonResponse
    {
        $validated = $this->validated($request, $quality, $context, false);
        [$page, $meta] = $this->page(
            $this->scoped($context)->where('quality_items.quality_type', QualityItem::TYPE_CONTROL)->with('controlDetail:id,quality_item_id,frequency'),
            $validated,
            $context,
        );

        return ApiResponse::ok($presenter->controlRows($page, (int) $context->actor->customer_id), $context, $meta);
    }

    public function show(ApiRequestContext $context, QualityProcessContextReader $quality, QualityPresenter $presenter, int $itemId): JsonResponse
    {
        if (! $quality->canRead($context->actor)) {
            throw new AuthorizationException;
        }

        $item = $this->scoped($context)->whereKey($itemId)->first()
            ?? throw (new ModelNotFoundException)->setModel(QualityItem::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($item), $context);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, QualityProcessContextReader $quality, ApiRequestContext $context, bool $byType): array
    {
        if (! $quality->canRead($context->actor)) {
            throw new AuthorizationException;
        }

        return $request->validate([
            ...($byType ? ['type' => ['sometimes', 'string', Rule::in(QualityItem::TYPES)]] : []),
            'status' => ['sometimes', 'string', Rule::in(QualityItem::STATUSES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);
    }

    /** The identity's customer's items — Kvalitet has no other boundary. */
    private function scoped(ApiRequestContext $context): Builder
    {
        return QualityItem::query()
            ->where('quality_items.customer_id', (int) $context->actor->customer_id)
            ->with('owner:id,name')
            ->orderBy('quality_items.id');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Collection<int, QualityItem>, 1: array{limit: int, next_cursor: ?string}}
     */
    private function page(Builder $query, array $validated, ApiRequestContext $context): array
    {
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        if (isset($validated['status'])) {
            $query->where('quality_items.status', $validated['status']);
        }

        if (isset($validated['cursor'])) {
            $query->where('quality_items.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return [$page, ['limit' => $limit, 'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null]];
    }
}
