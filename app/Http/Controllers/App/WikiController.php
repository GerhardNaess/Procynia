<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use App\Support\CustomerContext;
use Inertia\Inertia;
use Inertia\Response;

class WikiController extends Controller
{
    public function __construct(private readonly CustomerContext $customerContext) {}

    public function index(): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $this->visibleStatuses($user))
            ->with('currentVersion')
            ->withCount('claims')
            ->orderBy('title')
            ->get()
            ->map(fn(EnterpriseWikiPage $page) => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'current_version_id' => $page->currentVersion?->id,
                'claims_count' => $page->claims_count,
                'updated_at' => $page->updated_at,
            ]);

        return Inertia::render('App/Wiki/Index', [
            'pages' => $pages,
        ]);
    }

    public function show(string $slug): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->whereIn('status', $this->visibleStatuses($user))
            ->first() ?? abort(404);

        $currentVersion = $page->currentVersion()->first();

        $claims = [];
        if ($currentVersion !== null) {
            $claims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $currentVersion->id)
                ->with('sourceReferences')
                ->orderBy('position_order')
                ->get()
                ->map(fn(EnterpriseWikiClaim $claim) => [
                    'id' => $claim->id,
                    'claim_text' => $claim->claim_text,
                    'confidence' => $claim->confidence,
                    'conflict_flag' => $claim->conflict_flag,
                    'approval_status' => $claim->approval_status,
                    'position_order' => $claim->position_order,
                    'source_references' => $claim->sourceReferences
                        ->map(fn($ref) => [
                            'id' => $ref->id,
                            'source_type' => $ref->source_type,
                            'source_label' => $ref->source_label,
                            'excerpt' => $ref->excerpt,
                        ])
                        ->all(),
                ])
                ->all();
        }

        return Inertia::render('App/Wiki/Show', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'generated_by' => $page->generated_by,
                'reviewed_at' => $page->reviewed_at,
                'updated_at' => $page->updated_at,
            ],
            'current_version' => $currentVersion !== null ? [
                'id' => $currentVersion->id,
                'version_number' => $currentVersion->version_number,
                'content_markdown' => $currentVersion->content_markdown,
            ] : null,
            'claims' => $claims,
        ]);
    }

    /** @return list<string> */
    private function visibleStatuses(?User $user): array
    {
        $statuses = [EnterpriseWikiPage::STATUS_APPROVED];

        if ($user?->isSystemOwner() || $user?->isBidManager()) {
            $statuses[] = EnterpriseWikiPage::STATUS_DRAFT;
            $statuses[] = EnterpriseWikiPage::STATUS_PENDING_REVIEW;
        }

        return $statuses;
    }
}
