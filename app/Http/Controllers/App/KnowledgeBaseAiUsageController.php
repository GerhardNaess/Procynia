<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeBaseAiUsageService;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseAiUsageController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {}

    /**
     * Purpose: Render the AI usage overview for the customer's knowledge base.
     * Inputs: The current frontend request and the usage service.
     * Returns: An Inertia response with customer-scoped document and chunk aggregates.
     * Side effects: None.
     */
    public function index(Request $request, KnowledgeBaseAiUsageService $usageService): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        $filters = $this->resolveFilters($request);

        $documentRows = $usageService->documentAggregate($customerId, $filters)
            ->map(function (object $row): array {
                $data = (array) $row;
                $data['knowledge_item_show_url'] = route(
                    'app.ai.knowledge-base.show',
                    ['knowledgeItem' => (int) $row->knowledge_item_id],
                );

                return $data;
            });

        $chunkRows = $usageService->chunkAggregate($customerId, $filters)
            ->map(function (object $row): array {
                $data = (array) $row;
                $data['knowledge_item_show_url'] = route(
                    'app.ai.knowledge-base.show',
                    ['knowledgeItem' => (int) $row->knowledge_item_id],
                );

                return $data;
            });

        $evidenceCount = $documentRows->sum(fn (array $row): int => (int) ($row['evidence_count'] ?? 0));

        return Inertia::render('AI/KnowledgeBase/AiUsage', [
            'documentUsageRows' => $documentRows->values()->all(),
            'chunkUsageRows' => $chunkRows->values()->all(),
            'filters' => $filters,
            'summary' => [
                'document_count' => $documentRows->count(),
                'chunk_count' => $chunkRows->count(),
                'evidence_count' => $evidenceCount,
            ],
        ]);
    }

    /**
     * Purpose: Validate that the current request carries a resolvable customer context.
     * Inputs: The incoming frontend request.
     * Returns: The current user and customer id as a two-element array.
     * Side effects: Aborts with HTTP 403 if the customer context is unavailable.
     */
    private function frontendContext(Request $request): array
    {
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    /**
     * Purpose: Extract and validate filter parameters from the request query string.
     * Inputs: The incoming frontend request.
     * Returns: An associative array of validated filter keys and values.
     * Side effects: None.
     */
    private function resolveFilters(Request $request): array
    {
        $filters = [];

        $versionStatus = $request->query('version_status');
        if (in_array($versionStatus, ['current', 'superseded'], true)) {
            $filters['version_status'] = $versionStatus;
        }

        if ($request->boolean('primary_only')) {
            $filters['primary_only'] = true;
        }

        if ($request->filled('date_from')) {
            $filters['date_from'] = $request->query('date_from');
        }

        if ($request->filled('date_to')) {
            $filters['date_to'] = $request->query('date_to');
        }

        return $filters;
    }
}
