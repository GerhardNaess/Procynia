<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
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

        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get();

        $allRuns = $documents->isNotEmpty()
            ? EnterpriseWikiIngestRun::query()
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->whereIn('source_id', $documents->pluck('id'))
                ->orderByDesc('id')
                ->with('page:id,title,slug,status')
                ->get()
            : collect();

        $visibleStatuses = $this->visibleStatuses($user);

        $latestRuns = $allRuns
            ->groupBy('source_id')
            ->map(fn($group) => $group->first());

        $pagesPerDocument = $allRuns
            ->filter(fn($run) => $run->enterprise_wiki_page_id !== null && $run->page !== null)
            ->groupBy('source_id')
            ->map(fn($runs) => $runs
                ->map(fn($run) => $run->page)
                ->filter(fn($page) => in_array($page->status, $visibleStatuses, true))
                ->unique('id')
                ->values()
            );

        $sources = $documents->map(fn(EnterpriseWikiDocument $doc) => [
            'id' => $doc->id,
            'original_filename' => $doc->original_filename,
            'document_status' => $doc->document_status,
            'created_at' => $doc->created_at,
            'latest_ingest_run' => $latestRuns->has($doc->id) ? [
                'status' => $latestRuns[$doc->id]->status,
                'error_message' => $latestRuns[$doc->id]->error_message,
            ] : null,
            'generated_pages' => ($pagesPerDocument->get($doc->id) ?? collect())
                ->map(fn($page) => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'status' => $page->status,
                ])
                ->all(),
        ]);

        return Inertia::render('App/Wiki/Index', [
            'pages' => $pages,
            'sources' => $sources,
            'sources_store_url' => route('app.wiki.sources.store'),
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

    /**
     * Submit a page for review (draft → pending_review)
     * or reopen a rejected page for editing (rejected → draft).
     *
     * Restricted to System Owner in pilot. Broader submit roles can be
     * considered in a future phase once the approval flow is validated.
     */
    public function submit(string $slug): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner()) {
            abort(403);
        }

        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        if ($page->status === EnterpriseWikiPage::STATUS_DRAFT) {
            $page->status = EnterpriseWikiPage::STATUS_PENDING_REVIEW;
            $flash = 'Siden er sendt til gjennomgang.';
        } elseif ($page->status === EnterpriseWikiPage::STATUS_REJECTED) {
            $page->status = EnterpriseWikiPage::STATUS_DRAFT;
            $flash = 'Siden er gjenåpnet for redigering.';
        } else {
            abort(422);
        }

        $page->save();

        return redirect()->route('app.wiki.show', $page->slug)->with('success', $flash);
    }

    /**
     * Approve a page in pending_review → approved.
     * System Owner only (pilot). Bid Manager as approver deferred to later phase.
     */
    public function approve(string $slug): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner()) {
            abort(403);
        }

        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        if ($page->status !== EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
            abort(422);
        }

        $page->status = EnterpriseWikiPage::STATUS_APPROVED;
        $page->reviewed_at = now();
        $page->reviewed_by_user_id = $user->id;
        $page->save();

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Wiki-siden er godkjent.');
    }

    /**
     * Reject a page in pending_review → rejected.
     * System Owner only (pilot).
     */
    public function reject(string $slug): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner()) {
            abort(403);
        }

        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        if ($page->status !== EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
            abort(422);
        }

        $page->status = EnterpriseWikiPage::STATUS_REJECTED;
        $page->reviewed_at = now();
        $page->reviewed_by_user_id = $user->id;
        $page->save();

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Wiki-siden er avvist.');
    }

    /** @return list<string> */
    private function visibleStatuses(?User $user): array
    {
        $statuses = [EnterpriseWikiPage::STATUS_APPROVED];

        if ($user?->isSystemOwner() || $user?->isBidManager()) {
            $statuses[] = EnterpriseWikiPage::STATUS_DRAFT;
            $statuses[] = EnterpriseWikiPage::STATUS_PENDING_REVIEW;
        }

        if ($user?->isSystemOwner()) {
            $statuses[] = EnterpriseWikiPage::STATUS_REJECTED;
        }

        return $statuses;
    }
}
