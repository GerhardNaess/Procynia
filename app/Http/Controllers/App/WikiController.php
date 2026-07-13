<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiCoverageService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use App\Services\EnterpriseWiki\EnterpriseWikiWikilinkRenderer;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WikiController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiPageTraversalService $traversal,
        private readonly EnterpriseWikiCoverageService $coverageService,
        private readonly EnterpriseWikiWikilinkRenderer $wikilinkRenderer,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $allowedTabs = ['pages', 'sources', 'runs', 'quality'];
        $tab = in_array($request->query('tab'), $allowedTabs, true)
            ? $request->query('tab')
            : 'pages';

        $lintBySeverity = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $customerId)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->selectRaw('severity, count(*) as cnt')
            ->groupBy('severity')
            ->pluck('cnt', 'severity');

        $lintHealth = [
            'error' => (int) ($lintBySeverity[EnterpriseWikiLintFinding::SEVERITY_ERROR] ?? 0),
            'warning' => (int) ($lintBySeverity[EnterpriseWikiLintFinding::SEVERITY_WARNING] ?? 0),
            'info' => (int) ($lintBySeverity[EnterpriseWikiLintFinding::SEVERITY_INFO] ?? 0),
            'total' => (int) $lintBySeverity->sum(),
        ];

        $props = [
            'active_tab' => $tab,
            'lint_health' => $lintHealth,
            'wiki_generation_available' => EnterpriseWikiMaintainerDecisionAiClient::isAvailable(),
            'sources_store_url' => route('app.wiki.sources.store'),
        ];

        $props += match ($tab) {
            'sources' => $this->loadSourcesTab($user, $customerId, $request),
            'runs' => $this->loadRunsTab($customerId, $request),
            'quality' => $this->loadQualityTab($customerId, $request),
            default => $this->loadPagesTab($user, $customerId, $request),
        };

        return Inertia::render('App/Wiki/Index', $props);
    }

    private function loadPagesTab(?User $user, int $customerId, Request $request): array
    {
        $allowedPageTypes = ['article', 'summary', 'concept', 'entity', 'index', 'backlinks'];
        $allowedSorts = ['updated_at_desc', 'title_asc', 'created_at_desc'];
        $allowedLint = ['errors', 'warnings', 'ok'];

        $search = trim((string) $request->query('search', ''));
        $pageType = in_array($request->query('page_type'), $allowedPageTypes, true)
            ? $request->query('page_type') : null;
        $filterStatus = null;
        $requestedStatus = $request->query('status');
        if ($requestedStatus !== null && in_array($requestedStatus, $this->visibleStatuses($user), true)) {
            $filterStatus = $requestedStatus;
        }
        $lint = in_array($request->query('lint'), $allowedLint, true)
            ? $request->query('lint') : null;
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort') : 'updated_at_desc';

        $query = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $this->visibleStatuses($user))
            ->withCount('claims');

        if ($search !== '') {
            $searchLower = strtolower($search);
            $query->where(fn ($sub) => $sub
                ->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$searchLower}%"])
            );
        }

        if ($pageType !== null) {
            $query->where('page_type', $pageType);
        }

        if ($filterStatus !== null) {
            $query->where('status', $filterStatus);
        }

        if ($lint === 'errors') {
            $query->whereHas('lintFindings', fn ($q) => $q
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)
            );
        } elseif ($lint === 'warnings') {
            $query->whereHas('lintFindings', fn ($q) => $q
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->where('severity', EnterpriseWikiLintFinding::SEVERITY_WARNING)
            );
        } elseif ($lint === 'ok') {
            $query->whereDoesntHave('lintFindings', fn ($q) => $q
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            );
        }

        if ($sort === 'title_asc') {
            $query->orderBy('title');
        } elseif ($sort === 'created_at_desc') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('updated_at');
        }

        $paginator = $query->paginate(25);

        $pages = collect($paginator->items())->map(fn (EnterpriseWikiPage $page) => [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'page_type' => $page->page_type,
            'status' => $page->status,
            'claims_count' => $page->claims_count,
            'updated_at' => $page->updated_at,
        ]);

        return [
            'pages' => $pages,
            'pages_meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'pages_filters' => [
                'search' => $search,
                'page_type' => $pageType,
                'status' => $filterStatus,
                'lint' => $lint,
                'sort' => $sort,
            ],
        ];
    }

    private function loadSourcesTab(?User $user, int $customerId, Request $request): array
    {
        $allowedDocStatuses = [
            EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
            EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ];

        $srcSearch = trim((string) $request->query('src_q', ''));
        $srcStatus = in_array($request->query('src_status'), $allowedDocStatuses, true)
            ? $request->query('src_status') : null;

        $docQuery = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at');

        if ($srcSearch !== '') {
            $searchLower = strtolower($srcSearch);
            $docQuery->whereRaw('LOWER(original_filename) LIKE ?', ["%{$searchLower}%"]);
        }

        if ($srcStatus !== null) {
            $docQuery->where('document_status', $srcStatus);
        }

        $documents = $docQuery->get();

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
                'qa_status' => $latestRuns[$doc->id]->qa_status,
                'qa_last_error' => $latestRuns[$doc->id]->qa_last_error,
                'created_at' => $latestRuns[$doc->id]->created_at,
                'started_at' => $latestRuns[$doc->id]->started_at,
                'finished_at' => $latestRuns[$doc->id]->finished_at,
                'maintainer_decision_json' => $latestRuns[$doc->id]->maintainer_decision_json,
                'maintainer_decision_status' => $latestRuns[$doc->id]->maintainer_decision_status,
                'maintainer_decision_generated_at' => $latestRuns[$doc->id]->maintainer_decision_generated_at,
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

        return [
            'sources' => $sources,
            'sources_filters' => [
                'search' => $srcSearch,
                'status' => $srcStatus,
            ],
        ];
    }

    private function loadRunsTab(int $customerId, Request $request): array
    {
        $allowedRunStatuses = EnterpriseWikiIngestRun::STATUSES;
        $allowedDecisionStatuses = [
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'none',
        ];

        $runStatus = in_array($request->query('run_status'), $allowedRunStatuses, true)
            ? $request->query('run_status') : null;
        $runDecision = in_array($request->query('run_decision'), $allowedDecisionStatuses, true)
            ? $request->query('run_decision') : null;
        $runSrc = is_numeric($request->query('run_src'))
            ? (int) $request->query('run_src') : null;

        $lintCountSub = DB::table('enterprise_wiki_lint_findings')
            ->selectRaw('count(*)')
            ->whereColumn('enterprise_wiki_ingest_run_id', 'enterprise_wiki_ingest_runs.id')
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN);

        $query = EnterpriseWikiIngestRun::query()
            ->select('enterprise_wiki_ingest_runs.*')
            ->selectSub($lintCountSub, 'lint_count')
            ->withCount(['sections', 'pages'])
            ->where('enterprise_wiki_ingest_runs.customer_id', $customerId)
            ->where('enterprise_wiki_ingest_runs.source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->orderByDesc('enterprise_wiki_ingest_runs.created_at');

        if ($runStatus !== null) {
            $query->where('enterprise_wiki_ingest_runs.status', $runStatus);
        }

        if ($runDecision === 'none') {
            $query->whereNull('enterprise_wiki_ingest_runs.maintainer_decision_status');
        } elseif ($runDecision !== null) {
            $query->where('enterprise_wiki_ingest_runs.maintainer_decision_status', $runDecision);
        }

        if ($runSrc !== null) {
            $query->where('enterprise_wiki_ingest_runs.source_id', $runSrc);
        }

        $runs = $query->get();

        $documentIds = $runs->pluck('source_id')->unique();
        $docFilenames = $documentIds->isNotEmpty()
            ? EnterpriseWikiDocument::query()
                ->whereIn('id', $documentIds)
                ->where('customer_id', $customerId)
                ->pluck('original_filename', 'id')
            : collect();

        return [
            'runs' => $runs->map(fn(EnterpriseWikiIngestRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'maintainer_decision_status' => $run->maintainer_decision_status,
                'source_document_filename' => $docFilenames->get($run->source_id),
                'source_id' => $run->source_id,
                'error_message' => $run->error_message,
                'qa_status' => $run->qa_status,
                'qa_last_error' => $run->qa_last_error,
                'model_used' => $run->model_used,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
                'pages_count' => (int) ($run->pages_count ?? 0),
                'sections_count' => (int) ($run->sections_count ?? 0),
                'lint_count' => (int) ($run->lint_count ?? 0),
                'created_at' => $run->created_at,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
            ])->all(),
            'runs_filters' => [
                'status' => $runStatus,
                'decision' => $runDecision,
                'src_id' => $runSrc,
            ],
        ];
    }

    private function loadQualityTab(int $customerId, Request $request): array
    {
        $allowedSeverities = [
            EnterpriseWikiLintFinding::SEVERITY_ERROR,
            EnterpriseWikiLintFinding::SEVERITY_WARNING,
            EnterpriseWikiLintFinding::SEVERITY_INFO,
        ];
        $allowedPageTypes = EnterpriseWikiPage::PAGE_TYPES;
        $allowedCodes = EnterpriseWikiLintFinding::CODES;

        $severity = in_array($request->query('q_severity'), $allowedSeverities, true)
            ? $request->query('q_severity') : null;
        $code = in_array($request->query('q_code'), $allowedCodes, true)
            ? $request->query('q_code') : null;
        $pageType = in_array($request->query('q_page_type'), $allowedPageTypes, true)
            ? $request->query('q_page_type') : null;

        $query = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_lint_findings.customer_id', $customerId)
            ->where('enterprise_wiki_lint_findings.status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->with([
                'page:id,title,slug,page_type',
                'run:id,source_id',
            ])
            ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('enterprise_wiki_lint_findings.created_at');

        if ($severity !== null) {
            $query->where('enterprise_wiki_lint_findings.severity', $severity);
        }

        if ($code !== null) {
            $query->where('enterprise_wiki_lint_findings.code', $code);
        }

        if ($pageType !== null) {
            $query->whereHas('page', fn ($q) => $q->where('page_type', $pageType));
        }

        $findings = $query->get();

        $docIds = $findings
            ->map(fn ($f) => $f->run?->source_id)
            ->filter()
            ->unique()
            ->values();

        $docFilenames = $docIds->isNotEmpty()
            ? EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->whereIn('id', $docIds)
                ->pluck('original_filename', 'id')
            : collect();

        $mapped = $findings->map(fn (EnterpriseWikiLintFinding $f) => [
            'id' => $f->id,
            'code' => $f->code,
            'severity' => $f->severity,
            'message' => $f->message,
            'page_title' => $f->page?->title,
            'page_slug' => $f->page?->slug,
            'page_type' => $f->page?->page_type,
            'run_id' => $f->enterprise_wiki_ingest_run_id,
            'source_filename' => $f->run?->source_id ? $docFilenames->get($f->run->source_id) : null,
            'created_at' => $f->created_at,
        ])->all();

        $coverage = $this->coverageService->computeForCustomer($customerId);

        return [
            'quality_findings' => $mapped,
            'quality_filters' => [
                'severity' => $severity,
                'code' => $code,
                'page_type' => $pageType,
            ],
            'coverage' => $coverage,
        ];
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

        $claimSummary = [
            'total' => 0,
            'source_found' => 0,
            'missing_excerpt' => 0,
            'manually_approved' => 0,
            'missing_source' => 0,
            'conflict' => 0,
        ];
        $claims = [];

        if ($currentVersion !== null) {
            $claimCollection = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $currentVersion->id)
                ->with(['sourceReferences', 'approvedBy'])
                ->orderBy('position_order')
                ->get();

            $claimSummary['total'] = $claimCollection->count();

            foreach ($claimCollection as $claim) {
                if ($claim->conflict_flag) {
                    $claimSummary['conflict']++;
                }

                $claimSummary[match ($claim->sourceStatus()) {
                    EnterpriseWikiClaim::SOURCE_STATUS_FOUND => 'source_found',
                    EnterpriseWikiClaim::SOURCE_STATUS_MISSING_EXCERPT => 'missing_excerpt',
                    EnterpriseWikiClaim::SOURCE_STATUS_MANUALLY_APPROVED => 'manually_approved',
                    EnterpriseWikiClaim::SOURCE_STATUS_MISSING => 'missing_source',
                }]++;
            }

            $claims = $claimCollection
                ->map(fn(EnterpriseWikiClaim $claim) => [
                    'id' => $claim->id,
                    'claim_text' => $claim->claim_text,
                    'confidence' => $claim->confidence,
                    'conflict_flag' => $claim->conflict_flag,
                    'approval_status' => $claim->approval_status,
                    'position_order' => $claim->position_order,
                    'source_status' => $claim->sourceStatus(),
                    'approved_by_name' => $claim->approvedBy?->name,
                    'approved_at' => $claim->approved_at,
                    'approval_comment' => $claim->approval_comment,
                    'source_references' => $claim->sourceReferences
                        ->map(fn($ref) => [
                            'id' => $ref->id,
                            'source_type' => $ref->source_type,
                            'source_label' => $ref->source_label,
                            'excerpt' => $ref->excerpt,
                            'page_reference' => $ref->page_reference,
                            'download_url' => $ref->source_type === EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT
                                ? route('app.wiki.sources.download', $ref->source_id)
                                : null,
                        ])
                        ->all(),
                ])
                ->all();
        }

        $lintFindings = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $customerId)
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'code' => $f->code,
                'severity' => $f->severity,
                'message' => $f->message,
            ])
            ->all();

        $lintSummary = [
            'error'   => collect($lintFindings)->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)->count(),
            'warning' => collect($lintFindings)->where('severity', EnterpriseWikiLintFinding::SEVERITY_WARNING)->count(),
            'info'    => collect($lintFindings)->where('severity', EnterpriseWikiLintFinding::SEVERITY_INFO)->count(),
            'total'   => count($lintFindings),
        ];

        $mapPage = fn(EnterpriseWikiPage $p) => [
            'id'        => $p->id,
            'title'     => $p->title,
            'slug'      => $p->slug,
            'page_type' => $p->page_type,
            'status'    => $p->status,
        ];

        $renderedMarkdown = $currentVersion !== null && $currentVersion->content_markdown !== null
            ? $this->wikilinkRenderer->render($currentVersion->content_markdown, $customerId, $page)
            : null;

        // Canonical backlinks: pages whose current content_markdown contains an inline
        // [[wikilink]] to this page. Deliberately a direct, dedicated query rather than
        // EnterpriseWikiPageTraversalService::incoming() so this stays literally scoped to
        // link_type=wikilink regardless of how the traversal service's contract evolves.
        $backlinks = EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('to_page_id', $page->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->with('fromPage')
            ->get()
            ->map(fn(EnterpriseWikiPageLink $link) => $link->fromPage)
            ->filter()
            ->unique('id')
            ->map($mapPage)
            ->values()
            ->all();

        return Inertia::render('App/Wiki/Show', [
            'page' => [
                'id'           => $page->id,
                'title'        => $page->title,
                'slug'         => $page->slug,
                'page_type'    => $page->page_type,
                'status'       => $page->status,
                'generated_by' => $page->generated_by,
                'reviewed_at'  => $page->reviewed_at,
                'updated_at'   => $page->updated_at,
            ],
            'current_version' => $currentVersion !== null ? [
                'id'                => $currentVersion->id,
                'version_number'    => $currentVersion->version_number,
                'content_markdown'  => $currentVersion->content_markdown,
                'rendered_markdown' => $renderedMarkdown,
            ] : null,
            'claims'          => $claims,
            'claim_summary'   => $claimSummary,
            'lint_findings'   => $lintFindings,
            'lint_summary'    => $lintSummary,
            'outgoing_links'  => $this->traversal->outgoing($page)->map($mapPage)->values()->all(),
            'incoming_links'  => $this->traversal->incoming($page)->map($mapPage)->values()->all(),
            'related_articles' => $this->traversal->relatedArticles($page)->map($mapPage)->values()->all(),
            'related_concepts' => $this->traversal->relatedConcepts($page)->map($mapPage)->values()->all(),
            'related_entities' => $this->traversal->relatedEntities($page)->map($mapPage)->values()->all(),
            'backlinks'       => $backlinks,
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
