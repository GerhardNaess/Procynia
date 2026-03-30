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

        $suppliers = DoffinSupplier::query()
            ->withListingMetrics()
            ->searchListing($search)
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $suppliers->setCollection(
            $suppliers->getCollection()->map(fn (DoffinSupplier $supplier): array => [
                'id' => $supplier->id,
                'supplier_name' => $supplier->supplier_name,
                'organization_number' => $supplier->organization_number,
                'notices_count' => (int) ($supplier->notices_count ?? 0),
                'updated_at' => optional($supplier->updated_at)?->toIso8601String(),
                'view_url' => route('app.suppliers.show', ['supplier' => $supplier->id]),
            ]),
        );

        return Inertia::render('App/Suppliers/Index', [
            'filters' => [
                'search' => $search,
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
            'linkedNotices' => $record->noticeSuppliers()
                ->with('notice')
                ->orderByDesc('id')
                ->get()
                ->map(fn ($link): array => [
                    'id' => $link->id,
                    'notice_id' => $link->notice?->notice_id,
                    'heading' => $link->notice?->heading,
                    'buyer_name' => $link->notice?->buyer_name,
                    'publication_date' => optional($link->notice?->publication_date)?->toIso8601String(),
                    'source' => $link->source,
                    'winner_lots' => filled($link->winner_lots_json)
                        ? array_values($link->winner_lots_json)
                        : [],
                    'show_url' => $link->notice ? route('app.notices.show', ['notice' => $link->notice->id]) : null,
                ])
                ->all(),
        ]);
    }
}
