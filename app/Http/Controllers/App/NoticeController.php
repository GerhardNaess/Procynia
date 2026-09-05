<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GoNoGoAssessment;
use App\Models\GoNoGoAssessmentCriterion;
use App\Models\Notice;
use App\Models\NoticeAttention;
use App\Models\NoticeDocument;
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
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Services\GoNoGo\GoNoGoDefaultTemplateService;
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
        private readonly DoffinLiveSearchService $liveSearchService,
        private readonly DoffinNoticeDocumentService $documentService,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly SavedNoticeNoGoDecisionService $savedNoticeNoGoDecisionService,
        private readonly GoNoGoDefaultTemplateService $goNoGoDefaultTemplateService,
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
                'source' => $this->discoverySource($mode),
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
                'source' => $this->discoverySource($mode),
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
                'source' => $this->discoverySource($mode),
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

        $searchFilters = $filters;

        if ($keywordsMode !== '') {
            $searchFilters['keywords_mode'] = $keywordsMode;
        }

        $searchResponse = $this->liveSearchService->search($searchFilters, $page, $perPage);
        $page = max(1, (int) ($searchResponse['page'] ?? $page));
        $perPage = max(1, (int) ($searchResponse['perPage'] ?? $perPage));
        $fallbackUsed = (bool) ($searchResponse['fallback_used'] ?? false);

        if (! ($searchResponse['ok'] ?? true)) {
            $errorType = (string) ($searchResponse['error_type'] ?? 'unexpected_response');
            $status = $this->liveSearchStatusCode($errorType);
            $errorMessage = $this->liveSearchErrorMessage($errorType);
            $logLevel = $status >= HttpResponse::HTTP_INTERNAL_SERVER_ERROR ? 'error' : 'warning';

            Log::$logLevel('[DOFFIN][controller] Live notice search returned a controlled error response.', [
                'error_type' => $errorType,
                'mapped_status' => $status,
                'upstream_status' => $searchResponse['upstream_status'] ?? null,
                'request_id' => $searchResponse['meta']['request_id'] ?? null,
                'fallback_used' => $fallbackUsed,
                'customer_id' => $customerId,
                'page' => $page,
                'per_page' => $perPage,
                'live_search' => true,
            ]);

            return $this->renderNoticeIndexPage($request, [
                'mode' => $mode,
                'tab' => $noticeTab,
                'source' => $this->discoverySource($mode),
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
                            'error_message' => $searchResponse['error_message'] ?? $errorMessage,
                            'upstream_status' => $searchResponse['upstream_status'] ?? null,
                        ],
                    ),
                ],
                'historyTypeOptions' => collect(SavedNotice::historyTypeOptions())
                    ->map(fn (array $option): array => $option)
                    ->values()
                    ->all(),
            ], $status);
        }

        $hits = collect($this->liveSearchItems($searchResponse))
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->values();
        $accessibleTotal = (int) ($searchResponse['numHitsAccessible'] ?? $searchResponse['numHitsTotal'] ?? $hits->count());
        $total = (int) ($searchResponse['numHitsTotal'] ?? $accessibleTotal);
        $hitExternalIds = $hits->pluck('id')->filter()->map(fn (mixed $id): string => (string) $id)->all();
        $savedExternalIds = $this->activeSavedNoticeVisibleQuery($user)
            ->whereIn('external_id', $hitExternalIds)
            ->pluck('external_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $archivedExternalIds = $this->archivedSavedNoticeVisibleQuery($user)
            ->whereIn('external_id', $hitExternalIds)
            ->pluck('external_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $items = $hits
            ->map(fn (array $hit): array => $this->liveNoticeListItem($hit, $savedExternalIds, $archivedExternalIds))
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
            'source' => $this->discoverySource($mode),
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
                'rfp_submission_deadline_at' => ['nullable', 'date'],
                'reference_number' => ['nullable', 'string', 'max:255'],
                'contact_person_name' => ['nullable', 'string', 'max:255'],
                'contact_person_email' => ['nullable', 'email', 'max:255'],
                'notes' => ['nullable', 'string'],
            ]);

            $record = SavedNotice::query()->firstOrNew([
                'customer_id' => $customerId,
                'external_id' => $validated['notice_id'],
            ]);
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

        $record->fill([
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
            'rfp_submission_deadline_at' => $validated['rfp_submission_deadline_at'] ?? null,
        ]);

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
            'rfp_submission_deadline_at' => ['nullable', 'date'],
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
            'rfp_submission_deadline_at',
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

        $validated = $request->validate([
            'opportunity_owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])),
            ],
        ]);

        $record = $this->customerSavedNoticeManageableQuery($user)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

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

        $alertExternalIds = $records->pluck('doffin_notice_id')->filter()->map(fn (mixed $value): string => (string) $value)->all();
        $savedExternalIds = $this->activeSavedNoticeVisibleQuery($user, $customerId)
            ->whereIn('external_id', $alertExternalIds)
            ->pluck('external_id')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
        $archivedExternalIds = $this->archivedSavedNoticeVisibleQuery($user, $customerId)
            ->whereIn('external_id', $alertExternalIds)
            ->pluck('external_id')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

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
        $status = strtoupper(trim((string) data_get($rawPayload, 'status', '')));
        $cpvCode = trim((string) data_get($rawPayload, 'mainCpvCode', ''));

        if ($cpvCode === '') {
            $cpvCode = (string) $cpvCodes->first();
        }

        if ($status === 'ACTIVE') {
            $status = '';
        }

        return [
            'id' => $record->id,
            'notice_id' => $record->doffin_notice_id,
            'title' => trim((string) $record->title) !== '' ? trim((string) $record->title) : $record->doffin_notice_id,
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
            'external_url' => $record->external_url ?: $this->publicNoticeUrl($record->doffin_notice_id),
            'is_saved' => in_array($record->doffin_notice_id, $savedExternalIds, true),
            'is_in_history' => in_array($record->doffin_notice_id, $archivedExternalIds, true),
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
            'source_type' => $notice->source_type,
            'source_type_label' => $notice->source_type_label,
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
                'owner_options' => $this->customerOpportunityOwnerOptions((int) $notice->customer_id),
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
                    ? $this->customerOpportunityOwnerOptions((int) $notice->customer_id)
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
        $sourceNotice = Notice::query()
            ->where('notice_id', (string) $notice->external_id)
            ->with('documents')
            ->first();

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

    private function customerOpportunityOwnerOptions(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'bid_role'])
            ->map(function (User $user): array {
                $bidRoleLabel = match ($user->resolvedBidRole()) {
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

    private function liveNoticeListItem(array $hit, array $savedExternalIds = [], array $archivedExternalIds = []): array
    {
        $buyers = collect($hit['buyer'] ?? [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer))
            ->map(fn (array $buyer): string => trim((string) ($buyer['name'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $description = trim((string) ($hit['description'] ?? ''));
        $cpvCodes = collect($hit['cpvCodes'] ?? [])
            ->filter(fn (mixed $cpv): bool => is_string($cpv) && trim($cpv) !== '')
            ->values();
        $noticeId = (string) ($hit['id'] ?? '');
        $publicationDate = $hit['publicationDate'] ?? $hit['issueDate'] ?? null;

        return [
            'id' => $noticeId,
            'notice_id' => $noticeId,
            'title' => trim((string) ($hit['heading'] ?? '')),
            'buyer_name' => $buyers->implode(', '),
            'summary' => $description !== '' ? Str::squish($description) : null,
            'publication_date' => $publicationDate,
            'deadline' => $hit['deadline'] ?? null,
            'status' => $hit['status'] ?? null,
            'relevance_level' => null,
            'score' => null,
            'department' => null,
            'saved_search_name' => null,
            'cpv_code' => $cpvCodes->first(),
            'is_new' => false,
            'external_url' => $this->publicNoticeUrl($noticeId),
            'is_saved' => in_array($noticeId, $savedExternalIds, true),
            'is_in_history' => in_array($noticeId, $archivedExternalIds, true),
        ];
    }

    private function liveSearchItems(array $searchResponse): array
    {
        return collect($searchResponse['items'] ?? $searchResponse['hits'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
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

    private function liveSearchErrorMessage(string $errorType): string
    {
        return match ($errorType) {
            'invalid_request' => 'Søket mot Doffin ble avvist. Kontroller filtrene og prøv igjen.',
            'upstream_unavailable' => 'Doffin er midlertidig utilgjengelig. Prøv igjen om litt.',
            'timeout' => 'Doffin svarte ikke i tide. Prøv igjen om litt.',
            'connection_error' => 'Klarte ikke å koble til Doffin. Prøv igjen om litt.',
            'unexpected_response' => 'Doffin returnerte et uventet svar. Prøv igjen om litt.',
            default => 'Doffin-søket kunne ikke fullføres. Prøv igjen om litt.',
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

    private function publicNoticeUrl(string $noticeId): ?string
    {
        if ($noticeId === '') {
            return null;
        }

        return sprintf((string) config('doffin.public_notice_url'), rawurlencode($noticeId));
    }

    private function savedNoticeExternalUrl(SavedNotice $notice): ?string
    {
        if ($notice->isPrivateRequest()) {
            return $notice->external_url;
        }

        return $notice->external_url ?: $this->publicNoticeUrl($notice->external_id);
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

    private function discoverySource(string $mode = 'live'): array
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
                'type' => 'doffin_live_search',
                'label' => 'Live søk i Doffin',
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
