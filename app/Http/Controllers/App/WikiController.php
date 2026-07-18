<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiCoverageService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use App\Services\EnterpriseWiki\EnterpriseWikiWikilinkRenderer;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
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
            ->withCount('claims')
            ->with([
                'currentVersion.claims.sourceReferences',
                'currentVersion.documentOwnerApprovals.documentOwner',
            ]);

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
            'document_owner_summary' => $this->documentOwnerSummaryForPage($page),
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

    /**
     * Summarize the materialized document-owner approvals for one page without mutating state.
     *
     * The list view must stay read-only and must not try to re-sync approval rows on GET.
     *
     * @return array{
     *     state: string,
     *     label: string,
     *     owner_count: int,
     *     approved_count: int,
     *     pending_count: int,
     *     rejected_count: int,
     *     missing_owner_count: int,
     *     has_override: bool
     * }
     */
    private function documentOwnerSummaryForPage(EnterpriseWikiPage $page): array
    {
        $currentVersion = $page->currentVersion;

        if (! $currentVersion instanceof EnterpriseWikiPageVersion) {
            return $this->documentOwnerSummaryAwaitingSync();
        }

        $requirements = $this->documentOwnerApprovalService->previewRequirementsForPageVersion($currentVersion);

        if ($requirements->isEmpty()) {
            if ($this->documentOwnerApprovalService->hasActiveClaimIntegrityDefectsForVersion($currentVersion)) {
                return $this->documentOwnerSummaryBlockedByQuality();
            }

            return $this->documentOwnerSummaryAwaitingSync();
        }

        $approvals = $currentVersion->relationLoaded('documentOwnerApprovals')
            ? $currentVersion->documentOwnerApprovals->values()
            : $currentVersion->documentOwnerApprovals()->with('documentOwner')->get()->values();

        $matchedApprovals = $requirements->map(function (array $requirement) use ($approvals): array {
            $matchingApproval = $approvals->first(function (EnterpriseWikiPageVersionDocumentOwnerApproval $approval) use ($requirement): bool {
                $approvalOwnerId = $approval->document_owner_user_id !== null ? (int) $approval->document_owner_user_id : null;
                $requirementOwnerId = $requirement['document_owner_user_id'] !== null ? (int) $requirement['document_owner_user_id'] : null;

                return $approvalOwnerId === $requirementOwnerId
                    && (string) $approval->source_documents_hash === (string) $requirement['source_documents_hash'];
            });

            return [
                'requirement' => $requirement,
                'approval' => $matchingApproval,
            ];
        });

        $ownerCount = $matchedApprovals->count();
        $missingOwnerCount = $matchedApprovals->filter(fn (array $pair): bool => $pair['requirement']['document_owner_user_id'] === null)->count();
        $awaitingSyncCount = $matchedApprovals->filter(fn (array $pair): bool => $pair['requirement']['document_owner_user_id'] !== null && ! $pair['approval'] instanceof EnterpriseWikiPageVersionDocumentOwnerApproval)->count();
        $approvedCount = $matchedApprovals->filter(fn (array $pair): bool => $pair['approval'] instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && $pair['approval']->approval_status === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED)->count();
        $pendingCount = $matchedApprovals->filter(fn (array $pair): bool => $pair['approval'] instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && $pair['approval']->approval_status === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING)->count();
        $rejectedCount = $matchedApprovals->filter(fn (array $pair): bool => $pair['approval'] instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && $pair['approval']->approval_status === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED)->count();
        $hasOverride = $matchedApprovals->contains(fn (array $pair): bool => $pair['approval'] instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && (bool) $pair['approval']->is_override);
        $singlePair = $ownerCount === 1 ? $matchedApprovals->first() : null;
        $singleApproval = $singlePair['approval'] ?? null;
        $overrideSuffix = $hasOverride ? ' · '.__('procynia.wiki.document_owner_override') : '';

        if ($missingOwnerCount > 0) {
            return [
                'state' => 'missing_owner',
                'label' => __('procynia.wiki.document_owner_missing').$overrideSuffix,
                'owner_count' => $ownerCount,
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'rejected_count' => $rejectedCount,
                'missing_owner_count' => $missingOwnerCount,
                'has_override' => $hasOverride,
            ];
        }

        if ($awaitingSyncCount > 0) {
            return [
                'state' => 'awaiting_sync',
                'label' => __('procynia.wiki.document_owner_sync_pending').$overrideSuffix,
                'owner_count' => $ownerCount,
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'rejected_count' => $rejectedCount,
                'missing_owner_count' => $missingOwnerCount,
                'has_override' => $hasOverride,
            ];
        }

        $ownerCountLabel = $ownerCount > 1
            ? trans_choice('procynia.wiki.document_owner_owner_count', $ownerCount, ['count' => $ownerCount]).' · '
            : '';

        if ($rejectedCount > 0) {
            $label = $ownerCount === 1 && $singleApproval instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && $singleApproval->documentOwner?->name
                ? $singleApproval->documentOwner->name.' · '.__('procynia.wiki.document_owner_rejected_label')
                : $ownerCountLabel.__('procynia.wiki.document_owner_rejected_count', ['count' => $rejectedCount]);

            return [
                'state' => 'rejected',
                'label' => $label.$overrideSuffix,
                'owner_count' => $ownerCount,
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'rejected_count' => $rejectedCount,
                'missing_owner_count' => $missingOwnerCount,
                'has_override' => $hasOverride,
            ];
        }

        if ($pendingCount > 0) {
            $label = $ownerCount === 1 && $singleApproval instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && $singleApproval->documentOwner?->name
                ? $singleApproval->documentOwner->name.' · '.__('procynia.wiki.document_owner_pending_label')
                : $ownerCountLabel.__('procynia.wiki.document_owner_approved_of_total', [
                    'approved' => $approvedCount,
                    'total' => $ownerCount,
                ]);

            return [
                'state' => $approvedCount > 0 ? 'mixed' : 'pending',
                'label' => $label.$overrideSuffix,
                'owner_count' => $ownerCount,
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'rejected_count' => $rejectedCount,
                'missing_owner_count' => $missingOwnerCount,
                'has_override' => $hasOverride,
            ];
        }

        $label = $ownerCount === 1 && $singleApproval instanceof EnterpriseWikiPageVersionDocumentOwnerApproval && $singleApproval->documentOwner?->name
            ? $singleApproval->documentOwner->name.' · '.__('procynia.wiki.document_owner_approved_label')
            : $ownerCountLabel.__('procynia.wiki.document_owner_approved_label');

        return [
            'state' => 'approved',
            'label' => $label.$overrideSuffix,
            'owner_count' => $ownerCount,
            'approved_count' => $approvedCount,
            'pending_count' => $pendingCount,
            'rejected_count' => $rejectedCount,
            'missing_owner_count' => $missingOwnerCount,
            'has_override' => $hasOverride,
        ];
    }

    /**
     * @return array{
     *     state: string,
     *     label: string,
     *     owner_count: int,
     *     approved_count: int,
     *     pending_count: int,
     *     rejected_count: int,
     *     missing_owner_count: int,
     *     has_override: bool
     * }
     */
    private function documentOwnerSummaryAwaitingSync(): array
    {
        return [
            'state' => 'awaiting_sync',
            'label' => __('procynia.wiki.document_owner_sync_pending'),
            'owner_count' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'missing_owner_count' => 0,
            'has_override' => false,
        ];
    }

    /**
     * A technically invalid page version (active unsupported/internal-error claims, or a
     * source-based claim missing its provenance — see
     * EnterpriseWikiDocumentOwnerApprovalService::hasActiveClaimIntegrityDefectsForVersion())
     * never generates a Document Owner approval requirement, so it always falls into the same
     * "no requirements" branch as a page still awaiting its first sync. This distinct state
     * keeps the two apart in the UI: a Document Owner must never be asked to approve/reject
     * unresolved technical defects, only see an understandable "still processing" message
     * without internal enum names (Del 9).
     */
    private function documentOwnerSummaryBlockedByQuality(): array
    {
        return [
            'state' => 'blocked_by_quality',
            'label' => __('procynia.wiki.document_owner_blocked_by_quality'),
            'owner_count' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'missing_owner_count' => 0,
            'has_override' => false,
        ];
    }

    private function documentOwnerApprovalSummaryText(Collection $approvalRows): string
    {
        $totalSourceDocuments = $approvalRows
            ->flatMap(function (EnterpriseWikiPageVersionDocumentOwnerApproval $approval): array {
                return is_array($approval->source_document_ids) ? $approval->source_document_ids : [];
            })
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->count();

        if ($totalSourceDocuments === 0) {
            return '';
        }

        $countForStatus = static function (Collection $rows, string $status): int {
            return $rows
                ->where('approval_status', $status)
                ->sum(static function (EnterpriseWikiPageVersionDocumentOwnerApproval $approval): int {
                    return is_array($approval->source_document_ids) ? count($approval->source_document_ids) : 0;
                });
        };

        $approvedCount = $countForStatus($approvalRows, EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);
        $pendingCount = $countForStatus($approvalRows, EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);
        $rejectedCount = $countForStatus($approvalRows, EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED);
        $missingOwnerCount = $approvalRows
            ->where('approval_status', EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING)
            ->whereNull('document_owner_user_id')
            ->sum(static function (EnterpriseWikiPageVersionDocumentOwnerApproval $approval): int {
                return is_array($approval->source_document_ids) ? count($approval->source_document_ids) : 0;
            });

        if ($approvedCount === 0 && $pendingCount === 0 && $rejectedCount === 0 && $missingOwnerCount > 0) {
            return trans_choice('procynia.wiki.document_owner_summary_missing_owner', $missingOwnerCount, [
                'count' => $missingOwnerCount,
            ]);
        }

        $parts = [];

        if ($approvedCount > 0) {
            $parts[] = trans_choice('procynia.wiki.document_owner_summary_approved', $approvedCount, [
                'approved' => $approvedCount,
                'total' => $totalSourceDocuments,
            ]);
        }

        if ($pendingCount > 0) {
            $parts[] = trans_choice('procynia.wiki.document_owner_summary_pending', $pendingCount, [
                'count' => $pendingCount,
            ]);
        }

        if ($rejectedCount > 0) {
            $parts[] = trans_choice('procynia.wiki.document_owner_summary_rejected', $rejectedCount, [
                'count' => $rejectedCount,
            ]);
        }

        if ($missingOwnerCount > 0) {
            $parts[] = trans_choice('procynia.wiki.document_owner_summary_missing_owner', $missingOwnerCount, [
                'count' => $missingOwnerCount,
            ]);
        }

        return implode(' · ', $parts);
    }

    private function documentOwnerApprovalSentence(
        EnterpriseWikiPageVersionDocumentOwnerApproval $approval,
        Collection $sourceDocuments,
    ): string {
        $sourceLabel = $this->documentOwnerApprovalSourceLabel($approval, $sourceDocuments);

        if ($approval->is_override) {
            return __('procynia.wiki.document_owner_sentence_overridden', [
                'source' => $sourceLabel,
            ]);
        }

        return match ($approval->approval_status) {
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED => __('procynia.wiki.document_owner_sentence_approved', [
                'owner' => $approval->documentOwner?->name ?? __('procynia.wiki.document_owner_label'),
                'source' => $sourceLabel,
            ]),
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED => __('procynia.wiki.document_owner_sentence_rejected', [
                'owner' => $approval->documentOwner?->name ?? __('procynia.wiki.document_owner_label'),
                'source' => $sourceLabel,
            ]),
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING => $approval->document_owner_user_id === null
                ? __('procynia.wiki.document_owner_sentence_missing_owner', [
                    'source' => $sourceLabel,
                ])
                : __('procynia.wiki.document_owner_sentence_pending', [
                    'owner' => $approval->documentOwner?->name ?? __('procynia.wiki.document_owner_label'),
                    'source' => $sourceLabel,
                ]),
            default => __('procynia.wiki.document_owner_sentence_pending', [
                'owner' => $approval->documentOwner?->name ?? __('procynia.wiki.document_owner_label'),
                'source' => $sourceLabel,
            ]),
        };
    }

    private function documentOwnerApprovalSourceLabel(
        EnterpriseWikiPageVersionDocumentOwnerApproval $approval,
        Collection $sourceDocuments,
    ): string {
        $documentIds = collect(is_array($approval->source_document_ids) ? $approval->source_document_ids : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($documentIds->count() === 1) {
            $document = $sourceDocuments->get($documentIds->first());

            return $document?->original_filename ?? __('procynia.wiki.document_owner_source_single_fallback');
        }

        return trans_choice('procynia.wiki.document_owner_source_multiple', $documentIds->count(), [
            'count' => $documentIds->count(),
        ]);
    }

    private function loadSourcesTab(?User $user, int $customerId, Request $request): array
    {
        $allowedDocStatuses = [
            EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
            EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ];
        $lintCountSub = DB::table('enterprise_wiki_lint_findings')
            ->selectRaw('count(*)')
            ->whereColumn('enterprise_wiki_ingest_run_id', 'enterprise_wiki_ingest_runs.id')
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN);

        $srcSearch = trim((string) $request->query('src_q', ''));
        $srcStatus = in_array($request->query('src_status'), $allowedDocStatuses, true)
            ? $request->query('src_status') : null;

        $docQuery = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->with('owner:id,name,email,is_active')
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
                ->select('enterprise_wiki_ingest_runs.*')
                ->selectSub($lintCountSub, 'lint_count')
                ->withCount(['sections', 'pages'])
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
            ->map(fn ($group) => $group->first());

        $pagesPerDocument = $allRuns
            ->filter(fn ($run) => $run->enterprise_wiki_page_id !== null && $run->page !== null)
            ->groupBy('source_id')
            ->map(fn ($runs) => $runs
                ->map(fn ($run) => $run->page)
                ->filter(fn ($page) => in_array($page->status, $visibleStatuses, true))
                ->unique('id')
                ->values()
            );

        $sources = $documents->map(fn (EnterpriseWikiDocument $doc) => [
            'id' => $doc->id,
            'original_filename' => $doc->original_filename,
            'document_status' => $doc->document_status,
            'owner_user_id' => $doc->owner_user_id,
            'owner_name' => $doc->owner?->name,
            'owner_email' => $doc->owner?->email,
            'owner_is_active' => $doc->owner?->is_active,
            'created_at' => $doc->created_at,
            'latest_ingest_run' => $latestRuns->has($doc->id) ? [
                'status' => $latestRuns[$doc->id]->status,
                'error_message' => $latestRuns[$doc->id]->error_message,
                'qa_status' => $latestRuns[$doc->id]->qa_status,
                'qa_last_error' => $latestRuns[$doc->id]->qa_last_error,
                'created_at' => $latestRuns[$doc->id]->created_at,
                'started_at' => $latestRuns[$doc->id]->started_at,
                'finished_at' => $latestRuns[$doc->id]->finished_at,
                'updated_at' => $latestRuns[$doc->id]->updated_at,
                'last_progress_at' => $latestRuns[$doc->id]->updated_at,
                'maintainer_decision_json' => $latestRuns[$doc->id]->maintainer_decision_json,
                'maintainer_decision_status' => $latestRuns[$doc->id]->maintainer_decision_status,
                'maintainer_decision_generated_at' => $latestRuns[$doc->id]->maintainer_decision_generated_at,
                'pages_count' => (int) ($latestRuns[$doc->id]->pages_count ?? 0),
                'sections_count' => (int) ($latestRuns[$doc->id]->sections_count ?? 0),
                'lint_count' => (int) ($latestRuns[$doc->id]->lint_count ?? 0),
            ] : null,
            'generated_pages' => ($pagesPerDocument->get($doc->id) ?? collect())
                ->map(fn ($page) => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'status' => $page->status,
                ])
                ->all(),
        ]);

        return [
            'sources' => $sources,
            'document_owner_options' => $this->documentOwnerOptionsForCustomer($customerId),
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
            'runs' => $runs->map(fn (EnterpriseWikiIngestRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'maintainer_decision_status' => $run->maintainer_decision_status,
                'source_document_filename' => $docFilenames->get($run->source_id),
                'source_id' => $run->source_id,
                'error_message' => $run->error_message,
                'qa_status' => $run->qa_status,
                'qa_last_error' => $run->qa_last_error,
                'claim_content_repair_attempt_count' => $run->claim_content_repair_attempt_count,
                'claim_content_repair_result' => $run->claim_content_repair_result,
                'model_used' => $run->model_used,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
                'pages_count' => (int) ($run->pages_count ?? 0),
                'sections_count' => (int) ($run->sections_count ?? 0),
                'lint_count' => (int) ($run->lint_count ?? 0),
                'created_at' => $run->created_at,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'updated_at' => $run->updated_at,
                'last_progress_at' => $run->updated_at,
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
            'target_url' => $this->qualityFindingTargetUrl($f),
            'target_page_id' => $f->enterprise_wiki_page_id,
            'target_page_version_id' => $f->enterprise_wiki_page_version_id,
            'target_claim_id' => $f->enterprise_wiki_claim_id,
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

    private function qualityFindingTargetUrl(EnterpriseWikiLintFinding $finding): ?string
    {
        $pageSlug = $finding->page?->slug;

        if ($pageSlug === null || $pageSlug === '') {
            return null;
        }

        $target = route('app.wiki.show', ['slug' => $pageSlug]);
        $claimId = $finding->enterprise_wiki_claim_id !== null ? (int) $finding->enterprise_wiki_claim_id : null;

        if ($claimId !== null && $claimId > 0) {
            $target .= '?claim_id='.$claimId;
        }

        return $target;
    }

    public function show(string $slug): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        $currentVersion = $page->currentVersion()->first();
        $canApproveWikiClaims = $user?->isSystemOwner() || $user?->canApproveWikiClaims();

        $canViewPendingPage = $page->status === EnterpriseWikiPage::STATUS_APPROVED
            || $user?->isSystemOwner()
            || $user?->isBidManager()
            || $user?->canApproveWikiClaims()
            || (
                $currentVersion !== null
                && $user instanceof User
                && $user->is_active
                && (
                    $this->documentOwnerApprovalService->isRequiredDocumentOwnerForPageVersion($currentVersion, $user)
                    || $this->documentOwnerApprovalService->isOwnerOfAnySourceDocumentForPageVersion($currentVersion, $user)
                )
            );

        abort_unless($canViewPendingPage, 404);

        $claimCollection = collect();

        if ($currentVersion !== null) {
            $claimCollection = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $currentVersion->id)
                ->with(['sourceReferences', 'approvedBy'])
                ->orderBy('position_order')
                ->get();
        }

        $canHandleWikiClaims = $user instanceof User && (
            $canApproveWikiClaims
            || ($currentVersion !== null && $claimCollection->contains(
                fn (EnterpriseWikiClaim $claim): bool => $this->documentOwnerApprovalService->canHandleClaim($claim, $user, $currentVersion)
            ))
        );

        $documentOwnerApprovals = [];
        $documentOwnerApprovalSummary = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'missing_owner' => 0,
            'ready' => true,
            'message' => null,
        ];

        if ($currentVersion !== null) {
            $approvalRows = $this->documentOwnerApprovalService->syncForPageVersion($currentVersion);

            $sourceDocumentIds = $approvalRows
                ->flatMap(fn (EnterpriseWikiPageVersionDocumentOwnerApproval $approval) => is_array($approval->source_document_ids) ? $approval->source_document_ids : [])
                ->map(static fn (mixed $value): int => (int) $value)
                ->filter(static fn (int $value): bool => $value > 0)
                ->unique()
                ->values();

            $sourceDocuments = $sourceDocumentIds->isNotEmpty()
                ? EnterpriseWikiDocument::query()
                    ->where('customer_id', $customerId)
                    ->whereIn('id', $sourceDocumentIds)
                    ->with('owner:id,name,email,is_active')
                    ->get()
                    ->keyBy('id')
                : collect();

            $documentOwnerApprovals = $approvalRows->map(function (EnterpriseWikiPageVersionDocumentOwnerApproval $approval) use ($sourceDocuments, $user): array {
                $documentIds = is_array($approval->source_document_ids) ? $approval->source_document_ids : [];
                $documents = collect($documentIds)
                    ->map(fn (mixed $id): array => [
                        'id' => (int) $id,
                        'original_filename' => $sourceDocuments->get((int) $id)?->original_filename,
                        'owner_name' => $sourceDocuments->get((int) $id)?->owner?->name,
                        'owner_user_id' => $sourceDocuments->get((int) $id)?->owner_user_id,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $approval->id,
                    'approval_status' => $approval->approval_status,
                    'summary_text' => $this->documentOwnerApprovalSentence($approval, $sourceDocuments),
                    'approval_comment' => $approval->approval_comment,
                    'decided_at' => $approval->decided_at,
                    'decided_by_name' => $approval->decidedBy?->name,
                    'is_override' => $approval->is_override,
                    'override_reason' => $approval->override_reason,
                    'document_owner_user_id' => $approval->document_owner_user_id,
                    'document_owner_name' => $approval->documentOwner?->name,
                    'document_owner_email' => $approval->documentOwner?->email,
                    'document_owner_is_active' => $approval->documentOwner?->is_active,
                    'source_document_ids' => $documentIds,
                    'source_documents' => $documents,
                    'can_decide' => $user instanceof User && $this->documentOwnerApprovalService->canDecide($approval, $user),
                ];
            })->all();

            $documentOwnerApprovalSummary = [
                'total' => $approvalRows->count(),
                'pending' => $approvalRows->where('approval_status', EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING)->count(),
                'approved' => $approvalRows->where('approval_status', EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED)->count(),
                'rejected' => $approvalRows->where('approval_status', EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED)->count(),
                'missing_owner' => $approvalRows
                    ->where('approval_status', EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING)
                    ->whereNull('document_owner_user_id')
                    ->count(),
                'ready' => $approvalRows->whereIn('approval_status', [
                    EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
                    EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED,
                ])->isEmpty(),
                'summary_text' => $this->documentOwnerApprovalSummaryText($approvalRows),
                'message' => null,
            ];

            if (! $documentOwnerApprovalSummary['ready']) {
                $documentOwnerApprovalSummary['message'] = $documentOwnerApprovalSummary['missing_owner'] > 0
                    ? 'Kildedokument mangler Dokumenteier'
                    : ($documentOwnerApprovalSummary['rejected'] > 0
                        ? 'Avvist av Dokumenteier'
                        : 'Avventer godkjenning fra Dokumenteier');
            }
        }

        $claimSummary = [
            'total' => 0,
            'source_found' => 0,
            'missing_excerpt' => 0,
            'manually_approved' => 0,
            'rejected' => 0,
            'missing_source' => 0,
            'best_practice_review' => 0,
            'unsupported_generated_content' => 0,
            'internal_generation_error' => 0,
            'conflict' => 0,
        ];
        $claims = [];

        if ($currentVersion !== null) {
            $claimSummary['total'] = $claimCollection->count();

            foreach ($claimCollection as $claim) {
                if ($claim->conflict_flag) {
                    $claimSummary['conflict']++;
                }

                $claimSummary[match ($claim->sourceStatus()) {
                    EnterpriseWikiClaim::SOURCE_STATUS_FOUND => 'source_found',
                    EnterpriseWikiClaim::SOURCE_STATUS_MISSING_EXCERPT => 'missing_excerpt',
                    EnterpriseWikiClaim::SOURCE_STATUS_MANUALLY_APPROVED => 'manually_approved',
                    EnterpriseWikiClaim::SOURCE_STATUS_REJECTED => 'rejected',
                    EnterpriseWikiClaim::SOURCE_STATUS_MISSING => 'missing_source',
                    EnterpriseWikiClaim::SOURCE_STATUS_BEST_PRACTICE_REVIEW => 'best_practice_review',
                    EnterpriseWikiClaim::SOURCE_STATUS_UNSUPPORTED_GENERATED_CONTENT => 'unsupported_generated_content',
                    EnterpriseWikiClaim::SOURCE_STATUS_INTERNAL_ERROR => 'internal_generation_error',
                }]++;
            }

            $claims = $claimCollection
                ->map(fn (EnterpriseWikiClaim $claim) => [
                    'id' => $claim->id,
                    'claim_text' => $claim->claim_text,
                    'content_origin' => $claim->content_origin,
                    'page_excerpt' => $claim->page_excerpt,
                    'content_block_key' => $claim->content_block_key,
                    'review_reason' => $claim->review_reason,
                    'review_metadata' => $claim->review_metadata,
                    'generation_issue' => $claim->generation_issue,
                    'confidence' => $claim->confidence,
                    'conflict_flag' => $claim->conflict_flag,
                    'approval_status' => $claim->approval_status,
                    'position_order' => $claim->position_order,
                    'source_status' => $claim->sourceStatus(),
                    'approved_by_name' => $claim->approvedBy?->name,
                    'approved_at' => $claim->approved_at,
                    'approval_comment' => $claim->approval_comment,
                    'source_references' => $claim->sourceReferences
                        ->map(fn ($ref) => [
                            'id' => $ref->id,
                            'source_type' => $ref->source_type,
                            'source_element_key' => $ref->source_element_key,
                            'source_element_type' => $ref->source_element_type,
                            'source_row_key' => $ref->source_row_key,
                            'source_label' => $ref->source_label,
                            'excerpt' => $ref->excerpt,
                            'page_reference' => $ref->page_reference,
                            'download_url' => $ref->source_type === EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT
                                ? route('app.wiki.sources.download', $ref->source_id)
                                : null,
                        ])
                        ->all(),
                    'can_handle' => $user instanceof User && $this->documentOwnerApprovalService->canHandleClaim($claim, $user, $currentVersion),
                ])
                ->all();
        }

        $lintFindings = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $customerId)
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'code' => $f->code,
                'severity' => $f->severity,
                'message' => $f->message,
            ])
            ->all();

        $lintSummary = [
            'error' => collect($lintFindings)->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)->count(),
            'warning' => collect($lintFindings)->where('severity', EnterpriseWikiLintFinding::SEVERITY_WARNING)->count(),
            'info' => collect($lintFindings)->where('severity', EnterpriseWikiLintFinding::SEVERITY_INFO)->count(),
            'total' => count($lintFindings),
        ];

        $mapPage = fn (EnterpriseWikiPage $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'slug' => $p->slug,
            'page_type' => $p->page_type,
            'status' => $p->status,
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
            ->map(fn (EnterpriseWikiPageLink $link) => $link->fromPage)
            ->filter()
            ->unique('id')
            ->map($mapPage)
            ->values()
            ->all();

        $sourceDocuments = [];

        if ($canHandleWikiClaims) {
            $sourceDocumentsQuery = EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED)
                ->with('owner:id,name,email,is_active');

            if (! $canApproveWikiClaims && $user instanceof User) {
                $accessibleDocumentIds = $claimCollection
                    ->flatMap(fn (EnterpriseWikiClaim $claim): array => $claim->sourceReferences
                        ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                        ->pluck('source_id')
                        ->all())
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->filter(static fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();

                $sourceDocumentsQuery
                    ->where('owner_user_id', $user->id)
                    ->whereIn('id', $accessibleDocumentIds->all());
            }

            $sourceDocuments = $sourceDocumentsQuery
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (EnterpriseWikiDocument $doc): array => [
                    'id' => $doc->id,
                    'original_filename' => $doc->original_filename,
                    'document_status' => $doc->document_status,
                    'owner_user_id' => $doc->owner_user_id,
                    'owner_name' => $doc->owner?->name,
                    'owner_email' => $doc->owner?->email,
                    'owner_is_active' => $doc->owner?->is_active,
                    'download_url' => route('app.wiki.sources.download', $doc->id),
                    'created_at' => $doc->created_at,
                ])
                ->all();
        }

        return Inertia::render('App/Wiki/Show', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'page_type' => $page->page_type,
                'status' => $page->status,
                'generated_by' => $page->generated_by,
                'reviewed_at' => $page->reviewed_at,
                'updated_at' => $page->updated_at,
            ],
            'current_version' => $currentVersion !== null ? [
                'id' => $currentVersion->id,
                'version_number' => $currentVersion->version_number,
                'content_markdown' => $currentVersion->content_markdown,
                'rendered_markdown' => $renderedMarkdown,
            ] : null,
            'claims' => $claims,
            'claim_summary' => $claimSummary,
            'lint_findings' => $lintFindings,
            'lint_summary' => $lintSummary,
            'outgoing_links' => $this->traversal->outgoing($page)->map($mapPage)->values()->all(),
            'incoming_links' => $this->traversal->incoming($page)->map($mapPage)->values()->all(),
            'related_articles' => $this->traversal->relatedArticles($page)->map($mapPage)->values()->all(),
            'related_concepts' => $this->traversal->relatedConcepts($page)->map($mapPage)->values()->all(),
            'related_entities' => $this->traversal->relatedEntities($page)->map($mapPage)->values()->all(),
            'backlinks' => $backlinks,
            'can_handle_wiki_claims' => $canHandleWikiClaims,
            'source_documents' => $sourceDocuments,
            'document_owner_approvals' => $documentOwnerApprovals,
            'document_owner_approval_summary' => $documentOwnerApprovalSummary,
            'document_owner_summary' => $this->documentOwnerSummaryForPage($page),
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

    /**
     * Draft/pending_review visibility is also granted via canApproveWikiClaims() — a user who
     * can manually approve claims (System Owner, or Bid Manager/Contributor/etc. with QA) needs
     * to be able to open the page and its verification basis to act on them, even when their
     * ordinary role would not otherwise grant review access. This is read-only visibility; it
     * does not extend to whole-page approval/rejection (submit()/approve()/reject() remain
     * System Owner-only above) or to any Wiki administration action.
     *
     * @return list<string>
     */
    private function visibleStatuses(?User $user): array
    {
        $statuses = [EnterpriseWikiPage::STATUS_APPROVED];

        if ($user?->isSystemOwner() || $user?->isBidManager() || $user?->canApproveWikiClaims()) {
            $statuses[] = EnterpriseWikiPage::STATUS_DRAFT;
            $statuses[] = EnterpriseWikiPage::STATUS_PENDING_REVIEW;
        }

        if ($user?->isSystemOwner()) {
            $statuses[] = EnterpriseWikiPage::STATUS_REJECTED;
        }

        return $statuses;
    }

    /**
     * Purpose: Build the selectable document owner options for one customer.
     * Inputs: The customer id.
     * Returns: A stable list of active customer-scoped user options.
     * Side effects: None.
     */
    private function documentOwnerOptionsForCustomer(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->with('customer:id,permission_settings')
            ->get(['id', 'name', 'email', 'role', 'bid_role', 'is_qa', 'customer_id', 'is_active'])
            ->filter(fn (User $user): bool => $user->canBeEnterpriseWikiDocumentOwner())
            ->sortBy([
                ['name', 'asc'],
                ['id', 'asc'],
            ])
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'label' => sprintf('%s · %s', $user->name, $user->email),
            ])
            ->values()
            ->all();
    }
}
