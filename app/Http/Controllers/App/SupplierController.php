<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\DoffinSupplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purpose:
 * Expose read-only Doffin suppliers in the customer-facing application.
 *
 * Inputs:
 * Customer frontend requests for supplier index and detail pages.
 *
 * Returns:
 * Inertia responses for supplier listing and a simple detail page.
 *
 * Side effects:
 * Reads persisted supplier and linked notice counts from the database.
 */
class SupplierController extends Controller
{
    /**
     * Purpose:
     * Render the customer supplier index using the same supplier data source as admin.
     *
     * Inputs:
     * Search query string from the request.
     *
     * Returns:
     * Inertia\Response
     *
     * Side effects:
     * Loads paginated suppliers with linked notice counts.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $sortField = trim((string) $request->query('sort_field', ''));
        $sortDirection = strtolower(trim((string) $request->query('sort_direction', 'desc')));
        $allowedSortFields = [
            'supplier_name' => 'supplier_name',
            'organization_number' => 'organization_number',
            'notices_count' => 'notices_count',
            'total_estimated_value_amount' => 'total_estimated_value_amount',
            'updated_at' => 'updated_at',
        ];

        if (! array_key_exists($sortField, $allowedSortFields)) {
            $sortField = 'updated_at';
        }

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $suppliers = DoffinSupplier::query()
            ->withListingMetrics()
            ->searchListing($search);

        if ($sortField === 'total_estimated_value_amount') {
            $suppliers->orderByRaw(sprintf('total_estimated_value_amount %s NULLS LAST', $sortDirection));
        } else {
            $suppliers->orderBy($allowedSortFields[$sortField], $sortDirection);
        }

        $suppliers = $suppliers
            ->orderBy('supplier_name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $suppliers->setCollection(
            $suppliers->getCollection()->map(fn (DoffinSupplier $supplier): array => [
                'id' => $supplier->id,
                'supplier_name' => $supplier->supplier_name,
                'organization_number' => $supplier->organization_number,
                'notices_count' => (int) ($supplier->notices_count ?? 0),
                'total_estimated_value_amount' => $supplier->total_estimated_value_amount,
                'total_estimated_value_currency_code' => $supplier->total_estimated_value_amount !== null ? 'NOK' : null,
                'updated_at' => optional($supplier->updated_at)?->toIso8601String(),
                'view_url' => route('app.suppliers.show', ['supplier' => $supplier->id]),
            ]),
        );

        return Inertia::render('App/Suppliers/Index', [
            'filters' => [
                'search' => $search,
                'sort_field' => $sortField,
                'sort_direction' => $sortDirection,
            ],
            'suppliers' => $suppliers,
        ]);
    }

    /**
     * Purpose:
     * Render a simple read-only customer supplier detail stub.
     *
     * Inputs:
     * The requested supplier id from the route.
     *
     * Returns:
     * Inertia\Response
     *
     * Side effects:
     * Loads one persisted supplier and its linked notice count.
     */
    public function show(int $supplier): Response
    {
        $record = DoffinSupplier::query()
            ->withListingMetrics()
            ->whereKey($supplier)
            ->firstOrFail();
        $noticeLinks = $record->noticeSuppliers()
            ->with('notice')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('App/Suppliers/Show', [
            'supplier' => [
                'id' => $record->id,
                'supplier_name' => $record->supplier_name,
                'organization_number' => $record->organization_number,
                'normalized_name' => $record->normalized_name,
                'notices_count' => (int) ($record->notices_count ?? 0),
                'updated_at' => optional($record->updated_at)?->toIso8601String(),
                'back_url' => route('app.suppliers.index'),
            ],
            'linkedNotices' => $noticeLinks
                ->map(function ($link): array {
                    return [
                        'id' => $link->id,
                        'notice_id' => $link->notice?->notice_id,
                        'heading' => $link->notice?->heading,
                        'buyer_name' => $link->notice?->buyer_name,
                        'publication_date' => optional($link->notice?->publication_date)?->toIso8601String(),
                        'source' => $link->source,
                        'estimated_value_amount' => $link->notice?->estimated_value_amount,
                        'estimated_value_currency_code' => $link->notice?->estimated_value_currency_code,
                        'contract_period_text' => null,
                        'short_description' => data_get($link->notice?->raw_payload_json, 'description'),
                        'winner_lots' => filled($link->winner_lots_json)
                            ? array_values($link->winner_lots_json)
                            : [],
                        'show_url' => $link->notice ? route('app.notices.show', ['notice' => $link->notice->id]) : null,
                    ];
                })
                ->all(),
        ]);
    }
}
