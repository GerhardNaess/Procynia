<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimFindingExplainer;
use App\Services\EnterpriseWiki\EnterpriseWikiCoverageService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use App\Services\EnterpriseWiki\EnterpriseWikiWikilinkRenderer;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
        private readonly EnterpriseWikiRunFindingsService $runFindingsService,
        private readonly EnterpriseWikiDocumentFlowService $documentFlowService,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
        private readonly EnterpriseWikiClaimContentRepairService $claimContentRepairService,
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

        // Computed regardless of active tab (like lint_health above) so the Pages tab — whose own
        // prop payload never includes runs — can still know to poll for newly generated pages
        // while a run is working in the background on another tab.
        $hasActiveWikiRun = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('status', EnterpriseWikiIngestRun::NON_TERMINAL_STATUSES)
            ->exists();

        $props = [
            'active_tab' => $tab,
            'lint_health' => $lintHealth,
            'wiki_generation_available' => EnterpriseWikiMaintainerDecisionAiClient::isAvailable(),
            'sources_store_url' => route('app.wiki.sources.store'),
            'has_active_wiki_run' => $hasActiveWikiRun,
        ];

        $props += match ($tab) {
            'sources' => $this->loadSourcesTab($user, $customerId, $request),
            'runs' => $this->loadRunsTab($user, $customerId, $request),
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

        return $this->documentOwnerSummaryForVersion($currentVersion);
    }

    /**
     * Same read-only summary as documentOwnerSummaryForPage(), but for a specific page version
     * rather than always a page's current one — used by runPages() so a Kjøringer row reflects
     * the version THIS run actually produced/applied (Del 4), which may since have been
     * superseded by a later run (see documentOwnerSummaryForRunPageVersion()).
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
    private function documentOwnerSummaryForVersion(EnterpriseWikiPageVersion $currentVersion): array
    {
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

    /**
     * The version this run produced/applied is no longer the page's current version — a later
     * run has since superseded it. Document Owner approval no longer applies to a superseded
     * version, so this must never be conflated with "still pending" (Del 4).
     */
    private function documentOwnerSummarySuperseded(): array
    {
        return [
            'state' => 'superseded',
            'label' => __('procynia.wiki.document_owner_superseded'),
            'owner_count' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'missing_owner_count' => 0,
            'has_override' => false,
        ];
    }

    /**
     * The run hasn't finished generating this page's version yet — there is nothing to approve
     * until it exists. Reuses the exact same user-facing wording as
     * documentOwnerSummaryBlockedByQuality() ("Behandles fortsatt") since both are the same kind
     * of "not actionable yet, not a decision the user can make" state (Del 5).
     */
    private function documentOwnerSummaryProcessing(): array
    {
        return [
            'state' => 'processing',
            'label' => __('procynia.wiki.document_owner_blocked_by_quality'),
            'owner_count' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'missing_owner_count' => 0,
            'has_override' => false,
        ];
    }

    private function documentOwnerSummaryProcessingFailed(): array
    {
        return [
            'state' => 'processing_failed',
            'label' => __('procynia.wiki.document_owner_generation_failed'),
            'owner_count' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'missing_owner_count' => 0,
            'has_override' => false,
        ];
    }

    /**
     * Cross-run "Sider" detail (Kjøringer tab, Del 1-9): resolve the understandable Document
     * Owner status for the SPECIFIC version this run produced/applied — never just the run's
     * overall status, and never the page's current version if a later run has since replaced it.
     */
    private function documentOwnerSummaryForRunPageVersion(EnterpriseWikiPageVersion $version, bool $isCurrent): array
    {
        if (! $isCurrent) {
            return $this->documentOwnerSummarySuperseded();
        }

        return $this->documentOwnerSummaryForVersion($version);
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

        $latestRuns = $allRuns
            ->groupBy('source_id')
            ->map(fn ($group) => $group->first());

        $pagesPerDocument = $allRuns
            ->filter(fn ($run) => $run->enterprise_wiki_page_id !== null && $run->page !== null)
            ->groupBy('source_id')
            ->map(fn ($runs) => $runs
                ->map(fn ($run) => $run->page)
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
            'can_delete' => $user?->canDeleteEnterpriseWikiDocument($doc) ?? false,
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

    private function loadRunsTab(?User $user, int $customerId, Request $request): array
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

        $query = EnterpriseWikiIngestRun::query()
            ->select('enterprise_wiki_ingest_runs.*')
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
        $docsById = $documentIds->isNotEmpty()
            ? EnterpriseWikiDocument::query()
                ->whereIn('id', $documentIds)
                ->where('customer_id', $customerId)
                ->get()
                ->keyBy('id')
            : collect();

        return [
            // Kjøringer "Funn" — sourced from the exact same canonical collection the detail
            // panel uses (EnterpriseWikiRunFindingsService::buildForRun()), never a second,
            // hand-rolled approximation. A prior version of this method re-implemented
            // EnterpriseWikiClaimFindingExplainer::isUserFacingAddition() as raw SQL, which only
            // replicated its deterministic_reason check — it silently missed the "never reached a
            // verdict" and "self-reported check mismatch" exclusions, so the badge could show far
            // more than the panel (e.g. 17 vs 1). Calling buildForRun() per row costs a handful of
            // extra indexed queries per run instead of one batched subquery, but guarantees the
            // badge and the panel can never drift apart again — see
            // EnterpriseWikiRunFindingsConsistencyTest.
            'runs' => $runs->map(function (EnterpriseWikiIngestRun $run) use ($docsById, $user) {
                $summary = $this->runFindingsService->buildForRun($run, $user, false)['summary'];

                /** @var EnterpriseWikiDocument|null $document */
                $document = $docsById->get($run->source_id);

                return [
                    'id' => $run->id,
                    'status' => $run->status,
                    'maintainer_decision_status' => $run->maintainer_decision_status,
                    'source_document_filename' => $document?->original_filename,
                    'source_id' => $run->source_id,
                    'can_cancel' => ! $run->isTerminal()
                        && $document instanceof EnterpriseWikiDocument
                        && ($user?->canDeleteEnterpriseWikiDocument($document) ?? false),
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
                    'lint_count' => $summary['total'],
                    'findings_open_blocking_count' => $summary['open_blocking'],
                    'findings_open_non_blocking_count' => $summary['open_non_blocking'] + $summary['best_practice_pending'],
                    'created_at' => $run->created_at,
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at,
                    'updated_at' => $run->updated_at,
                    'last_progress_at' => $run->updated_at,
                ];
            })->all(),
            'runs_filters' => [
                'status' => $runStatus,
                'decision' => $runDecision,
                'src_id' => $runSrc,
            ],
        ];
    }

    /**
     * "Sider" detail (Kjøringer tab, Del 1-9): the Wiki pages this run actually created or
     * updated, with the Document Owner status of the SPECIFIC page version the run
     * produced/applied — not just the run's own overall status. Read-only: reuses
     * EnterpriseWikiDocumentOwnerApprovalService's existing preview/summary methods and never
     * re-syncs or writes approval rows on this GET.
     *
     * Scoping: the pivot rows already only ever reference pages/versions belonging to the same
     * customer as the run (Enterprise Wiki never links a page across customers), but the
     * customer_id equality check below is kept as a defensive, cheap guard against a
     * manipulated/foreign run id — consistent with resolvePageForClaim()'s abort_unless() style
     * elsewhere in this codebase.
     */
    public function runPages(EnterpriseWikiIngestRun $run): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        abort_unless((int) $run->customer_id === (int) $customerId, 404);
        abort_unless($run->source_type === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 404);

        $runPages = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with([
                'page',
                'generatedPageVersion.documentOwnerApprovals' => fn ($query) => $query->with(['documentOwner', 'decidedBy']),
            ])
            ->get()
            ->filter(fn (EnterpriseWikiIngestRunPage $runPage): bool => $runPage->page instanceof EnterpriseWikiPage
                && (int) $runPage->page->customer_id === $customerId);

        $rows = $runPages->map(fn (EnterpriseWikiIngestRunPage $runPage): array => $this->buildRunPageRow($runPage, $user))->values();

        $doneStates = ['approved', 'rejected', 'superseded'];
        $blockedStates = ['blocked_by_quality', 'processing', 'processing_failed'];

        $doneCount = $rows->filter(fn (array $row): bool => in_array($row['document_owner_status']['state'], $doneStates, true))->count();
        $blockedCount = $rows->filter(fn (array $row): bool => in_array($row['document_owner_status']['state'], $blockedStates, true))->count();
        $awaitingCount = $rows->count() - $doneCount - $blockedCount;

        return response()->json([
            'pages' => $rows->all(),
            'summary' => [
                'total' => $rows->count(),
                'done' => $doneCount,
                'awaiting_document_owner' => $awaitingCount,
                'blocked_by_quality' => $blockedCount,
            ],
            'stall_explanation' => $this->runStallExplanation($run, $awaitingCount),
        ]);
    }

    /**
     * Manually cancel a non-terminal ingest run — e.g. one that is legitimately waiting on
     * Document Owner approval with no active job/lease behind it — so its source document
     * becomes eligible for the existing document-scoped deletion. Authorization mirrors
     * WikiSourceController::destroy() exactly: the run is cancelled by whoever could delete its
     * source document (System Owner, or the document's registered owner), since cancelling a
     * run only ever exists to unblock that same deletion.
     */
    public function cancelRun(EnterpriseWikiIngestRun $run): RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        abort_unless((int) $run->customer_id === (int) $customerId, 404);
        abort_unless($run->source_type === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 404);

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->find($run->source_id);

        abort_unless($document instanceof EnterpriseWikiDocument, 404);
        abort_unless($user instanceof User && $user->canDeleteEnterpriseWikiDocument($document), 403);

        if ($run->isTerminal()) {
            return redirect()->route('app.wiki.index', ['tab' => 'runs'])
                ->with('error', __('procynia.wiki.run_cancel_already_terminal'));
        }

        $this->documentFlowService->cancelRun($run, $user);

        Log::info('[PROCYNIA][WIKI_RUN] Cancelled ingest run.', [
            'run_id' => $run->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
        ]);

        return redirect()->route('app.wiki.index', ['tab' => 'runs'])
            ->with('success', __('procynia.wiki.run_cancel_success'));
    }

    /**
     * @return array{
     *     page_id: int, title: string, slug: string, url: string, page_type: string,
     *     page_version_id: ?int, version_number: ?int, action: string, is_current_version: bool,
     *     document_owner_status: array, can_handle: bool, decided_at: ?string, decided_by_name: ?string
     * }
     */
    private function buildRunPageRow(EnterpriseWikiIngestRunPage $runPage, ?User $user): array
    {
        $page = $runPage->page;
        $version = $runPage->generatedPageVersion;

        $base = [
            'page_id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => route('app.wiki.show', $page->slug),
            'page_type' => $page->page_type,
            'action' => $runPage->action,
            'page_version_id' => $version?->id,
            'version_number' => $version?->version_number,
            'is_current_version' => $version !== null && (bool) $version->is_current,
            'can_handle' => false,
            'decided_at' => null,
            'decided_by_name' => null,
        ];

        if (! $version instanceof EnterpriseWikiPageVersion) {
            $base['document_owner_status'] = $runPage->generation_status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED
                ? $this->documentOwnerSummaryProcessingFailed()
                : $this->documentOwnerSummaryProcessing();

            return $base;
        }

        $isCurrent = (bool) $version->is_current;
        $base['document_owner_status'] = $this->documentOwnerSummaryForRunPageVersion($version, $isCurrent);

        $approvals = $version->documentOwnerApprovals;
        $lastDecided = $approvals->whereNotNull('decided_at')->sortByDesc('decided_at')->first();

        if ($lastDecided instanceof EnterpriseWikiPageVersionDocumentOwnerApproval) {
            $base['decided_at'] = $lastDecided->decided_at?->toIso8601String();
            $base['decided_by_name'] = $lastDecided->decidedBy?->name;
        }

        if ($isCurrent && $user instanceof User) {
            $base['can_handle'] = $approvals
                ->where('approval_status', EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING)
                ->contains(fn (EnterpriseWikiPageVersionDocumentOwnerApproval $approval): bool => $this->documentOwnerApprovalService->canDecide($approval, $user));
        }

        return $base;
    }

    /**
     * Del 9: when a run's own status is "awaiting Document Owner approval", explain WHY using
     * the same page list already computed above — never invent a separate explanation and never
     * pretend a reason applies when the underlying count is actually zero (a stale/unreconciled
     * run status is reported as needing resync, not given a fabricated explanation).
     */
    private function runStallExplanation(EnterpriseWikiIngestRun $run, int $awaitingCount): ?string
    {
        if ($run->status !== EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL) {
            return null;
        }

        if ($awaitingCount > 0) {
            return trans_choice('procynia.wiki.runs_pages_stall_awaiting_owner', $awaitingCount, ['count' => $awaitingCount]);
        }

        return __('procynia.wiki.runs_pages_stall_needs_resync');
    }

    /**
     * "Funn" detail (Kjøringer tab): every quality finding for this run, normalized from
     * EnterpriseWikiLintFinding rows and live claim-integrity defects by
     * EnterpriseWikiRunFindingsService — see that class for why both sources are needed and how
     * double-counting is avoided. Read-only: never re-runs lint, never re-evaluates QA, never
     * writes a reconciliation.
     *
     * Technical diagnostics (raw code, raw severity/status) are included only for System Owner
     * or a QA-capable user — the same gate EnterpriseWikiDocumentOwnerApprovalService::
     * canHandleClaim() already uses, so an ordinary Document Owner never sees internal enum
     * values (Del 13).
     */
    public function runFindings(EnterpriseWikiIngestRun $run): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        abort_unless((int) $run->customer_id === (int) $customerId, 404);
        abort_unless($run->source_type === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 404);

        $includeTechnical = $user instanceof User && ($user->isSystemOwner() || $user->canApproveWikiClaims());

        return response()->json($this->runFindingsService->buildForRun($run, $user, $includeTechnical));
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

        $parameters = ['slug' => $pageSlug];
        $claimId = $finding->enterprise_wiki_claim_id !== null ? (int) $finding->enterprise_wiki_claim_id : null;

        if ($claimId !== null && $claimId > 0) {
            $parameters['claim_id'] = $claimId;
        } else {
            $parameters['finding_id'] = $finding->id;
        }

        return route('app.wiki.show', $parameters);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildStructureFindingReference(
        Request $request,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
        int $customerId,
        ?string $backUrl,
    ): ?array {
        $rawFindingId = $request->query('finding_id');

        if ($rawFindingId === null || $rawFindingId === '' || ! is_numeric($rawFindingId)) {
            return null;
        }

        $findingId = (int) $rawFindingId;

        if ($findingId <= 0) {
            return null;
        }

        $finding = EnterpriseWikiLintFinding::query()
            ->whereKey($findingId)
            ->where('customer_id', $customerId)
            ->where('enterprise_wiki_page_id', $page->id)
            ->whereNull('enterprise_wiki_claim_id')
            ->with(['run:id,source_id', 'version:id,version_number'])
            ->first();

        if (! $finding instanceof EnterpriseWikiLintFinding) {
            return null;
        }

        $copy = $this->qualityCheckCopy($finding->code);
        $versionId = $finding->enterprise_wiki_page_version_id !== null
            ? (int) $finding->enterprise_wiki_page_version_id
            : null;

        return [
            'id' => $finding->id,
            'code' => $finding->code,
            'category_label' => $copy['label'],
            'description' => $copy['description'],
            'message' => $finding->message,
            'severity' => $finding->severity,
            'severity_label' => $this->lintSeverityLabel($finding->severity),
            'status' => $finding->status,
            'status_label' => __('procynia.wiki.runs_findings_status_'.$finding->status),
            'page_id' => $page->id,
            'page_title' => $page->title,
            'page_type' => $page->page_type,
            'page_version_id' => $versionId,
            'page_version_number' => $finding->version?->version_number,
            'current_page_version_id' => $currentVersion?->id,
            'is_current_version' => $versionId === null || ($currentVersion !== null && $versionId === (int) $currentVersion->id),
            'run_id' => $finding->enterprise_wiki_ingest_run_id,
            'run_source_id' => $finding->run?->source_id,
            'detected_at' => $finding->detected_at?->toIso8601String(),
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'back_url' => $backUrl,
        ];
    }

    /**
     * @return array{label: string, description: string}
     */
    private function qualityCheckCopy(string $code): array
    {
        $label = __('procynia.wiki.quality_checks.'.$code.'.label');
        $description = __('procynia.wiki.quality_checks.'.$code.'.description');

        $unresolvedLabel = 'procynia.wiki.quality_checks.'.$code.'.label';
        $unresolvedDescription = 'procynia.wiki.quality_checks.'.$code.'.description';

        return [
            'label' => $label === $unresolvedLabel ? __('procynia.wiki.quality_check_unknown_label').': '.$code : $label,
            'description' => $description === $unresolvedDescription ? __('procynia.wiki.quality_check_unknown_description').' ('.$code.')' : $description,
        ];
    }

    private function lintSeverityLabel(string $severity): string
    {
        return match ($severity) {
            EnterpriseWikiLintFinding::SEVERITY_ERROR => __('procynia.wiki.lint_severity_error'),
            EnterpriseWikiLintFinding::SEVERITY_WARNING => __('procynia.wiki.lint_severity_warning'),
            default => __('procynia.wiki.lint_severity_info'),
        };
    }

    public function show(Request $request, string $slug): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        $currentVersion = $page->currentVersion()->first();
        $canApproveWikiClaims = $user?->isSystemOwner() || $user?->canApproveWikiClaims();

        // Read access is not gated by page status: any authorized user of this customer's
        // Enterprise Wiki may open the page regardless of draft/pending_review/approved/rejected
        // — see User::visibleEnterpriseWikiPageStatuses(). Status still fully controls which
        // workflow actions are available (submit()/approve()/reject() below, and claim handling
        // via $canApproveWikiClaims/canHandleWikiClaims further down).
        abort_unless($user instanceof User && $user->is_active && $user->canAccessCustomerFrontend(), 404);

        $claimCollection = collect();

        if ($currentVersion !== null) {
            $claimCollection = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $currentVersion->id)
                ->with(['sourceReferences', 'approvedBy', 'blockingOverrideBy', 'canonicalFact'])
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
                ->map(function (EnterpriseWikiClaim $claim) use ($user, $currentVersion): array {
                    $isClaimDefect = in_array($claim->content_origin, [
                        EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                        EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    ], true);
                    $finding = $isClaimDefect ? $this->claimFindingExplainer->explain($claim) : null;
                    $blockingState = $isClaimDefect ? $this->claimFindingExplainer->blockingState($claim) : null;

                    return [
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
                        // Per-case finding for internal_error/unsupported_generated_content claims
                        // (EnterpriseWikiClaimFindingExplainer) — null for every other claim, since
                        // only these two content_origins are ever a "finding" needing this shape.
                        'finding_category' => $finding['category'] ?? null,
                        'finding_category_label' => $finding['category_label'] ?? null,
                        'finding_title' => $finding['title'] ?? null,
                        'finding_explanation' => $finding['explanation'] ?? null,
                        'finding_recommended_action' => $finding['recommended_action'] ?? null,
                        // Kept as two separate facts, never one collapsed boolean — a claim the
                        // system recommends blocking with no recorded decision must never render
                        // as "Blokkerer kjøringen" (CLAUDE.md: "Systemforslag er ikke
                        // brukerbeslutning"). null for every non-claim-defect claim.
                        'system_recommends_blocking' => $blockingState['system_recommends_blocking'] ?? null,
                        'user_decision' => $blockingState['user_decision'] ?? null,
                        'requires_decision' => $blockingState['requires_decision'] ?? null,
                        'blocking_override_by_name' => $claim->blockingOverrideBy?->name,
                        'blocking_override_at' => $claim->blocking_override_at,
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
                    ];
                })
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

        $rawReviewClaimId = $request->query('claim_id');
        $rawBackUrl = $request->query('back_url');
        $backUrl = is_string($rawBackUrl) ? $this->normalizeReviewBackUrl($rawBackUrl) : null;
        $reviewReference = ($rawReviewClaimId !== null && $rawReviewClaimId !== '' && is_numeric($rawReviewClaimId))
            ? $this->buildReviewReference((int) $rawReviewClaimId, $page, $currentVersion, $canApproveWikiClaims, $backUrl)
            : null;
        $structureFinding = $reviewReference === null
            ? $this->buildStructureFindingReference($request, $page, $currentVersion, $customerId, $backUrl)
            : null;
        $manualBlockEdit = $canApproveWikiClaims
            ? $this->manualMixedBlockEditContext($page, $currentVersion, $customerId)
            : null;

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
                'content_blocks_json' => $this->renderedContentBlocks($currentVersion, $page, $customerId),
            ] : null,
            'review_reference' => $reviewReference,
            'structure_finding' => $structureFinding,
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
            'can_edit_wiki_claims' => (bool) $canApproveWikiClaims,
            'manual_block_edit' => $manualBlockEdit,
            'source_documents' => $sourceDocuments,
            'document_owner_approvals' => $documentOwnerApprovals,
            'document_owner_approval_summary' => $documentOwnerApprovalSummary,
            'document_owner_summary' => $this->documentOwnerSummaryForPage($page),
        ]);
    }

    public function updateManualMixedBlockEdit(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        abort_unless($user instanceof User && $user->is_active && $user->canAccessCustomerFrontend() && $user->canApproveWikiClaims(), 403);

        $validated = $request->validate([
            'run_id' => ['required', 'integer'],
            'expected_page_version_id' => ['required', 'integer'],
            'blocks' => ['required', 'array', 'min:1', 'max:25'],
            'blocks.*' => ['required', 'array'],
            'blocks.*.block_key' => ['required', 'string', 'max:255', 'distinct'],
            'blocks.*.markdown' => ['required', 'string', 'max:20000'],
            'back_url' => ['nullable', 'string', 'max:2048'],
        ], [
            'run_id.required' => 'Kjøringskontekst mangler. Åpne funnet fra Kjøringer og prøv igjen.',
            'expected_page_version_id.required' => 'Sideversjon mangler. Last inn siden på nytt og prøv igjen.',
            'blocks.required' => 'Velg minst én tekstblokk som skal lagres.',
            'blocks.*.block_key.required' => 'Tekstblokken mangler block_key. Last inn siden på nytt og prøv igjen.',
            'blocks.*.block_key.distinct' => 'Samme tekstblokk kan bare sendes én gang.',
            'blocks.*.markdown.required' => 'Tekstblokken kan ikke være tom.',
        ]);

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        abort_unless((int) $claim->enterprise_wiki_page_id === (int) $page->id, 404);

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->whereKey((int) $validated['run_id'])
            ->first() ?? abort(404);

        $expectedCurrentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->whereKey((int) $validated['expected_page_version_id'])
            ->first();

        if (! $expectedCurrentVersion instanceof EnterpriseWikiPageVersion) {
            throw ValidationException::withMessages([
                'expected_page_version_id' => 'Ugyldig sideversjon for denne Wiki-siden.',
            ]);
        }

        $backUrl = isset($validated['back_url']) && is_string($validated['back_url'])
            ? $this->normalizeReviewBackUrl($validated['back_url'])
            : null;

        if (! $expectedCurrentVersion->is_current
            || $expectedCurrentVersion->is_staged
            || (int) $claim->enterprise_wiki_page_version_id !== (int) $expectedCurrentVersion->id
        ) {
            return $this->manualMixedBlockEditConflictRedirect($page, $claim, $backUrl);
        }

        $runPage = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->first();

        if (! $runPage instanceof EnterpriseWikiIngestRunPage) {
            abort(404);
        }

        if ((int) ($runPage->generated_page_version_id ?? 0) !== (int) $expectedCurrentVersion->id) {
            return $this->manualMixedBlockEditConflictRedirect($page, $claim, $backUrl);
        }

        $submittedBlocks = $this->validatedManualMixedBlockEditBlocks((array) $validated['blocks'], $expectedCurrentVersion);

        try {
            $result = $this->claimContentRepairService->applyManualMixedBlockEdit(
                $run,
                $page,
                $expectedCurrentVersion,
                $claim,
                $submittedBlocks,
                $user,
            );
        } catch (\InvalidArgumentException $e) {
            if ($this->isManualMixedBlockEditConflict($e)) {
                return $this->manualMixedBlockEditConflictRedirect($page, $claim, $backUrl);
            }

            throw ValidationException::withMessages([
                'blocks' => $this->manualMixedBlockEditValidationMessage($e),
            ]);
        } catch (\RuntimeException $e) {
            if ($this->isManualMixedBlockEditConflict($e)) {
                return $this->manualMixedBlockEditConflictRedirect($page, $claim, $backUrl);
            }

            Log::warning('[PROCYNIA][WIKI_MANUAL_BLOCK_EDIT] Failed to save manual mixed block edit.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            return $this->manualMixedBlockEditFailureRedirect($page, $claim, $backUrl);
        } catch (\Throwable $e) {
            Log::warning('[PROCYNIA][WIKI_MANUAL_BLOCK_EDIT] Unexpected failure while saving manual mixed block edit.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            return $this->manualMixedBlockEditFailureRedirect($page, $claim, $backUrl);
        }

        $routeParams = ['slug' => $page->slug];
        $newClaimId = collect($result['new_claim_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->first(static fn (int $id): bool => $id > 0);

        if ($newClaimId !== null) {
            $routeParams['claim_id'] = $newClaimId;
        }

        if ($backUrl !== null) {
            $routeParams['back_url'] = $backUrl;
        }

        return redirect()
            ->route('app.wiki.show', $routeParams)
            ->with('success', 'Wiki-teksten er lagret som ny aktiv versjon.');
    }

    /**
     * @return array{run_id: int, update_url_template: string}|null
     */
    private function manualMixedBlockEditContext(
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
        int $customerId,
    ): ?array {
        if (! $currentVersion instanceof EnterpriseWikiPageVersion) {
            return null;
        }

        $runPage = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('generated_page_version_id', $currentVersion->id)
            ->whereHas('run', fn ($query) => $query
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (! $runPage instanceof EnterpriseWikiIngestRunPage) {
            return null;
        }

        return [
            'run_id' => (int) $runPage->enterprise_wiki_ingest_run_id,
            'update_url_template' => route('app.wiki.claims.manual-block-edit.update', [
                'slug' => $page->slug,
                'claim' => '__CLAIM_ID__',
            ], false),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{block_key: string, markdown: string}>
     */
    private function validatedManualMixedBlockEditBlocks(array $blocks, EnterpriseWikiPageVersion $version): array
    {
        $currentBlockKeys = collect((array) ($version->content_blocks_json ?? []))
            ->filter(fn ($block): bool => is_array($block))
            ->mapWithKeys(fn (array $block): array => [trim((string) ($block['block_key'] ?? '')) => true])
            ->filter(fn (bool $exists, string $blockKey): bool => $blockKey !== '')
            ->all();
        $submittedBlocks = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                throw ValidationException::withMessages([
                    'blocks' => 'Ugyldig tekstblokk. Last inn siden på nytt og prøv igjen.',
                ]);
            }

            $blockKey = trim((string) ($block['block_key'] ?? ''));

            if ($blockKey === '' || ! array_key_exists($blockKey, $currentBlockKeys)) {
                throw ValidationException::withMessages([
                    'blocks' => 'Tekstblokken finnes ikke i gjeldende Wiki-versjon. Last inn siden på nytt og prøv igjen.',
                ]);
            }

            $submittedBlocks[] = [
                'block_key' => $blockKey,
                'markdown' => trim((string) ($block['markdown'] ?? '')),
            ];
        }

        return $submittedBlocks;
    }

    private function manualMixedBlockEditConflictRedirect(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, ?string $backUrl): RedirectResponse
    {
        return redirect()
            ->route('app.wiki.show', array_filter([
                'slug' => $page->slug,
                'claim_id' => $claim->id,
                'back_url' => $backUrl,
            ], static fn ($value): bool => $value !== null))
            ->with('error', 'Wiki-siden er endret av noen andre. Last inn siden på nytt før du lagrer.');
    }

    private function manualMixedBlockEditFailureRedirect(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, ?string $backUrl): RedirectResponse
    {
        return redirect()
            ->route('app.wiki.show', array_filter([
                'slug' => $page->slug,
                'claim_id' => $claim->id,
                'back_url' => $backUrl,
            ], static fn ($value): bool => $value !== null))
            ->with('error', 'Tekstendringen kunne ikke lagres. Ingen ny Wiki-versjon ble aktivert.');
    }

    private function manualMixedBlockEditValidationMessage(\InvalidArgumentException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'duplicate content_block_key')) {
            return 'Samme tekstblokk kan bare sendes én gang.';
        }

        if (str_contains($message, 'requires non-empty markdown')) {
            return 'Tekstblokken kan ikke være tom.';
        }

        if (str_contains($message, 'not a mixed-provenance block')) {
            return 'Bare mixed Wiki-blokker kan redigeres i denne flyten.';
        }

        if (str_contains($message, 'did not change any content block')) {
            return 'Teksten er uendret. Gjør en endring før du lagrer.';
        }

        return 'Tekstendringen kan ikke lagres. Kontroller tekstblokken og prøv igjen.';
    }

    private function isManualMixedBlockEditConflict(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'no longer current')
            || str_contains($message, 'no longer points to expected current page version')
            || str_contains($message, 'does not point to expected current page version')
            || str_contains($message, 'not the current published version')
            || str_contains($message, 'no longer promotable');
    }

    /**
     * Resolves a `?claim_id=` deep link (e.g. from the Kjøringer "Funn" panel's best-practice
     * suggestion "Åpne og vurder" action) into an explicit, backend-validated review target —
     * the frontend never decides this from the raw claim/page/block data alone (Del 7).
     *
     * The claim is looked up scoped to THIS page only (already customer-scoped via $page), so a
     * manipulated claim id belonging to another page or another customer simply resolves to
     * 'not_found' — never leaks whether a differently-scoped claim id exists.
     *
     * @return array{status: string, claim_id?: int, block_key?: ?string, version_number?: ?int, back_url?: string}
     */
    private function buildReviewReference(
        int $claimId,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $currentVersion,
        bool $includeTechnical,
        ?string $backUrl = null,
    ): array {
        $claim = EnterpriseWikiClaim::query()
            ->where('id', $claimId)
            ->where('enterprise_wiki_page_id', $page->id)
            ->first();

        if ($claim === null) {
            return $this->reviewReferenceWithBackUrl(['status' => 'not_found'], $backUrl);
        }

        if ($currentVersion === null || (int) $claim->enterprise_wiki_page_version_id !== (int) $currentVersion->id) {
            $claimVersion = $claim->version()->first();

            return $this->reviewReferenceWithBackUrl([
                'status' => 'superseded',
                'claim_id' => $claim->id,
                'version_number' => $claimVersion?->version_number,
            ], $backUrl);
        }

        $blockKey = trim((string) ($claim->content_block_key ?? ''));
        $blockKey = $blockKey !== '' ? $blockKey : null;
        $blocks = $this->displayContentBlocks($currentVersion);

        if ($blockKey === null) {
            $fallbackBlockKey = $this->uniqueReviewReferenceBlockKeyFromExcerpt($claim, $blocks);

            if ($fallbackBlockKey !== null) {
                return $this->reviewReferenceWithBackUrl(['status' => 'ready', 'claim_id' => $claim->id, 'block_key' => $fallbackBlockKey], $backUrl);
            }

            return $this->reviewReferenceWithBackUrl(['status' => 'ready', 'claim_id' => $claim->id, 'block_key' => null], $backUrl);
        }

        // Must match renderedContentBlocks()'s own filter exactly (non-empty markdown) — a block
        // that still exists in content_blocks_json but was blanked (e.g. the "Fjern"/remove-text
        // action on a best-practice suggestion, see WikiClaimController) is never actually
        // rendered with a #wiki-block-{key} element for the frontend to scroll to and highlight.
        // Checking block_key existence alone here previously reported 'ready' for such a claim,
        // silently failing to scroll or highlight anything with no error shown to the user.
        $blockExists = $blocks->contains(fn (array $block): bool => ($block['block_key'] ?? null) === $blockKey);

        if (! $blockExists) {
            $fallbackBlockKey = $this->uniqueReviewReferenceBlockKeyFromExcerpt($claim, $blocks);

            if ($fallbackBlockKey !== null && $this->hasNoRenderableStoredBlocks($currentVersion)) {
                return $this->reviewReferenceWithBackUrl(['status' => 'ready', 'claim_id' => $claim->id, 'block_key' => $fallbackBlockKey], $backUrl);
            }

            $reference = ['status' => 'block_missing', 'claim_id' => $claim->id];

            if ($includeTechnical) {
                $reference['technical_block_key'] = $blockKey;
            }

            return $this->reviewReferenceWithBackUrl($reference, $backUrl);
        }

        return $this->reviewReferenceWithBackUrl(['status' => 'ready', 'claim_id' => $claim->id, 'block_key' => $blockKey], $backUrl);
    }

    private function reviewReferenceWithBackUrl(array $reference, ?string $backUrl): array
    {
        if ($backUrl !== null) {
            $reference['back_url'] = $backUrl;
        }

        return $reference;
    }

    private function normalizeReviewBackUrl(string $backUrl): ?string
    {
        $backUrl = trim($backUrl);

        if ($backUrl === '') {
            return null;
        }

        $parsed = parse_url($backUrl);

        if (! is_array($parsed) || ($parsed['path'] ?? null) !== '/app/wiki') {
            return null;
        }

        parse_str($parsed['query'] ?? '', $query);

        return (($query['tab'] ?? null) === 'runs') ? $backUrl : null;
    }

    /**
     * Per-block wikilink rendering (Del 3/4) — the article body renders each block individually
     * (see resources/js/Pages/App/Wiki/Show.jsx) so a specific block can be scrolled to and
     * highlighted; each block therefore needs the same [[wikilink]] → clickable-link
     * transformation `rendered_markdown` already applies to the whole joined document.
     * EnterpriseWikiWikilinkRenderer::render() operates on a plain markdown string with no
     * whole-document context requirement, so rendering per-block is equivalent to rendering the
     * joined markdown once.
     *
     * @return list<array<string, mixed>>
     */
    private function renderedContentBlocks(EnterpriseWikiPageVersion $version, EnterpriseWikiPage $page, int $customerId): array
    {
        return $this->displayContentBlocks($version)
            ->map(fn (array $block): array => [
                'block_key' => $block['block_key'] ?? null,
                'position' => $block['position'] ?? 0,
                'markdown' => $this->wikilinkRenderer->render((string) $block['markdown'], $customerId, $page),
                'raw_markdown' => (string) $block['markdown'],
                'content_origin' => $block['content_origin'] ?? null,
                'is_derived_from_markdown' => (bool) ($block['is_derived_from_markdown'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function displayContentBlocks(EnterpriseWikiPageVersion $version): Collection
    {
        $storedBlocks = collect((array) ($version->content_blocks_json ?? []))
            ->filter(fn ($block): bool => is_array($block) && trim((string) ($block['markdown'] ?? '')) !== '')
            ->values()
            ->map(fn (array $block, int $index): array => array_merge($block, [
                'block_key' => trim((string) ($block['block_key'] ?? '')) !== ''
                    ? (string) $block['block_key']
                    : 'stored-block-'.sprintf('%04d', $index + 1),
                'position' => $block['position'] ?? $index,
                'is_derived_from_markdown' => false,
            ]));

        if ($storedBlocks->isNotEmpty()) {
            return $storedBlocks;
        }

        return $this->derivedMarkdownDisplayBlocks((string) ($version->content_markdown ?? ''));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function derivedMarkdownDisplayBlocks(string $markdown): Collection
    {
        $parts = preg_split("/\n{2,}/", $markdown) ?: [];

        return collect($parts)
            ->map(static fn (string $part): string => trim($part))
            ->filter(static fn (string $part): bool => $part !== '')
            ->values()
            ->map(static fn (string $part, int $index): array => [
                'block_key' => 'markdown-block-'.sprintf('%04d', $index + 1),
                'position' => $index,
                'markdown' => $part,
                'raw_markdown' => $part,
                'content_origin' => null,
                'is_derived_from_markdown' => true,
            ]);
    }

    private function hasNoRenderableStoredBlocks(EnterpriseWikiPageVersion $version): bool
    {
        return collect((array) ($version->content_blocks_json ?? []))
            ->doesntContain(fn ($block): bool => is_array($block) && trim((string) ($block['markdown'] ?? '')) !== '');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $blocks
     */
    private function uniqueReviewReferenceBlockKeyFromExcerpt(EnterpriseWikiClaim $claim, Collection $blocks): ?string
    {
        $needles = collect([
            $claim->page_excerpt,
            $claim->claim_text,
        ])
            ->map(fn ($value): string => $this->normalizeReviewReferenceText((string) $value))
            ->filter(static fn (string $value): bool => mb_strlen($value) >= 20)
            ->unique()
            ->values();

        if ($needles->isEmpty()) {
            return null;
        }

        $matches = $blocks
            ->filter(function (array $block) use ($needles): bool {
                $haystack = $this->normalizeReviewReferenceText((string) ($block['raw_markdown'] ?? $block['markdown'] ?? ''));

                return $needles->contains(static fn (string $needle): bool => str_contains($haystack, $needle));
            })
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        $blockKey = trim((string) ($matches->first()['block_key'] ?? ''));

        return $blockKey !== '' ? $blockKey : null;
    }

    private function normalizeReviewReferenceText(string $text): string
    {
        $text = preg_replace('/\[\[([^|\]]+)\|([^\]]+)]]/u', '$2', $text) ?? $text;
        $text = preg_replace('/\[\[([^\]]+)]]/u', '$1', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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
        return $user?->visibleEnterpriseWikiPageStatuses() ?? [EnterpriseWikiPage::STATUS_APPROVED];
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
