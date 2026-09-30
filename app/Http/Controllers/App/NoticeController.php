<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GoNoGoAssessment;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\Notice;
use App\Models\NoticeAttention;
use App\Models\NoticeDocument;
use App\Models\OpportunitySourceRecord;
use App\Models\SavedNotice;
use App\Models\SavedNoticeBusinessReview;
use App\Models\SavedNoticeInfoItem;
use App\Models\SavedNoticeNoGoDecision;
use App\Models\SavedNoticePhaseComment;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\Cpv\CustomerNoticeCpvSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\GoNoGo\GoNoGoDefaultTemplateService;
use App\Services\OpportunitySources\LiveSearchOpportunityLinker;
use App\Services\OpportunitySources\OpportunityRegistrar;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunitySourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceRegistry;
use App\Services\OpportunitySources\OpportunityStatus;
use App\Services\SavedNoticeAccessService;
use App\Services\SavedNoticeNoGoDecisionService;
use App\Support\CustomerContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class NoticeController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly CustomerNoticeCpvSearchService $cpvSearchService,
        private readonly OpportunitySourceRegistry $sources,
        private readonly DoffinNoticeDocumentService $documentService,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly SavedNoticeNoGoDecisionService $savedNoticeNoGoDecisionService,
        private readonly GoNoGoDefaultTemplateService $goNoGoDefaultTemplateService,
        private readonly OpportunityRegistrar $opportunities,
        private readonly LiveSearchOpportunityLinker $opportunityLinks,
    ) {}

    public function index(Request $request): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);
        $mode = $this->noticeMode((string) $request->string('mode'));
        $noticeTab = trim((string) $request->string('tab'));
        $isAlertsTab = $mode === 'live' && $noticeTab === 'alerts';
        $useCockpitScope = $request->boolean('cockpit_scope');
        $keywordsMode = trim((string) $request->string('keywords_mode'));
        $publicationPeriod = trim((string) $request->string('publication_period'));
        $publicationDateFrom = trim((string) $request->string('publication_date_from'));
        $publicationDateTo = trim((string) $request->string('publication_date_to'));

        if ($publicationDateFrom === '' && $publicationDateTo === '' && $publicationPeriod !== '') {
            [$publicationDateFrom, $publicationDateTo] = $this->publicationDateRangeFromPeriod($publicationPeriod);
        }

        $filters = [
            'q' => trim((string) $request->string('q')),
            'organization_name' => trim((string) $request->string('organization_name')),
            'cpv' => trim((string) $request->string('cpv')),
            'keywords' => trim((string) $request->string('keywords')),
            'watch_list_id' => trim((string) $request->string('watch_list_id')),
            'publication_date_from' => $publicationDateFrom,
            'publication_date_to' => $publicationDateTo,
            'publication_period' => $publicationPeriod,
            'status' => trim((string) $request->string('status')),
            'relevance' => trim((string) $request->string('relevance')),
            'bid_status' => trim((string) $request->string('bid_status')),
            'history_type' => trim((string) $request->string('history_type')),
            'cockpit_scope' => $useCockpitScope ? '1' : '',
        ];

        if ($customerId === null) {
            return $this->renderNoticeIndexPage($request, [
                'mode' => $mode,
                'source' => $this->discoverySource($mode, $request),
                'supportMode' => [
                    'active' => $user->isSuperAdmin(),
                    'message' => __('procynia.frontend.super_admin_context_required'),
                ],
                'filters' => $filters,
                'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
                'savedSearches' => [],
                'worklist' => [
                    'saved_count' => 0,
                    'history_count' => 0,
                ],
                'tab' => $noticeTab,
                'monitoring' => $this->monitoringSummary(null, null),
                'watchAlerts' => $this->emptyWatchAlertsPayload(),
                'notices' => $this->emptySearchResult(),
                'historyTypeOptions' => collect(SavedNotice::historyTypeOptions())
                    ->map(fn (array $option): array => $option)
                    ->values()
                    ->all(),
            ]);
        }

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = 15;
        $worklist = $this->savedNoticeCounts($user, $customerId, $useCockpitScope);
        $watchAlerts = $this->watchAlertsPayload($user, $customerId);

        if ($isAlertsTab) {
            return $this->renderNoticeIndexPage($request, [
                'mode' => $mode,
                'tab' => $noticeTab,
                'source' => $this->discoverySource($mode, $request),
                'supportMode' => [
                    'active' => false,
                    'message' => null,
                ],
                'filters' => $filters,
                'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
                'savedSearches' => [],
                'worklist' => [
                    'saved_count' => 0,
                    'history_count' => 0,
                ],
                'monitoring' => $this->monitoringSummary($user, $customerId),
                'watchAlerts' => $watchAlerts,
                'notices' => $this->emptySearchResult(),
                'historyTypeOptions' => collect(SavedNotice::historyTypeOptions())
                    ->map(fn (array $option): array => $option)
                    ->values()
                    ->all(),
            ]);
        }

        if ($mode !== 'live') {
            return $this->renderNoticeIndexPage($request, [
                'mode' => $mode,
                'tab' => $noticeTab,
                'source' => $this->discoverySource($mode, $request),
                'supportMode' => [
                    'active' => false,
                    'message' => null,
                ],
                'filters' => $filters,
                'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
                'savedSearches' => $this->savedSearchesForUser($user, $customerId),
                'worklist' => $worklist,
                'monitoring' => $this->monitoringSummary($user, $customerId),
                'watchAlerts' => $watchAlerts,
                'notices' => $this->savedNoticeResult($request, $user, $mode, $page, $perPage, $customerId, $useCockpitScope),
                'historyTypeOptions' => collect(SavedNotice::historyTypeOptions())
                    ->map(fn (array $option): array => $option)
                    ->values()
                    ->all(),
            ]);
        }

        Log::debug('[DOFFIN][controller] Incoming live notice search request.', [
            'q' => $filters['q'],
            'keywords' => $filters['keywords'],
            'keywords_mode' => $keywordsMode !== '' ? $keywordsMode : 'all',
            'organization_name' => $filters['organization_name'],
            'cpv' => $filters['cpv'],
            'publication_date_from' => $filters['publication_date_from'],
            'publication_date_to' => $filters['publication_date_to'],
            'publication_period' => $filters['publication_period'],
            'status' => $filters['status'],
            'page' => $page,
            'per_page' => $perPage,
            'customer_id' => $customerId,
        ]);

        // What the user asked for, said without naming a register. Five of the keys in $filters —
        // watch_list_id, relevance, bid_status, history_type, cockpit_scope — filter saved cases
        // inside Procynia and never reached the register at all; they were passed to search() and
        // silently ignored. They are simply not part of the question any more.
        $criteria = new OpportunitySearchCriteria(
            query: $filters['q'] !== '' ? $filters['q'] : null,
            keywords: OpportunitySearchCriteria::fromArray(['keywords' => $filters['keywords']])->keywords,
            // The dropdown offers all-or-any and defaults to all, exactly as before.
            matchAllKeywords: $keywordsMode !== 'any',
            buyerName: $filters['organization_name'] !== '' ? $filters['organization_name'] : null,
            cpvCodes: OpportunitySearchCriteria::fromArray(['cpv_codes' => $filters['cpv']])->cpvCodes,
            status: OpportunityStatus::fromRequestValue($filters['status']),
            publishedFrom: $publicationDateFrom !== '' ? $publicationDateFrom : null,
            publishedTo: $publicationDateTo !== '' ? $publicationDateTo : null,
            publishedWithinDays: $publicationPeriod !== '' ? (int) $publicationPeriod : null,
        );

        $searchResponse = $this->discoveryAdapter($request)->search($criteria, $page, $perPage);
        $page = $searchResponse->page;
        $perPage = $searchResponse->perPage;
        $fallbackUsed = $searchResponse->fallbackUsed;

        if (! $searchResponse->ok) {
            $errorType = (string) ($searchResponse->errorType ?? 'unexpected_response');
            $status = $this->liveSearchStatusCode($errorType);
            $errorMessage = $searchResponse->userMessage ?? 'Søket kunne ikke fullføres. Prøv igjen om litt.';
            $logLevel = $status >= HttpResponse::HTTP_INTERNAL_SERVER_ERROR ? 'error' : 'warning';

            Log::$logLevel('[DOFFIN][controller] Live notice search returned a controlled error response.', [
                'error_type' => $errorType,
                'mapped_status' => $status,
                'upstream_status' => $searchResponse->upstreamStatus,
                'fallback_used' => $fallbackUsed,
                'customer_id' => $customerId,
                'page' => $page,
                'per_page' => $perPage,
                'live_search' => true,
            ]);

            return $this->renderNoticeIndexPage($request, [
                'mode' => $mode,
                'tab' => $noticeTab,
                'source' => $this->discoverySource($mode, $request),
                'supportMode' => [
                    'active' => false,
                    'message' => null,
                ],
                'filters' => $filters,
                'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
                'savedSearches' => $this->savedSearchesForUser($user, $customerId),
                'worklist' => $worklist,
                'monitoring' => $this->monitoringSummary($user, $customerId),
                'watchAlerts' => $watchAlerts,
                'notices' => [
                    'data' => [],
                    'error' => $errorMessage,
                    'meta' => array_merge(
                        $this->livePaginationMeta($request, $page, $perPage, 0, 0),
                        [
                            'fallback_used' => $fallbackUsed,
                            'error_type' => $errorType,
                            'error_message' => $searchResponse->errorMessage ?? $errorMessage,
                            'upstream_status' => $searchResponse->upstreamStatus,
                        ],
                    ),
                ],
                'historyTypeOptions' => collect(SavedNotice::historyTypeOptions())
                    ->map(fn (array $option): array => $option)
                    ->values()
                    ->all(),
            ], $status);
        }

        $notices = collect($searchResponse->notices);
        $accessibleTotal = $searchResponse->numHitsAccessible;
        $total = $searchResponse->numHitsTotal;
        $hitExternalIds = $notices->pluck('externalId')->filter()->map(fn (mixed $id): string => (string) $id)->all();
        // Matched within the source these hits came from. An external id on its own cannot tell
        // Doffin's notice 123 from another register's notice 123, and answering "already saved"
        // on the strength of a shared number would be wrong in exactly the case that matters.
        $sourceKey = $this->discoverySourceKey($request);
        $savedExternalIds = $this->savedExternalIdsForSource(
            $this->activeSavedNoticeVisibleQuery($user),
            $sourceKey,
            $hitExternalIds,
        );
        $archivedExternalIds = $this->savedExternalIdsForSource(
            $this->archivedSavedNoticeVisibleQuery($user),
            $sourceKey,
            $hitExternalIds,
        );

        // And the same question asked of the procurement rather than the record.
        //
        // A case opened from Doffin is the same case when TED publishes the tender, so a hit whose
        // procurement this customer already works on is already saved — offering it again would
        // hand them a second case for one tender, which is the thing this whole model exists to
        // prevent. Matched only on the identifiers the registers publish themselves; a hit nobody
        // can identify is simply not matched, and falls back on the source-aware answer above.
        $opportunityLinks = $this->opportunityLinks->linkHits(
            $sourceKey,
            $notices->all(),
            $this->caseOpportunityIds($user),
        );
        $savedExternalIds = array_values(array_unique(array_merge(
            $savedExternalIds,
            $this->opportunityLinks->coveredExternalIds($opportunityLinks, $this->activeSavedNoticeVisibleQuery($user)),
        )));
        $archivedExternalIds = array_values(array_unique(array_merge(
            $archivedExternalIds,
            $this->opportunityLinks->coveredExternalIds($opportunityLinks, $this->archivedSavedNoticeVisibleQuery($user)),
        )));

        $items = $notices
            ->map(fn ($notice): array => $notice->toDiscoveryPayload($savedExternalIds, $archivedExternalIds))
            ->all();

        Log::debug('[DOFFIN][ui-contract] Outgoing notice payload ready for frontend.', [
            'result_count' => count($items),
            'total' => $total,
            'accessible_total' => $accessibleTotal,
            'first_item' => $items[0] ?? null,
        ]);

        return $this->renderNoticeIndexPage($request, [
            'mode' => $mode,
            'tab' => $noticeTab,
            'source' => $this->discoverySource($mode, $request),
            'supportMode' => [
                'active' => false,
                'message' => null,
            ],
            'filters' => $filters,
            'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
            'savedSearches' => $this->savedSearchesForUser($user, $customerId),
            'worklist' => $worklist,
            'monitoring' => $this->monitoringSummary($user, $customerId),
            'watchAlerts' => $watchAlerts,
            'notices' => [
                'data' => $items,
                'meta' => array_merge(
                    $this->livePaginationMeta($request, $page, $perPage, $accessibleTotal, count($items), $total),
                    [
                        'fallback_used' => $fallbackUsed,
                    ],
                ),
            ],
            'historyTypeOptions' => collect(SavedNotice::historyTypeOptions())
                ->map(fn (array $option): array => $option)
                ->values()
                ->all(),
        ]);
    }

    public function cpvSuggestions(Request $request): JsonResponse
    {
        $query = trim((string) $request->string('query'));
        $selectedCodes = $this->cpvSearchService->parseCodes((string) $request->string('selected'));
        $limit = min(12, max(5, (int) $request->integer('limit', 8)));

        return response()->json([
            'data' => $this->cpvSearchService->search($query, $selectedCodes, $limit),
        ]);
    }

    private function renderNoticeIndexPage(Request $request, array $props, int $status = HttpResponse::HTTP_OK): HttpResponse
    {
        return Inertia::render('App/Notices/Index', $props)
            ->toResponse($request)
            ->setStatusCode($status);
    }

    public function destroyWatchAlertRecord(Request $request, WatchProfileInboxRecord $watchProfileInboxRecord): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $record = WatchProfileInboxRecord::query()
            ->accessibleTo($user)
            ->whereKey($watchProfileInboxRecord->getKey())
            ->firstOrFail();

        $record->delete();

        return redirect()
            ->back()
            ->with('success', 'Varsel slettet.');
    }

    /**
     * The register that was the only one, for rows written before Procynia recorded which.
     *
     * Not the same question as "which register is this request searching": a case saved in 2025
     * came from Doffin whatever a 2026 request happens to ask for, and `notices.notice_id` holds
     * Doffin's ids however many registers are wired up now.
     */
    private const LEGACY_SOURCE_KEY = DoffinSourceAdapter::SOURCE_KEY;

    /**
     * The register this request is searching.
     *
     * A choice now that there are two. The UI does not offer it yet, so a request that says
     * nothing gets Doffin — every existing link, saved search and bookmark keeps meaning what it
     * meant. A request that does say something gets what it asked for, or nothing: an unknown key
     * is refused by the registry rather than quietly answered by whichever adapter is at hand,
     * which is the whole reason the registry refuses instead of defaulting.
     */
    private function discoverySourceKey(?Request $request = null): string
    {
        $requested = trim((string) ($request?->string('source') ?? ''));

        return $requested === '' ? DoffinSourceAdapter::SOURCE_KEY : $requested;
    }

    /** The adapter for the register being searched. Throws if that register is not registered. */
    private function discoveryAdapter(?Request $request = null): OpportunitySourceAdapter
    {
        return $this->sources->get($this->discoverySourceKey($request));
    }

    /**
     * The adapter for a source that was recorded on a row, or null.
     *
     * Null rather than a default: a row from a register this installation has no adapter for must
     * fall back on what it already stored, not on somebody else's URL builder. A null source is a
     * public row written before the column existed, when the discovery register was the only one
     * there was — those are read under that name, which is what keeps them working.
     */
    private function storedSourceAdapter(?string $source, bool $isPublicNotice): ?OpportunitySourceAdapter
    {
        if (! $isPublicNotice) {
            return null;
        }

        return $this->sources->find($source ?? self::LEGACY_SOURCE_KEY);
    }

    public function storeSavedNotice(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $sourceType = (string) $request->string('source_type');
        $sourceType = in_array($sourceType, SavedNotice::SOURCE_TYPES, true)
            ? $sourceType
            : SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE;

        // True unless a second register's record led us to a case that already exists. A private
        // request has no register at all, so it is always its own record.
        $isOwnRecord = true;

        if ($sourceType === SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST) {
            $validated = $request->validate([
                'source_type' => ['nullable', 'string', Rule::in(SavedNotice::SOURCE_TYPES)],
                'title' => ['required', 'string', 'max:1000'],
                'buyer_name' => ['required', 'string', 'max:1000'],
                'summary' => ['required', 'string'],
                'deadline' => ['nullable', 'date'],
                'reference_number' => ['nullable', 'string', 'max:255'],
                'contact_person_name' => ['nullable', 'string', 'max:255'],
                'contact_person_email' => ['nullable', 'email', 'max:255'],
                'external_url' => ['nullable', 'url', 'max:2000'],
                'notes' => ['nullable', 'string'],
                'status' => ['nullable', 'string', 'max:255'],
            ]);

            $record = new SavedNotice;
            $record->customer_id = $customerId;
            $record->source_type = SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST;
            $record->external_id = sprintf(
                'private-request-%d-%d-%s',
                $customerId,
                $user->id,
                (string) Str::ulid(),
            );
        } else {
            $validated = $request->validate([
                'source_type' => ['nullable', 'string', Rule::in(SavedNotice::SOURCE_TYPES)],
                'notice_id' => ['required', 'string', 'max:255'],
                'title' => ['required', 'string', 'max:1000'],
                'buyer_name' => ['nullable', 'string', 'max:1000'],
                'external_url' => ['nullable', 'url', 'max:2000'],
                'summary' => ['nullable', 'string'],
                'publication_date' => ['nullable', 'date'],
                'deadline' => ['nullable', 'date'],
                'status' => ['nullable', 'string', 'max:255'],
                'cpv_code' => ['nullable', 'string', 'max:255'],
                'rfi_submission_deadline_at' => ['nullable', 'date'],
                'reference_number' => ['nullable', 'string', 'max:255'],
                'contact_person_name' => ['nullable', 'string', 'max:255'],
                'contact_person_email' => ['nullable', 'email', 'max:255'],
                'notes' => ['nullable', 'string'],
            ]);

            // The identity of a public case is the register plus what that register calls the
            // notice. The source comes from the adapter, so there is one spelling of it, and the
            // controller never has to know which register it is talking to.
            $sourceKey = $this->discoverySourceKey($request);

            // Which procurement this record is, if a register has told Procynia. This is the one
            // place that is allowed to pay for the answer — for Doffin it costs a single detail
            // request, once per record ever, and it buys the rule that one procurement is one
            // case however many registers publish it. A null is ordinary: without an identifier
            // nothing below changes, and the case is found the way it always was.
            $opportunity = $this->opportunities->resolveWithLookup($sourceKey, (string) $validated['notice_id']);

            // A public case whose source is still null is a row from before the register was
            // recorded. It is matched too — otherwise saving the same notice again would create a
            // second case beside it and step straight past the archived-case guard below — and
            // named on the way past, so it only ever happens once per row.
            $record = ($opportunity !== null ? $this->opportunities->caseFor($customerId, $opportunity) : null)
                ?? SavedNotice::query()
                    ->where('customer_id', $customerId)
                    ->where('external_id', $validated['notice_id'])
                    ->where(fn (Builder $query) => $query
                        ->where('source', $sourceKey)
                        ->orWhere(fn (Builder $legacy) => $legacy
                            ->whereNull('source')
                            ->where('source_type', SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE)))
                    ->first()
                ?? new SavedNotice([
                    'customer_id' => $customerId,
                    'external_id' => $validated['notice_id'],
                ]);

            // Whether this save is about the register record the case was built from, or about a
            // second register's record of the same procurement. The second kind is why the case
            // was found at all, and it must not rewrite the case: TED's title for a Norwegian
            // tender is its own translation, and the bid manager saved the one they saved.
            $isOwnRecord = ! $record->exists
                || ((string) $record->external_id === (string) $validated['notice_id']
                    && ($record->source === null || $record->source === $sourceKey));

            if ($isOwnRecord) {
                $record->source = $sourceKey;
            }

            if ($opportunity !== null && $record->opportunity_id === null) {
                $record->opportunity_id = $opportunity->id;
            }

            // Link the imported notice when Procynia actually has one. Most public cases are saved
            // straight from a live search or a watch alert and never pass through the import
            // pipeline, so a null here is an ordinary answer — and no Notice is created to avoid it.
            if ($record->notice_id === null) {
                $record->notice_id = Notice::query()
                    ->where('notice_id', (string) $validated['notice_id'])
                    ->value('id');
            }
        }

        // Moving a case to history is final: an archived case is never brought back by saving the
        // same notice again. Bail out before fill() so no case data is overwritten either. A No-Go
        // case has its own explicit, audited reopening flow — it does not go through here.
        if ($record->exists && $record->archived_at !== null) {
            return redirect()
                ->back()
                ->with('error', __('procynia.notices.save_already_in_history'));
        }

        $isNewRecord = ! $record->exists;
        $hadCaseAccess = ! $record->exists || $this->savedNoticeAccess->canView($user, $record);

        // Saving the other register's record of a procurement the customer is already working on
        // reaches the case that exists and leaves its contents alone. Overwriting them would swap
        // the title, the summary and the link for another register's version of the same tender —
        // TED's title for a Norwegian notice is its own translation — which nobody asked for and
        // which would read as data loss. Everything after this still runs: the person pressed save
        // and gets the same access to the case they would have got by saving it first.
        $record->fill($isOwnRecord ? [
            'source_type' => $sourceType,
            'title' => $validated['title'],
            'buyer_name' => $validated['buyer_name'] ?? null,
            'external_url' => $validated['external_url'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'publication_date' => $validated['publication_date'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'status' => $validated['status'] ?? null,
            'cpv_code' => $validated['cpv_code'] ?? null,
            'reference_number' => $validated['reference_number'] ?? null,
            'contact_person_name' => $validated['contact_person_name'] ?? null,
            'contact_person_email' => $validated['contact_person_email'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'rfi_submission_deadline_at' => $validated['rfi_submission_deadline_at'] ?? null,
        ] : []);

        if ($isNewRecord) {
            $record->saved_by_user_id = $user->id;
            $record->bid_status = SavedNotice::BID_STATUS_DISCOVERED;
            $record->organizational_department_id = $user->primaryAffiliationDepartmentId();

            if ($sourceType === SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST) {
                $record->opportunity_owner_user_id = $user->id;
            }
        }

        $record->save();

        if (! $hadCaseAccess) {
            $this->grantSavedNoticeAccess($record, $user, $user, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);
        }

        return redirect()
            ->back()
            ->with('success', 'Anskaffelsen ble lagret.');
    }

    public function updateSavedNoticeDeadlines(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->activeSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $validated = $request->validate([
            'questions_deadline_at' => ['nullable', 'date'],
            'questions_rfi_deadline_at' => ['nullable', 'date'],
            'rfi_submission_deadline_at' => ['nullable', 'date'],
            'questions_rfp_deadline_at' => ['nullable', 'date'],
            'award_date_at' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'contact_person_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'business_reviews' => ['sometimes', 'array'],
            'business_reviews.*.id' => [
                'nullable',
                'integer',
                Rule::exists(SavedNoticeBusinessReview::class, 'id')->where(fn ($query) => $query
                    ->where('saved_notice_id', $record->id)),
            ],
            'business_reviews.*.business_review_at' => ['required', 'date'],
        ]);

        $updates = [];

        foreach ([
            'questions_deadline_at',
            'questions_rfi_deadline_at',
            'rfi_submission_deadline_at',
            'questions_rfp_deadline_at',
            'award_date_at',
            'reference_number',
            'contact_person_name',
            'contact_person_email',
            'notes',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        $record->fill($updates);
        $record->save();

        if (array_key_exists('business_reviews', $validated)) {
            $this->syncSavedNoticeBusinessReviews($record, is_array($validated['business_reviews']) ? $validated['business_reviews'] : []);
        }

        return redirect()
            ->back()
            ->with('success', 'Frister ble oppdatert.');
    }

    public function updateSavedNoticeHistoryMetadata(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->archivedSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $validated = $request->validate([
            'selected_supplier_name' => ['nullable', 'string', 'max:255'],
            'contract_value_mnok' => ['nullable', 'numeric', 'min:0'],
            'procurement_type' => ['required', 'string', Rule::in(SavedNotice::PROCUREMENT_TYPES)],
            'follow_up_mode' => ['required', 'string', Rule::in(SavedNotice::EDITABLE_FOLLOW_UP_MODES)],
            'follow_up_offset_months' => [
                Rule::requiredIf(fn (): bool => $request->input('follow_up_mode') === SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET),
                'nullable',
                'integer',
                'min:1',
                Rule::prohibitedIf(fn (): bool => $request->input('follow_up_mode') !== SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET),
            ],
            'contract_period_months' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $followUpOffsetMonths = array_key_exists('follow_up_offset_months', $validated) && $validated['follow_up_offset_months'] !== null
            ? (int) $validated['follow_up_offset_months']
            : null;
        $contractPeriodMonths = array_key_exists('contract_period_months', $validated) && $validated['contract_period_months'] !== null
            ? (int) $validated['contract_period_months']
            : null;

        $contractValueMnok = array_key_exists('contract_value_mnok', $validated) && $validated['contract_value_mnok'] !== null
            ? round((float) $validated['contract_value_mnok'], 2)
            : null;

        $record->fill([
            'selected_supplier_name' => $validated['selected_supplier_name'] ?? null,
            'contract_value_mnok' => $contractValueMnok,
            'procurement_type' => $validated['procurement_type'],
            'follow_up_mode' => $validated['follow_up_mode'],
            'follow_up_offset_months' => $validated['follow_up_mode'] === SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET ? $followUpOffsetMonths : null,
            'contract_period_months' => $validated['procurement_type'] === SavedNotice::PROCUREMENT_TYPE_RECURRING ? $contractPeriodMonths : null,
            'next_process_date_at' => $this->calculateHistoryNextProcessDate(
                $validated['follow_up_mode'],
                $followUpOffsetMonths,
            ),
        ]);
        $record->save();

        return redirect()
            ->back()
            ->with('success', 'Historikk ble oppdatert.');
    }

    public function archiveSavedNotice(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $validated = $request->validate([
            'history_type' => ['required', 'string', Rule::in(SavedNotice::HISTORY_TYPES)],
        ]);

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereNull('archived_at')
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canArchive($user, $record), 403);

        $record->archiveWithHistoryType((string) $validated['history_type'])->save();

        return redirect()
            ->back()
            ->with('success', 'Anskaffelsen ble flyttet til historikk.');
    }

    public function destroySavedNotice(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->activeSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        if ($record->saved_by_user_id !== $user->id) {
            return redirect()
                ->back()
                ->with('error', 'Du kan bare slette saker du selv har opprettet.');
        }

        $record->delete();

        return redirect()
            ->back()
            ->with('success', 'Anskaffelsen ble fjernet.');
    }

    public function destroyArchivedSavedNotice(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->archivedSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        if ($record->saved_by_user_id !== $user->id) {
            return redirect()
                ->back()
                ->with('error', 'Du kan bare slette saker du selv har opprettet.');
        }

        $record->delete();

        return redirect()
            ->back()
            ->with('success', 'Historikk-kunngjøringen ble slettet.');
    }

    public function showSavedNotice(Request $request, SavedNotice $savedNotice): Response
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->with([
                // Which registers publish this procurement. Null for a case with no identity,
                // which is every case saved before the identifiers were recorded.
                'opportunity.sourceRecords',
                'opportunityOwner:id,name,bid_role',
                'bidManager:id,name,bid_role',
                'businessReviews:id,saved_notice_id,business_review_at',
                'infoItems' => fn ($query) => $query
                    ->with([
                        'owner:id,name,email,bid_role',
                        'createdBy:id,name,email,bid_role',
                    ])
                    ->orderByDesc('created_at')
                    ->orderByDesc('id'),
                'phaseComments' => fn ($query) => $query
                    ->with(['user:id,name,email,bid_role'])
                    ->orderBy('created_at'),
                'noGoDecisions' => fn ($query) => $query
                    ->with([
                        'closedBy:id,name,email,bid_role',
                        'reopenedBy:id,name,email,bid_role',
                    ]),
                'userAccesses' => fn ($query) => $query
                    ->active()
                    ->with([
                        'user:id,name,email,bid_role',
                        'grantedBy:id,name,email',
                    ]),
                'submissions:id,saved_notice_id,sequence_number,label,submitted_at',
            ])
            ->firstOrFail();
        $canManageCase = $this->savedNoticeAccess->canManage($user, $record);
        $canManageContributorAccess = $this->savedNoticeAccess->canManageContributorAccess($user, $record);
        $canComment = $this->savedNoticeAccess->canComment($user, $record);
        $canArchive = $this->savedNoticeAccess->canArchive($user, $record);
        $canReopenAfterNoGo = $this->savedNoticeAccess->canReopenAfterNoGo($user, $record);

        return Inertia::render('App/Notices/SavedShow', [
            'notice' => $this->savedNoticeCasePayload($record, $canManageCase, $canManageContributorAccess, $canComment, $canArchive, $canReopenAfterNoGo),
            'goNoGoData' => $record->bid_status === SavedNotice::BID_STATUS_GO_NO_GO
                ? $this->buildGoNoGoData($record, $customerId, $user)
                : null,
        ]);
    }

    private function buildGoNoGoData(SavedNotice $record, int $customerId, User $user): array
    {
        $template = $this->goNoGoDefaultTemplateService->ensureDefaultExists($customerId);

        $criteria = GoNoGoAssessmentCriterion::query()
            ->where('template_id', $template->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (GoNoGoAssessmentCriterion $c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'short_description' => $c->short_description,
                'weight' => $c->weight,
                'is_score_reversed' => $c->is_score_reversed,
                'sort_order' => $c->sort_order,
                'help_what_is_assessed' => $c->help_what_is_assessed,
                'help_why_it_matters' => $c->help_why_it_matters,
                'help_what_to_investigate' => $c->help_what_to_investigate,
                'help_positive_indicators' => $c->help_positive_indicators,
                'help_warning_signs' => $c->help_warning_signs,
                'help_example_assessment' => $c->help_example_assessment,
            ])
            ->all();

        $assessment = GoNoGoAssessment::query()
            ->where('saved_notice_id', $record->id)
            ->where('template_id', $template->id)
            ->with('answers')
            ->first();

        $answers = $assessment
            ? $assessment->answers->map(fn ($a) => [
                'criterion_id' => $a->criterion_id,
                'selected_value' => $a->selected_value,
                'comment' => $a->comment,
            ])->all()
            : [];

        return [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'criteria' => $criteria,
            ],
            'assessment' => $assessment ? [
                'id' => $assessment->id,
                'recommendation' => $assessment->recommendation,
                'total_score' => $assessment->total_score,
                'max_score' => $assessment->max_score,
                'completed_at' => $assessment->completed_at?->toIso8601String(),
                'updated_at' => $assessment->updated_at?->toIso8601String(),
                'answers' => $answers,
            ] : null,
            'save_url' => route('app.notices.saved.go-no-go-assessment.upsert', ['savedNotice' => $record->id]),
        ];
    }

    public function storeSavedNoticeCaseAccess(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canManageContributorAccess($user, $record), 403);

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->where('is_active', true)
                    ->whereIn('bid_role', [
                        User::BID_ROLE_CONTRIBUTOR,
                        User::BID_ROLE_VIEWER,
                    ])),
            ],
            'access_role' => ['required', 'string', Rule::in(SavedNoticeUserAccess::ACCESS_ROLES)],
        ]);

        $targetUser = User::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereIn('bid_role', [
                User::BID_ROLE_CONTRIBUTOR,
                User::BID_ROLE_VIEWER,
            ])
            ->whereKey((int) $validated['user_id'])
            ->firstOrFail();

        $this->grantSavedNoticeAccess($record, $user, $targetUser, (string) $validated['access_role']);

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Case access was granted.');
    }

    public function storeSavedNoticePhaseComment(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canComment($user, $record), 403);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:4000'],
        ]);

        $comment = trim((string) $validated['comment']);

        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Kommentaren kan ikke være tom.',
            ]);
        }

        $record->phaseComments()->create([
            'user_id' => $user->id,
            'phase_status' => $record->bid_status,
            'comment' => $comment,
        ]);

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Kommentaren ble lagret.');
    }

    public function storeSavedNoticeInfoItem(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(SavedNoticeInfoItem::TYPES)],
            'direction' => ['required', 'string', Rule::in(SavedNoticeInfoItem::DIRECTIONS)],
            'channel' => ['required', 'string', Rule::in(SavedNoticeInfoItem::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', 'string', Rule::in(SavedNoticeInfoItem::STATUSES)],
            'requires_response' => ['nullable', 'boolean'],
            'response_due_at' => ['nullable', 'date'],
            'closure_comment' => ['nullable', 'string', 'max:4000'],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])),
            ],
        ]);

        $subject = trim((string) ($validated['subject'] ?? ''));
        $body = trim((string) $validated['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Aksjonen kan ikke være tom.',
            ]);
        }

        $responseDueAt = isset($validated['response_due_at']) && $validated['response_due_at'] !== null
            ? Carbon::parse($validated['response_due_at'])->startOfDay()
            : null;
        $closureComment = trim((string) ($validated['closure_comment'] ?? ''));

        $record->infoItems()->create([
            'type' => (string) $validated['type'],
            'direction' => (string) $validated['direction'],
            'channel' => (string) $validated['channel'],
            'subject' => $subject !== '' ? $subject : null,
            'body' => $body,
            'status' => (string) $validated['status'],
            'requires_response' => (bool) ($validated['requires_response'] ?? false),
            'response_due_at' => $responseDueAt,
            'owner_user_id' => isset($validated['owner_user_id']) && $validated['owner_user_id'] !== null
                ? (int) $validated['owner_user_id']
                : null,
            'created_by_user_id' => $user->id,
            'closed_at' => (string) $validated['status'] === SavedNoticeInfoItem::STATUS_CLOSED
                ? now()
                : null,
            'closure_comment' => $closureComment !== '' ? $closureComment : null,
        ]);

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Aksjonen ble lagret.');
    }

    public function closeSavedNoticeInfoItem(Request $request, SavedNotice $savedNotice, SavedNoticeInfoItem $infoItem): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canManage($user, $record), 403);

        $targetInfoItem = $record->infoItems()
            ->whereKey($infoItem->id)
            ->firstOrFail();

        if ($targetInfoItem->status === SavedNoticeInfoItem::STATUS_CLOSED) {
            return redirect()
                ->route('app.notices.saved.show', ['savedNotice' => $record->id])
                ->with('error', 'Aksjonen er allerede lukket.');
        }

        $validated = $request->validate([
            'closure_comment' => ['nullable', 'string', 'max:4000'],
        ]);

        $closureComment = trim((string) ($validated['closure_comment'] ?? ''));

        $targetInfoItem->forceFill([
            'status' => SavedNoticeInfoItem::STATUS_CLOSED,
            'closed_at' => now(),
            'closure_comment' => $closureComment !== '' ? $closureComment : null,
        ])->save();

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Aksjonen ble lukket.');
    }

    public function destroySavedNoticeCaseAccess(Request $request, SavedNotice $savedNotice, SavedNoticeUserAccess $caseAccess): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canManageContributorAccess($user, $record), 403);

        $accessRecord = $record->userAccesses()
            ->whereKey($caseAccess->id)
            ->whereNull('revoked_at')
            ->firstOrFail();

        $accessRecord->forceFill([
            'revoked_at' => now(),
        ])->save();

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Case access was revoked.');
    }

    public function storeSavedNoticeSubmission(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->activeSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        if (! $record->canCreateSubmission()) {
            return redirect()
                ->route('app.notices.saved.show', ['savedNotice' => $record->id])
                ->with('error', 'Ny innsending kan bare registreres nar saken er sendt eller i forhandling.');
        }

        $record->createNextSubmission(now());

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Ny innsending ble registrert.');
    }

    public function updateSavedNoticeStatus(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(SavedNotice::BID_STATUSES)],
            'bid_closure_reason' => [
                Rule::requiredIf(fn (): bool => in_array((string) $request->input('status'), [
                    SavedNotice::BID_STATUS_NO_GO,
                    SavedNotice::BID_STATUS_WITHDRAWN,
                ], true)),
                'nullable',
                'string',
                Rule::in(SavedNotice::BID_CLOSURE_REASONS),
            ],
            'bid_closure_note' => ['nullable', 'string'],
        ]);

        $record = $this->activeSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        try {
            if ((string) $validated['status'] === SavedNotice::BID_STATUS_NO_GO) {
                $this->savedNoticeNoGoDecisionService->closeAsNoGo(
                    $record,
                    $user,
                    (string) ($validated['bid_closure_reason'] ?? ''),
                    $validated['bid_closure_note'] ?? null,
                );
            } else {
                $record->transitionBidStatus(
                    (string) $validated['status'],
                    $validated['bid_closure_reason'] ?? null,
                    $validated['bid_closure_note'] ?? null,
                )->save();
            }
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'status' => 'Statusendringen er ikke tillatt for denne saken.',
            ]);
        }

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Saksstatus ble oppdatert.');
    }

    public function reopenSavedNoticeAfterNoGo(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $record = $this->customerSavedNoticeVisibleQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        abort_unless($this->savedNoticeAccess->canReopenAfterNoGo($user, $record), 403);

        $validated = $request->validate([
            'reopen_reason' => ['required', 'string', 'max:4000'],
            'confirm_reopen' => ['accepted'],
        ]);

        try {
            $record = $this->savedNoticeNoGoDecisionService->reopenAfterNoGo(
                $record,
                $user,
                (string) $validated['reopen_reason'],
                (bool) $validated['confirm_reopen'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'reopen_reason' => 'Saken kan ikke gjenåpnes fordi den ikke lenger er No-Go.',
            ]);
        }

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'No-Go-beslutningen ble omgjort. Saken er tilbake i Go / No-Go.');
    }

    public function updateSavedNoticeOpportunityOwner(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $record = $this->customerSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        // A NEW commercial owner has to hold the role. Re-saving the person already on the case
        // does not: cases were assigned before the role existed, and refusing to save an unrelated
        // field on such a case would make it unusable. The current holder is therefore accepted as
        // themselves and nobody else.
        $validated = $request->validate([
            'opportunity_owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])),
                function (string $attribute, mixed $value, callable $fail) use ($record): void {
                    if ($value === null || (int) $value === (int) $record->opportunity_owner_user_id) {
                        return;
                    }

                    $candidate = User::query()->find((int) $value);

                    if (! $candidate instanceof User || ! $candidate->isCommercialOwner()) {
                        $fail('Kommersiell eier må være en bruker med rollen Kommersiell eier.');
                    }
                },
            ],
        ]);

        $record->fill([
            'opportunity_owner_user_id' => isset($validated['opportunity_owner_user_id']) && $validated['opportunity_owner_user_id'] !== null
                ? (int) $validated['opportunity_owner_user_id']
                : null,
        ])->save();

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Kommersiell eier ble oppdatert.');
    }

    public function updateSavedNoticeBidManager(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return redirect()
                ->back()
                ->with('error', 'Customer context is required.');
        }

        $validated = $request->validate([
            'bid_manager_user_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
                    ->where('bid_role', User::BID_ROLE_BID_MANAGER)),
            ],
        ]);

        $record = $this->customerSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $record->fill([
            'bid_manager_user_id' => isset($validated['bid_manager_user_id']) && $validated['bid_manager_user_id'] !== null
                ? (int) $validated['bid_manager_user_id']
                : null,
        ])->save();

        return redirect()
            ->route('app.notices.saved.show', ['savedNotice' => $record->id])
            ->with('success', 'Bid-manager ble oppdatert.');
    }

    public function show(Request $request, int $notice): Response
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $record = Notice::query()
            ->tap(fn (Builder $query): Builder => $this->customerContext->scopeNoticeDiscovery($query, $user))
            ->whereKey($notice)
            ->with([
                'attentions' => fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->with('department')
                    ->orderByDesc('department_score')
                    ->orderByDesc('is_new'),
                'cpvCodes.catalogEntry',
                'documents',
            ])
            ->firstOrFail();

        if ($record->documents->isEmpty()) {
            $this->documentService->syncFromNotice($record);
            $record->load('documents');
        }

        $contexts = $record->attentions
            ->map(function (NoticeAttention $attention) use ($record): array {
                $departmentScore = is_array($record->department_scores)
                    ? ($record->department_scores[(string) $attention->department_id] ?? $record->department_scores[$attention->department_id] ?? [])
                    : [];

                return [
                    'department' => $attention->department?->name,
                    'score' => $attention->department_score,
                    'relevance_level' => $attention->relevance_level,
                    'is_new' => $attention->is_new,
                    'watch_profile_name' => data_get($departmentScore, 'watch_profile_name'),
                ];
            })
            ->values();

        $primaryContext = $contexts->first();
        $watchProfiles = $contexts
            ->pluck('watch_profile_name')
            ->filter()
            ->unique()
            ->values();

        return Inertia::render('App/Notices/Show', [
            'notice' => [
                'id' => $record->id,
                'notice_id' => $record->notice_id,
                'title' => $record->title,
                'description' => $record->description,
                'status' => $record->status,
                'publication_date' => optional($record->publication_date)?->toIso8601String(),
                'deadline' => optional($record->deadline)?->toIso8601String(),
                'buyer_name' => $record->buyer_name,
                'relevance_score' => $primaryContext['score'] ?? null,
                'relevance_level' => $primaryContext['relevance_level'] ?? null,
                'reason_summary' => $watchProfiles->isNotEmpty()
                    ? __('procynia.frontend.reason_watch_profile', ['profiles' => $watchProfiles->implode(', ')])
                    : 'Denne kunngjøringen kommer direkte fra Doffin-søket.',
                'department_contexts' => $contexts->all(),
                'cpv_codes' => $record->cpvCodes
                    ->sortBy('cpv_code')
                    ->values()
                    ->map(fn ($cpv): array => [
                        'code' => $cpv->cpv_code,
                        'description' => $this->customerContext->cpvDescription($cpv->catalogEntry, $user)
                            ?? $cpv->cpv_description_no
                            ?? $cpv->cpv_description_en
                            ?? null,
                    ])
                    ->all(),
                'documents' => $record->documents
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (NoticeDocument $document): array => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'mime_type' => $document->mime_type,
                        'file_size' => $document->file_size,
                        'download_url' => route('app.notices.documents.download', [
                            'notice' => $record->id,
                            'document' => $document->id,
                        ]),
                    ])
                    ->all(),
                'download_all_url' => route('app.notices.documents.download-all', ['notice' => $record->id]),
            ],
        ]);
    }

    private function noticeMode(string $value): string
    {
        return match ($value) {
            'saved', 'history' => $value,
            default => 'live',
        };
    }

    private function customerSavedNoticeVisibleQuery(User $user, ?int $customerId = null, bool $useCockpitScope = false): Builder
    {
        if ($useCockpitScope) {
            $customerId ??= $this->customerContext->currentCustomerId($user);

            if ($customerId === null) {
                return SavedNotice::query()->whereRaw('1 = 0');
            }

            return $this->savedNoticeAccess->cockpitScopeQueryFor($user, $customerId);
        }

        return $this->savedNoticeAccess->visibleQueryFor($user);
    }

    private function activeSavedNoticeVisibleQuery(User $user, ?int $customerId = null, bool $useCockpitScope = false): Builder
    {
        return $this->customerSavedNoticeVisibleQuery($user, $customerId, $useCockpitScope)
            ->whereNull('archived_at');
    }

    private function archivedSavedNoticeVisibleQuery(User $user, ?int $customerId = null, bool $useCockpitScope = false): Builder
    {
        return $this->customerSavedNoticeVisibleQuery($user, $customerId, $useCockpitScope)
            ->whereNotNull('archived_at')
            ->whereIn('history_type', SavedNotice::HISTORY_TYPES);
    }

    /**
     * The external ids, within one source, that the given query already holds.
     *
     * Returned as a flat list because every hit in a live search comes from the same source, so
     * the source is a filter rather than part of the answer.
     *
     * @param  array<int, string>  $externalIds
     * @return array<int, string>
     */
    private function savedExternalIdsForSource(Builder $query, string $source, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        return $query
            // A legacy public case that predates the source column belongs to the register that
            // was the only one when it was written.
            ->where(fn (Builder $scope) => $scope
                ->where('source', $source)
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->whereNull('source')
                    ->where('source_type', SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE)))
            ->whereIn('external_id', $externalIds)
            ->pluck('external_id')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }

    /**
     * The same question across several sources at once, for a list that may mix them.
     *
     * Keyed "source\nexternal_id" rather than returned as a flat list: a watch list can hold the
     * same number from two registers, and a flat list would say both were saved as soon as one of
     * them was.
     *
     * @param  array<int, string>  $sources
     * @param  array<int, string>  $externalIds
     * @return array<int, string>
     */
    private function savedIdentitiesForSources(Builder $query, array $sources, array $externalIds): array
    {
        if ($sources === [] || $externalIds === []) {
            return [];
        }

        // A legacy public case that predates the source column came from the only register there
        // was, which is the one the adapter speaks for. Read under that name so it keeps matching;
        // a row from any other register always names itself and is never folded in here.
        $legacySource = self::LEGACY_SOURCE_KEY;

        return $query
            ->where(fn (Builder $scope) => $scope
                ->whereIn('source', $sources)
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->whereNull('source')
                    ->where('source_type', SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE)))
            ->whereIn('external_id', $externalIds)
            ->get(['source', 'external_id'])
            ->map(fn ($row): string => self::sourceIdentityKey(
                $row->source !== null ? (string) $row->source : $legacySource,
                (string) $row->external_id,
            ))
            ->all();
    }

    /** One string for one external identity, so two registers can never be mistaken for each other. */
    private static function sourceIdentityKey(string $source, string $externalId): string
    {
        return $source."\n".$externalId;
    }

    /**
     * The procurements this user can see a case for, open or archived.
     *
     * Read through the ordinary visibility query, so a case somebody else in the customer keeps
     * private stays private here too — a hit must not read as "already saved" on the strength of
     * a case the person looking is not allowed to know about.
     *
     * @return array<int, int>
     */
    private function caseOpportunityIds(User $user): array
    {
        return $this->customerSavedNoticeVisibleQuery($user)
            ->whereNotNull('opportunity_id')
            ->distinct()
            ->pluck('opportunity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function customerSavedNoticeManageableQuery(User $user): Builder
    {
        return $this->savedNoticeAccess->manageableQueryFor($user);
    }

    private function activeSavedNoticeManageableQuery(User $user): Builder
    {
        return $this->customerSavedNoticeManageableQuery($user)
            ->whereNull('archived_at');
    }

    private function archivedSavedNoticeManageableQuery(User $user): Builder
    {
        return $this->customerSavedNoticeManageableQuery($user)
            ->whereNotNull('archived_at')
            ->whereIn('history_type', SavedNotice::HISTORY_TYPES);
    }

    private function savedNoticeCounts(User $user, int $customerId, bool $useCockpitScope = false): array
    {
        return [
            'saved_count' => $this->activeSavedNoticeVisibleQuery($user, $customerId, $useCockpitScope)->count(),
            'history_count' => $this->archivedSavedNoticeVisibleQuery($user, $customerId, $useCockpitScope)->count(),
        ];
    }

    private function watchAlertsPayload(?User $user, ?int $customerId): array
    {
        if ($customerId === null || ! ($user instanceof User)) {
            return $this->emptyWatchAlertsPayload();
        }

        $records = WatchProfileInboxRecord::query()
            ->accessibleTo($user)
            ->where('customer_id', $customerId)
            ->where('discovered_at', '>=', now()->subDay())
            ->with(['watchProfile:id,name'])
            ->orderByDesc('discovered_at')
            ->orderByDesc('id')
            ->get();

        if ($records->isEmpty()) {
            return $this->emptyWatchAlertsPayload();
        }

        $alertExternalIds = $records->pluck('external_id')->filter()->map(fn (mixed $value): string => (string) $value)->all();
        // Keyed by source, because two registers may name a notice the same thing. Each record is
        // then asked about its own source rather than about a flat list of ids.
        $alertSources = $records->pluck('source')->filter()->map(fn (mixed $value): string => (string) $value)->unique()->all();
        $savedExternalIds = $this->savedIdentitiesForSources(
            $this->activeSavedNoticeVisibleQuery($user, $customerId),
            $alertSources,
            $alertExternalIds,
        );
        $archivedExternalIds = $this->savedIdentitiesForSources(
            $this->archivedSavedNoticeVisibleQuery($user, $customerId),
            $alertSources,
            $alertExternalIds,
        );

        return [
            'data' => $records
                ->map(fn (WatchProfileInboxRecord $record): array => $this->watchAlertListItem($record, $savedExternalIds, $archivedExternalIds))
                ->all(),
            'meta' => [
                'total' => $records->count(),
            ],
        ];
    }

    private function watchAlertListItem(WatchProfileInboxRecord $record, array $savedExternalIds = [], array $archivedExternalIds = []): array
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $cpvCodes = collect(data_get($rawPayload, 'cpvCodes', []))
            ->filter(fn (mixed $cpv): bool => is_string($cpv) && trim($cpv) !== '')
            ->map(fn (string $cpv): string => trim($cpv))
            ->values();
        $summary = trim((string) data_get($rawPayload, 'description', ''));
        $cpvCode = trim((string) data_get($rawPayload, 'mainCpvCode', ''));

        if ($cpvCode === '') {
            $cpvCode = (string) $cpvCodes->first();
        }

        // Identity comes from the source, not from a Doffin id. For a Doffin record the two are
        // the same string, so the payload the frontend already reads is unchanged.
        $externalId = (string) $record->external_id;
        // The adapter for the register this record actually came from. A record from a register
        // Procynia has no adapter for keeps whatever URL was stored with it and is offered
        // nothing else — it is not handed another register's link because the ids look alike.
        $recordAdapter = $this->sources->find((string) $record->source);

        // An open notice shows no status badge — the card says how long is left instead, and
        // stamping "still open" on something that is obviously open is noise. Which stored word
        // means open is the register's business, so the record's own adapter reads it; a record
        // from a register Procynia cannot speak to keeps whatever word was stored with it.
        $normalized = $recordAdapter?->normalizeLiveSearchHit($rawPayload);
        $status = trim((string) data_get($rawPayload, 'status', ''));

        if ($normalized !== null) {
            $status = $normalized->status === OpportunityStatus::Open
                ? ''
                : (string) $normalized->providerStatusLabel();
        }

        return [
            'id' => $record->id,
            'notice_id' => $externalId,
            // Additive: what the frontend reads is above, but a consumer that wants to know where
            // a hit came from no longer has to infer it from a column name.
            'source' => (string) $record->source,
            'external_id' => $externalId,
            'title' => trim((string) $record->title) !== '' ? trim((string) $record->title) : $externalId,
            'buyer_name' => $record->buyer_name,
            'summary' => $summary !== '' ? Str::squish($summary) : null,
            'publication_date' => optional($record->publication_date)?->toIso8601String(),
            'deadline' => optional($record->deadline)?->toIso8601String(),
            'status' => $status !== '' ? $status : null,
            'relevance_level' => null,
            'score' => null,
            'department' => null,
            'saved_search_name' => null,
            'cpv_code' => $cpvCode !== '' ? $cpvCode : null,
            'is_new' => false,
            // The stored URL is what the source actually gave us; its own adapter is the fallback.
            'external_url' => $record->external_url ?: $recordAdapter?->sourceUrl($externalId),
            // SavedNotice is still identified by a bare external id, so these compare like for like
            // only while Doffin is the only source. Making that comparison source-aware is Phase 3B.
            // Asked as an identity, not as a number: a record from another register that happens
            // to share this id is a different opportunity and must not read as already saved.
            'is_saved' => in_array(self::sourceIdentityKey((string) $record->source, $externalId), $savedExternalIds, true),
            'is_in_history' => in_array(self::sourceIdentityKey((string) $record->source, $externalId), $archivedExternalIds, true),
            'watch_profile_name' => $record->watchProfile?->name,
            'discovered_at' => optional($record->discovered_at)?->toIso8601String(),
            'delete_url' => route('app.notices.watch-alerts.destroy', ['watchProfileInboxRecord' => $record->id]),
        ];
    }

    private function emptyWatchAlertsPayload(): array
    {
        return [
            'data' => [],
            'meta' => [
                'total' => 0,
            ],
        ];
    }

    private function savedNoticeResult(Request $request, User $user, string $mode, int $page, int $perPage, int $customerId, bool $useCockpitScope = false): array
    {
        $query = $mode === 'history'
            ? $this->archivedSavedNoticeVisibleQuery($user, $customerId, $useCockpitScope)
            : $this->activeSavedNoticeVisibleQuery($user, $customerId, $useCockpitScope);
        $bidStatus = trim((string) $request->string('bid_status'));
        $historyType = trim((string) $request->string('history_type'));

        if ($mode === 'history') {
            if ($historyType !== '' && in_array($historyType, SavedNotice::HISTORY_TYPES, true)) {
                $query->where('history_type', $historyType);
            }
        } elseif ($bidStatus !== '' && in_array($bidStatus, SavedNotice::BID_STATUSES, true)) {
            $query->where('bid_status', $bidStatus);
        }

        $total = (clone $query)->count();
        $records = $query
            ->with([
                'savedBy:id,name',
                'opportunityOwner:id,name',
                'bidManager:id,name',
                'businessReviews:id,saved_notice_id,business_review_at',
            ])
            ->withCount('submissions')
            ->orderByDesc('updated_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $records
                ->map(fn (SavedNotice $notice): array => $this->savedNoticeListItem($user, $notice))
                ->all(),
            'meta' => $this->livePaginationMeta($request, $page, $perPage, $total, $records->count()),
        ];
    }

    private function savedNoticeListItem(User $user, SavedNotice $notice): array
    {
        $nextDeadline = $this->nextRelevantSavedNoticeDeadline($notice);
        $canArchive = $this->savedNoticeAccess->canArchive($user, $notice);

        return [
            'id' => $notice->id,
            'saved_notice_id' => $notice->id,
            'notice_id' => $notice->external_id,
            // Additive: the payload key above is the external id the frontend already reads, and
            // keeps its name. These two say which register it belongs to and whether Procynia
            // holds the imported notice behind it.
            'source' => $notice->source,
            'source_notice_id' => $notice->notice_id,
            'source_type' => $notice->source_type,
            'source_type_label' => $notice->source_type_label,
            'title' => $notice->title,
            'buyer_name' => $notice->buyer_name,
            'summary' => $notice->summary,
            'publication_date' => optional($notice->publication_date)?->toIso8601String(),
            'deadline' => optional($notice->deadline)?->toIso8601String(),
            'status' => $notice->status,
            'relevance_level' => null,
            'score' => null,
            'department' => null,
            'saved_search_name' => null,
            'cpv_code' => $notice->cpv_code,
            'is_new' => false,
            'external_url' => $this->savedNoticeExternalUrl($notice),
            'show_url' => route('app.notices.saved.show', ['savedNotice' => $notice->id]),
            'is_saved' => $notice->archived_at === null,
            'bid_status' => $notice->bid_status,
            'bid_status_label' => $notice->bid_status_label,
            'history_type' => $notice->history_type,
            'history_type_label' => $notice->history_type ? $notice->history_type_label : null,
            'submissions_count' => (int) ($notice->submissions_count ?? 0),
            'opportunity_owner_name' => $notice->opportunityOwner?->name,
            'reference_number' => $notice->reference_number,
            'contact_person_name' => $notice->contact_person_name,
            'contact_person_email' => $notice->contact_person_email,
            'next_deadline_type' => $nextDeadline['type'],
            'next_deadline_at' => $nextDeadline['type'] === 'Business Review'
                ? $nextDeadline['at']?->toDateString()
                : $nextDeadline['at']?->toIso8601String(),
            'deadline_state' => $nextDeadline['state'],
            'saved_by_name' => $notice->savedBy?->name,
            'saved_at' => optional($notice->created_at)?->toIso8601String(),
            'notes' => $notice->notes,
            'questions_deadline_at' => optional($notice->questions_deadline_at)?->toIso8601String(),
            'questions_rfi_deadline_at' => optional($notice->questions_rfi_deadline_at)?->toIso8601String(),
            'rfi_submission_deadline_at' => optional($notice->rfi_submission_deadline_at)?->toIso8601String(),
            'questions_rfp_deadline_at' => optional($notice->questions_rfp_deadline_at)?->toIso8601String(),
            'rfp_submission_deadline_at' => optional($notice->rfp_submission_deadline_at)?->toIso8601String(),
            'award_date_at' => optional($notice->award_date_at)?->toIso8601String(),
            'business_reviews' => $notice->businessReviews
                ->map(fn (SavedNoticeBusinessReview $businessReview): array => [
                    'id' => $businessReview->id,
                    'business_review_at' => $businessReview->business_review_at?->toDateString(),
                ])
                ->all(),
            'selected_supplier_name' => $notice->selected_supplier_name,
            'contract_value_mnok' => $notice->contract_value_mnok !== null ? (float) $notice->contract_value_mnok : null,
            'contract_period_text' => $notice->contract_period_text,
            'contract_period_months' => $notice->contract_period_months,
            'procurement_type' => $notice->procurement_type,
            'follow_up_mode' => $notice->follow_up_mode,
            'follow_up_offset_months' => $notice->follow_up_offset_months,
            'next_process_date_at' => optional($notice->next_process_date_at)?->toIso8601String(),
            'can_delete' => $notice->saved_by_user_id === $user->id,
            'actions' => [
                'can_archive' => $canArchive,
                'archive_url' => $canArchive
                    ? route('app.notices.saved.archive', ['savedNotice' => $notice->id])
                    : null,
            ],
        ];
    }

    /**
     * The registers a case's procurement is published in.
     *
     * Provenance, shown rather than folded away. Doffin and TED both publishing one tender is the
     * ordinary situation for anything over the EEA threshold, and a bid manager who saved it from
     * one of them should be able to see that the other has it too — not least because the two
     * records carry different reference numbers, and somebody will eventually be given the one
     * that is not in the case.
     *
     * Deduplicated by register, because a register can hold several records of one procurement:
     * TED republishes, and a procurement's change notice and award notice are separate rows. Two
     * "TED" entries would say nothing the first one does not.
     *
     * A case with no identity gets the one register it was saved from, which is the whole truth
     * about it. A private request gets nothing — it has no register.
     *
     * @return array<int, array{key: string, label: string, external_id: string, url: ?string, is_case_origin: bool}>
     */
    private function caseSourcesPayload(SavedNotice $notice): array
    {
        if ($notice->source_type !== SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE) {
            return [];
        }

        $ownSource = $notice->source ?? self::LEGACY_SOURCE_KEY;
        $ownEntry = [
            'key' => $ownSource,
            'label' => $this->registerName($ownSource),
            'external_id' => (string) $notice->external_id,
            'url' => $this->savedNoticeExternalUrl($notice),
            'is_case_origin' => true,
        ];

        $sources = collect($notice->opportunity?->sourceRecords ?? [])
            ->filter(fn (OpportunitySourceRecord $record): bool => trim((string) $record->source) !== '')
            ->sortBy(fn (OpportunitySourceRecord $record): string => (string) $record->source)
            ->unique(fn (OpportunitySourceRecord $record): string => (string) $record->source)
            ->map(fn (OpportunitySourceRecord $record): array => [
                'key' => (string) $record->source,
                'label' => $this->registerName((string) $record->source),
                'external_id' => (string) $record->external_id,
                'url' => $this->sourceRecordUrl($record),
                'is_case_origin' => (string) $record->source === $ownSource,
            ])
            ->values()
            ->all();

        if ($sources === []) {
            return [$ownEntry];
        }

        // The case's own register goes first and is never missing from the list: it is where the
        // title, the deadline and the documents in front of the reader actually came from.
        $own = array_values(array_filter($sources, fn (array $source): bool => $source['is_case_origin']));
        $others = array_values(array_filter($sources, fn (array $source): bool => ! $source['is_case_origin']));

        return [...($own === [] ? [$ownEntry] : $own), ...$others];
    }

    /** The register's own short name, or its key when this installation has no adapter for it. */
    private function registerName(string $sourceKey): string
    {
        return $this->sources->find($sourceKey)?->registerName() ?? Str::upper($sourceKey);
    }

    /** What the register stored, or what its adapter would build now. */
    private function sourceRecordUrl(OpportunitySourceRecord $record): ?string
    {
        $stored = trim((string) $record->source_url);

        if ($stored !== '') {
            return $stored;
        }

        return $this->sources->find((string) $record->source)?->sourceUrl((string) $record->external_id);
    }

    private function savedNoticeCasePayload(
        SavedNotice $notice,
        bool $canManageCase,
        bool $canManageContributorAccess,
        bool $canComment,
        bool $canArchive,
        bool $canReopenAfterNoGo,
    ): array {
        $nextDeadline = $this->nextRelevantSavedNoticeDeadline($notice);
        $isMutableCase = $notice->archived_at === null && $canManageCase;
        $canCreateSubmission = $isMutableCase && $notice->canCreateSubmission();
        $statusActions = $isMutableCase ? $notice->availableBidStatusActions() : [];
        $documentsPayload = $this->savedNoticeDocumentsPayload($notice);

        return [
            'id' => $notice->id,
            'notice_id' => $notice->external_id,
            // Additive: the payload key above is the external id the frontend already reads, and
            // keeps its name. These two say which register it belongs to and whether Procynia
            // holds the imported notice behind it.
            'source' => $notice->source,
            'source_notice_id' => $notice->notice_id,
            'source_type' => $notice->source_type,
            'source_type_label' => $notice->source_type_label,
            // The registers this procurement is published in, the case's own first.
            'sources' => $this->caseSourcesPayload($notice),
            'title' => $notice->title,
            'organization_name' => $notice->buyer_name,
            'external_url' => $this->savedNoticeExternalUrl($notice),
            'ai_show_url' => route('app.ai.show', ['savedNotice' => $notice->id]),
            'summary' => $notice->summary,
            'cpv_code' => $notice->cpv_code,
            'publication_date' => optional($notice->publication_date)?->toIso8601String(),
            'deadline' => optional($notice->deadline)?->toIso8601String(),
            'next_deadline_type' => $nextDeadline['type'],
            'next_deadline_at' => $nextDeadline['type'] === 'Business Review'
                ? $nextDeadline['at']?->toDateString()
                : $nextDeadline['at']?->toIso8601String(),
            'deadline_state' => $nextDeadline['state'],
            'bid_status' => $notice->bid_status,
            'bid_status_label' => $notice->bid_status_label,
            'history_type' => $notice->history_type,
            'history_type_label' => $notice->history_type ? $notice->history_type_label : null,
            'bid_closed_at' => optional($notice->bid_closed_at)?->toIso8601String(),
            'bid_closure_reason' => $notice->bid_closure_reason,
            'bid_closure_reason_label' => $notice->bid_closure_reason ? $notice->bid_closure_reason_label : null,
            'bid_closure_note' => $notice->bid_closure_note,
            'bid_submitted_at' => optional($notice->bid_submitted_at)?->toIso8601String(),
            'archived_at' => optional($notice->archived_at)?->toIso8601String(),
            'reference_number' => $notice->reference_number,
            'contact_person_name' => $notice->contact_person_name,
            'contact_person_email' => $notice->contact_person_email,
            'notes' => $notice->notes,
            'documents' => $documentsPayload['documents'],
            'download_all_url' => $documentsPayload['download_all_url'],
            'info_items' => [
                'can_create' => true,
                'store_url' => route('app.notices.saved.info-items.store', ['savedNotice' => $notice->id]),
                'defaults' => [
                    'type' => SavedNoticeInfoItem::TYPE_NOTE,
                    'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
                    'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
                    'status' => SavedNoticeInfoItem::STATUS_OPEN,
                ],
                'type_options' => collect(SavedNoticeInfoItem::typeOptions())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
                'direction_options' => collect(SavedNoticeInfoItem::directionOptions())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
                'channel_options' => collect(SavedNoticeInfoItem::channelOptions())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
                'status_options' => collect(SavedNoticeInfoItem::statusOptions())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
                'owner_options' => $this->customerOpportunityOwnerOptions(
                    (int) $notice->customer_id,
                    $notice->opportunity_owner_user_id !== null ? (int) $notice->opportunity_owner_user_id : null,
                ),
                'items' => $notice->infoItems
                    ->map(fn (SavedNoticeInfoItem $infoItem): array => [
                        'id' => $infoItem->id,
                        'type' => $infoItem->type,
                        'type_label' => $infoItem->type_label,
                        'direction' => $infoItem->direction,
                        'direction_label' => $infoItem->direction_label,
                        'channel' => $infoItem->channel,
                        'channel_label' => $infoItem->channel_label,
                        'subject' => $infoItem->subject,
                        'body' => $infoItem->body,
                        'status' => $infoItem->status,
                        'status_label' => $infoItem->status_label,
                        'requires_response' => (bool) $infoItem->requires_response,
                        'response_due_at' => optional($infoItem->response_due_at)?->toDateString(),
                        'closed_at' => optional($infoItem->closed_at)?->toIso8601String(),
                        'closure_comment' => $infoItem->closure_comment,
                        'created_at' => optional($infoItem->created_at)?->toIso8601String(),
                        'can_close' => $canManageCase && $infoItem->status !== SavedNoticeInfoItem::STATUS_CLOSED,
                        'close_url' => $canManageCase && $infoItem->status !== SavedNoticeInfoItem::STATUS_CLOSED
                            ? route('app.notices.saved.info-items.close', [
                                'savedNotice' => $notice->id,
                                'infoItem' => $infoItem->id,
                            ])
                            : null,
                        'owner' => $infoItem->owner ? [
                            'id' => $infoItem->owner->id,
                            'name' => $infoItem->owner->name,
                        ] : null,
                        'created_by' => $infoItem->createdBy ? [
                            'id' => $infoItem->createdBy->id,
                            'name' => $infoItem->createdBy->name,
                        ] : null,
                    ])
                    ->all(),
            ],
            'business_reviews' => $notice->businessReviews
                ->map(fn (SavedNoticeBusinessReview $businessReview): array => [
                    'id' => $businessReview->id,
                    'business_review_at' => $businessReview->business_review_at?->toDateString(),
                ])
                ->all(),
            'saved_at' => optional($notice->created_at)?->toIso8601String(),
            'opportunity_owner' => $notice->opportunityOwner
                ? [
                    'id' => $notice->opportunityOwner->id,
                    'name' => $notice->opportunityOwner->name,
                    'bid_role' => $notice->opportunityOwner->resolvedBidRole(),
                    'bid_role_label' => $notice->opportunityOwner->bid_role_label,
                ]
                : null,
            'bid_manager' => $notice->bidManager
                ? [
                    'id' => $notice->bidManager->id,
                    'name' => $notice->bidManager->name,
                    'bid_role' => $notice->bidManager->resolvedBidRole(),
                    'bid_role_label' => $notice->bidManager->bid_role_label,
                ]
                : null,
            'submissions' => $notice->submissions
                ->map(fn ($submission): array => [
                    'id' => $submission->id,
                    'sequence_number' => $submission->sequence_number,
                    'label' => $submission->label,
                    'submitted_at' => optional($submission->submitted_at)?->toIso8601String(),
                ])
                ->all(),
            'phase_comments' => [
                'can_comment' => $canComment,
                'store_url' => $canComment
                    ? route('app.notices.saved.phase-comments.store', ['savedNotice' => $notice->id])
                    : null,
                'active_phase_status' => $notice->bid_status,
                'active_phase_label' => $notice->bid_status_label,
                'comments' => $notice->phaseComments
                    ->map(fn (SavedNoticePhaseComment $comment): array => [
                        'id' => $comment->id,
                        'phase_status' => $comment->phase_status,
                        'phase_status_label' => $comment->phase_status_label,
                        'comment' => $comment->comment,
                        'created_at' => optional($comment->created_at)?->toIso8601String(),
                        'user' => $comment->user ? [
                            'id' => $comment->user->id,
                            'name' => $comment->user->name,
                            'email' => $comment->user->email,
                            'bid_role' => $comment->user->resolvedBidRole(),
                            'bid_role_label' => $comment->user->bid_role_label,
                        ] : null,
                    ])
                    ->all(),
            ],
            'no_go_decisions' => $notice->noGoDecisions
                ->map(fn (SavedNoticeNoGoDecision $decision): array => [
                    'id' => $decision->id,
                    'closure_reason' => $decision->closure_reason,
                    'closure_reason_label' => $decision->closure_reason
                        ? SavedNotice::BID_CLOSURE_REASON_LABELS[$decision->closure_reason] ?? $decision->closure_reason
                        : null,
                    'closure_note' => $decision->closure_note,
                    'closed_at' => optional($decision->closed_at)?->toIso8601String(),
                    'closed_by' => $decision->closedBy ? [
                        'id' => $decision->closedBy->id,
                        'name' => $decision->closedBy->name,
                    ] : null,
                    'reopen_reason' => $decision->reopen_reason,
                    'reopened_at' => optional($decision->reopened_at)?->toIso8601String(),
                    'reopened_by' => $decision->reopenedBy ? [
                        'id' => $decision->reopenedBy->id,
                        'name' => $decision->reopenedBy->name,
                    ] : null,
                    'reopened_from_archived_at' => optional($decision->reopened_from_archived_at)?->toIso8601String(),
                    'reopened_from_history_type' => $decision->reopened_from_history_type,
                ])
                ->all(),
            'back_url' => route('app.notices.index', ['mode' => $notice->archived_at ? 'history' : 'saved']),
            'back_label' => $notice->archived_at ? 'Tilbake til historikk' : 'Tilbake til arbeidsliste',
            'actions' => [
                'update_status_url' => $isMutableCase
                    ? route('app.notices.saved.status.update', ['savedNotice' => $notice->id])
                    : null,
                'status_actions' => $statusActions,
                'can_reopen_after_no_go' => $canReopenAfterNoGo,
                'reopen_after_no_go_url' => $canReopenAfterNoGo
                    ? route('app.notices.saved.reopen-after-no-go', ['savedNotice' => $notice->id])
                    : null,
                'closure_reasons' => SavedNotice::bidClosureReasonOptions(),
                'update_opportunity_owner_url' => $canManageCase
                    ? route('app.notices.saved.opportunity-owner.update', ['savedNotice' => $notice->id])
                    : null,
                'opportunity_owner_options' => $canManageCase
                    ? $this->customerOpportunityOwnerOptions(
                        (int) $notice->customer_id,
                        $notice->opportunity_owner_user_id !== null ? (int) $notice->opportunity_owner_user_id : null,
                    )
                    : [],
                'update_bid_manager_url' => $canManageCase
                    ? route('app.notices.saved.bid-manager.update', ['savedNotice' => $notice->id])
                    : null,
                'bid_manager_options' => $canManageCase
                    ? $this->customerBidManagerOptions((int) $notice->customer_id)
                    : [],
                'case_access' => [
                    'can_manage' => $canManageContributorAccess,
                    'store_url' => $canManageContributorAccess
                        ? route('app.notices.saved.case-access.store', ['savedNotice' => $notice->id])
                        : null,
                    'access_role_options' => $canManageContributorAccess
                        ? $this->caseAccessRoleOptions()
                        : [],
                    'user_options' => $canManageContributorAccess
                        ? $this->customerCaseAccessUserOptions((int) $notice->customer_id)
                        : [],
                    'accesses' => $canManageContributorAccess
                        ? $notice->userAccesses
                            ->map(fn (SavedNoticeUserAccess $access): array => [
                                'id' => $access->id,
                                'user' => $access->user ? [
                                    'id' => $access->user->id,
                                    'name' => $access->user->name,
                                    'email' => $access->user->email,
                                ] : null,
                                'access_role' => $access->access_role,
                                'access_role_label' => $access->access_role_label,
                                'granted_by' => $access->grantedBy ? [
                                    'id' => $access->grantedBy->id,
                                    'name' => $access->grantedBy->name,
                                    'email' => $access->grantedBy->email,
                                ] : null,
                                'granted_at' => optional($access->created_at)?->toIso8601String(),
                                'revoke_url' => route('app.notices.saved.case-access.destroy', [
                                    'savedNotice' => $notice->id,
                                    'caseAccess' => $access->id,
                                ]),
                            ])
                            ->all()
                        : [],
                ],
                'can_create_submission' => $canCreateSubmission,
                'create_submission_url' => $canCreateSubmission
                    ? route('app.notices.saved.submissions.store', ['savedNotice' => $notice->id])
                    : null,
                'can_archive' => $canArchive,
                'archive_url' => $canArchive
                    ? route('app.notices.saved.archive', ['savedNotice' => $notice->id])
                    : null,
                'history_type_options' => collect(SavedNotice::historyTypeOptions())
                    ->map(fn (array $option): array => $option)
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Resolve the original notice documents for a saved notice.
     *
     * @return array{documents: array<int, array<string, mixed>>, download_all_url: ?string}
     */
    private function savedNoticeDocumentsPayload(SavedNotice $notice): array
    {
        // The link, when the case has one. Falling back to matching the external id by hand is
        // what this has always done, and it stays — but only for a case from the register whose
        // ids `notices.notice_id` actually holds. Another register's id must not reach in here and
        // collect a Doffin notice's documents because the two happen to share a number.
        // The bare-id fallback only holds for the register whose ids `notices.notice_id` carries,
        // which is the discovery register. Any other register's case reaches the imported notice
        // through the link or not at all.
        $matchesImportedIds = $this->storedSourceAdapter($notice->source, $notice->isPublicNotice())
            ?->sourceKey() === self::LEGACY_SOURCE_KEY;

        $sourceNotice = $notice->notice_id !== null
            ? Notice::query()->whereKey($notice->notice_id)->with('documents')->first()
            : ($matchesImportedIds
                ? Notice::query()->where('notice_id', (string) $notice->external_id)->with('documents')->first()
                : null);

        if ($sourceNotice === null) {
            return [
                'documents' => [],
                'download_all_url' => null,
            ];
        }

        $documents = $sourceNotice->documents
            ->sortBy('sort_order')
            ->values()
            ->map(fn (NoticeDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'created_at' => optional($document->created_at)?->toIso8601String(),
                'download_url' => route('app.notices.documents.download', [
                    'notice' => $sourceNotice->id,
                    'document' => $document->id,
                ]),
            ])
            ->all();

        return [
            'documents' => $documents,
            'download_all_url' => count($documents) > 1
                ? route('app.notices.documents.download-all', ['notice' => $sourceNotice->id])
                : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $businessReviews
     */
    private function syncSavedNoticeBusinessReviews(SavedNotice $notice, array $businessReviews): void
    {
        $existingReviews = $notice->businessReviews()
            ->get()
            ->keyBy('id');
        $keptReviewIds = [];

        foreach ($businessReviews as $reviewData) {
            if (! is_array($reviewData)) {
                continue;
            }

            $reviewId = isset($reviewData['id']) && $reviewData['id'] !== ''
                ? (int) $reviewData['id']
                : null;
            $businessReviewAt = $reviewData['business_review_at'] ?? null;
            $normalizedBusinessReviewAt = $businessReviewAt !== null
                ? Carbon::parse($businessReviewAt)->startOfDay()
                : null;

            if ($reviewId !== null && $existingReviews->has($reviewId)) {
                $review = $existingReviews->get($reviewId);

                $review->forceFill([
                    'business_review_at' => $normalizedBusinessReviewAt,
                ])->save();

                $keptReviewIds[] = $review->id;

                continue;
            }

            $review = $notice->businessReviews()->create([
                'business_review_at' => $normalizedBusinessReviewAt,
            ]);

            $keptReviewIds[] = $review->id;
        }

        $notice->businessReviews()
            ->when($keptReviewIds !== [], function (Builder $query) use ($keptReviewIds): Builder {
                return $query->whereNotIn('id', $keptReviewIds);
            }, function (Builder $query): Builder {
                return $query;
            })
            ->delete();
    }

    private function grantSavedNoticeAccess(SavedNotice $notice, User $grantedBy, User $user, string $accessRole): void
    {
        $access = $notice->userAccesses()->firstOrNew([
            'user_id' => $user->id,
        ]);

        $shouldRefreshGrantTimestamp = $access->exists && $access->revoked_at !== null;

        $access->forceFill([
            'granted_by_user_id' => $grantedBy->id,
            'access_role' => $accessRole,
            'expires_at' => null,
            'revoked_at' => null,
        ]);

        if ($shouldRefreshGrantTimestamp) {
            $access->forceFill([
                'created_at' => now(),
            ]);
        }

        $access->save();
    }

    private function caseAccessRoleOptions(): array
    {
        return collect(SavedNoticeUserAccess::accessRoleOptions())
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function customerCaseAccessUserOptions(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereIn('bid_role', [
                User::BID_ROLE_CONTRIBUTOR,
                User::BID_ROLE_VIEWER,
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'bid_role'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => "{$user->name} · {$user->email} · {$user->bid_role_label}",
            ])
            ->values()
            ->all();
    }

    private function calculateHistoryNextProcessDate(
        string $followUpMode,
        ?int $followUpOffsetMonths,
    ): ?CarbonInterface {
        return match ($followUpMode) {
            SavedNotice::FOLLOW_UP_MODE_NONE => null,
            SavedNotice::FOLLOW_UP_MODE_MANUAL_OFFSET => $followUpOffsetMonths !== null
                ? now()->addMonthsNoOverflow($followUpOffsetMonths)
                : null,
            default => null,
        };
    }

    /**
     * Who may be picked as the commercial owner of a case: the customer's users who hold that main
     * role — the same rule customerBidManagerOptions() applies to Bid Manager.
     *
     * $currentOwnerId keeps whoever is already assigned in the list even if they do not hold the
     * role. Cases were assigned before the role existed, and a select whose current value is not
     * among its options renders as empty and silently clears the field on the next save. Such a
     * person stays visible on the case they already have and is not offered anywhere else.
     */
    private function customerOpportunityOwnerOptions(int $customerId, ?int $currentOwnerId = null): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->where(function ($query) use ($currentOwnerId): void {
                $query->where('bid_role', User::BID_ROLE_COMMERCIAL_OWNER);

                if ($currentOwnerId !== null) {
                    $query->orWhere('id', $currentOwnerId);
                }
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'bid_role'])
            ->map(function (User $user): array {
                $bidRoleLabel = match ($user->resolvedBidRole()) {
                    User::BID_ROLE_COMMERCIAL_OWNER => 'Kommersiell eier',
                    User::BID_ROLE_BID_MANAGER => 'Bid-manager',
                    User::BID_ROLE_VIEWER => 'Lesetilgang',
                    default => 'Bid-bidragsyter',
                };

                return [
                    'value' => $user->id,
                    'label' => $user->is_active
                        ? "{$user->name} · {$bidRoleLabel}"
                        : "{$user->name} · {$bidRoleLabel} (inaktiv)",
                ];
            })
            ->all();
    }

    private function customerBidManagerOptions(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->where('bid_role', User::BID_ROLE_BID_MANAGER)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->is_active
                    ? $user->name
                    : "{$user->name} (inaktiv)",
            ])
            ->all();
    }

    /**
     * The next deadline a case is actually working towards.
     *
     * The official notice deadline IS the RFP submission deadline — the rest of the model already
     * treats it that way, so the separate rfp_submission_deadline_at column is legacy and stays
     * out of this on purpose. No type outranks another: the earliest future candidate wins.
     */
    private function nextRelevantSavedNoticeDeadline(SavedNotice $notice): array
    {
        $now = now();
        $businessReviewCandidates = $notice->businessReviews
            ->map(fn (SavedNoticeBusinessReview $businessReview): array => [
                'type' => 'Business Review',
                'at' => $businessReview->business_review_at,
            ])
            ->filter(fn (array $candidate): bool => $candidate['at'] !== null)
            ->values();
        $candidates = collect([
            [
                'type' => 'RFI',
                'at' => $notice->rfi_submission_deadline_at,
            ],
            [
                'type' => 'RFP',
                'at' => $notice->deadline,
            ],
        ])
            ->merge($businessReviewCandidates)
            ->filter(fn (array $candidate): bool => $candidate['at'] !== null)
            ->values();

        $upcoming = $candidates
            ->filter(fn (array $candidate): bool => $candidate['at']->greaterThan($now))
            ->sortBy(fn (array $candidate): int => $candidate['at']->getTimestamp())
            ->values();

        if ($upcoming->isNotEmpty()) {
            return [
                'state' => 'upcoming',
                'type' => $upcoming[0]['type'],
                'at' => $upcoming[0]['at'],
            ];
        }

        if ($candidates->isEmpty()) {
            return [
                'state' => 'missing',
                'type' => null,
                'at' => null,
            ];
        }

        return [
            'state' => 'expired',
            'type' => null,
            'at' => null,
        ];
    }

    private function liveSearchStatusCode(string $errorType): int
    {
        return match ($errorType) {
            'invalid_request' => HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            'upstream_unavailable' => HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            'timeout' => HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            'connection_error' => HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            'unexpected_response' => HttpResponse::HTTP_BAD_GATEWAY,
            default => HttpResponse::HTTP_BAD_GATEWAY,
        };
    }

    private function livePaginationMeta(Request $request, int $page, int $perPage, int $accessibleTotal, int $count, ?int $displayTotal = null): array
    {
        $displayTotal ??= $accessibleTotal;
        $isCapped = $displayTotal > $accessibleTotal;
        $lastPage = $this->liveLastPage($accessibleTotal, $perPage, $isCapped);
        $from = $accessibleTotal > 0 ? (($page - 1) * $perPage) + 1 : null;
        $to = $count > 0 && $from !== null ? $from + $count - 1 : null;

        return [
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $displayTotal,
            'from' => $from,
            'to' => $to,
            'prev_page_url' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            'next_page_url' => $page < $lastPage ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
            'numHitsTotal' => $displayTotal,
            'numHitsAccessible' => $accessibleTotal,
            'is_capped' => $isCapped,
        ];
    }

    private function liveLastPage(int $accessibleTotal, int $perPage, bool $isCapped): int
    {
        if ($accessibleTotal <= 0) {
            return 1;
        }

        if ($isCapped) {
            return max(1, (int) floor($accessibleTotal / $perPage));
        }

        return max(1, (int) ceil($accessibleTotal / $perPage));
    }

    private function savedNoticeExternalUrl(SavedNotice $notice): ?string
    {
        if ($notice->isPrivateRequest()) {
            return $notice->external_url;
        }

        // The stored URL first, because it is what the register actually gave us. Its own adapter
        // is the only fallback — a case from a register Procynia cannot speak to gets null rather
        // than a link built by whichever adapter happened to be at hand.
        return $notice->external_url
            ?: $this->storedSourceAdapter($notice->source, true)?->sourceUrl($notice->external_id);
    }

    private function emptySearchResult(): array
    {
        return [
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 15,
                'total' => 0,
                'from' => null,
                'to' => null,
                'prev_page_url' => null,
                'next_page_url' => null,
                'numHitsTotal' => 0,
                'numHitsAccessible' => 0,
                'is_capped' => false,
            ],
        ];
    }

    private function publicationDateRangeFromPeriod(string $publicationPeriod): array
    {
        if (! in_array($publicationPeriod, ['1', '7', '30', '90', '365'], true)) {
            return ['', ''];
        }

        $days = (int) $publicationPeriod;

        return [
            Carbon::now()->subDays($days)->toDateString(),
            Carbon::now()->toDateString(),
        ];
    }

    private function discoverySource(string $mode = 'live', ?Request $request = null): array
    {
        return match ($mode) {
            'saved' => [
                'type' => 'saved_notices',
                'label' => 'Registrerte kunngjøringer',
            ],
            'history' => [
                'type' => 'saved_notice_history',
                'label' => 'Historikk',
            ],
            default => [
                'type' => $this->discoverySourceKey($request).'_live_search',
                'label' => $this->discoveryAdapter($request)->label(),
            ],
        };
    }

    private function cpvSelectorPayload(string $cpvFilter): array
    {
        $selected = $this->cpvSearchService->selectedFromFilter($cpvFilter);

        return [
            'endpoint' => route('app.notices.cpv-suggestions'),
            'selected' => $selected,
            'popular' => $this->cpvSearchService->popular(array_column($selected, 'code')),
        ];
    }

    private function savedSearchesForUser(User $user, int $customerId): array
    {
        return WatchProfile::query()
            ->accessibleTo($user)
            ->where('customer_id', $customerId)
            ->active()
            ->with([
                'user:id,name',
                'department:id,name',
                'cpvCodes' => fn ($query) => $query
                    ->select(['id', 'watch_profile_id', 'cpv_code'])
                    ->orderBy('cpv_code'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (WatchProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'summary' => $this->savedSearchSummary($profile),
                'department' => $profile->department?->name,
                'owner_scope' => $profile->ownerScope(),
                'owner_reference' => $profile->isUserOwned()
                    ? ($profile->user?->name ?? 'Ukjent bruker')
                    : ($profile->department?->name ?? 'Ukjent avdeling'),
                'frequency' => null,
                'prefill' => $this->savedSearchPrefill($profile),
            ])
            ->all();
    }

    private function savedSearchPrefill(WatchProfile $profile): array
    {
        $keywords = collect($profile->keywords ?? [])
            ->filter(fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== '')
            ->map(fn (string $keyword): string => trim($keyword))
            ->values()
            ->all();
        $cpvItems = $profile->cpvCodes
            ->pluck('cpv_code')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->map(fn (string $code): ?array => $this->cpvSearchService->resolve($code))
            ->filter()
            ->values()
            ->all();

        return [
            'organization_name' => null,
            'cpv_items' => $cpvItems,
            'keywords' => implode(', ', $keywords),
            'publication_period' => null,
            'status' => 'ACTIVE',
            'relevance' => null,
        ];
    }

    private function monitoringSummary(?User $user, ?int $customerId): array
    {
        return [
            'new_hits_last_day_count' => $customerId === null || ! ($user instanceof User)
                ? 0
                : WatchProfileInboxRecord::query()
                    ->accessibleTo($user)
                    ->where('customer_id', $customerId)
                    ->where('discovered_at', '>=', now()->subDay())
                    ->count(),
            'next_update_text' => 'Nattlig Doffin-discovery kjører hver dag kl. 01:15.',
        ];
    }

    private function savedSearchSummary(WatchProfile $profile): string
    {
        $keywords = collect($profile->keywords)
            ->filter(fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== '')
            ->map(fn (string $keyword): string => trim($keyword))
            ->values();

        if ($keywords->isNotEmpty()) {
            return $keywords->take(3)->implode(', ');
        }

        $cpvCodes = $profile->cpvCodes
            ->pluck('cpv_code')
            ->filter()
            ->values();

        if ($cpvCodes->isNotEmpty()) {
            return 'CPV '.$cpvCodes->take(3)->implode(', ');
        }

        $description = trim(Str::squish((string) $profile->description));

        if ($description !== '') {
            return Str::limit($description, 90);
        }

        if ($profile->department?->name) {
            return 'Avdeling: '.$profile->department->name;
        }

        return 'Kriterier definert for dette lagrede søket.';
    }
}
