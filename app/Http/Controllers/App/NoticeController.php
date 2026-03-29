<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeAttention;
use App\Models\NoticeDocument;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\Cpv\CustomerNoticeCpvSearchService;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class NoticeController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly CustomerNoticeCpvSearchService $cpvSearchService,
        private readonly DoffinLiveSearchService $liveSearchService,
        private readonly DoffinNoticeDocumentService $documentService,
    ) {
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);
        $mode = $this->noticeMode((string) $request->string('mode'));

        $filters = [
            'q' => trim((string) $request->string('q')),
            'organization_name' => trim((string) $request->string('organization_name')),
            'cpv' => trim((string) $request->string('cpv')),
            'keywords' => trim((string) $request->string('keywords')),
            'publication_period' => trim((string) $request->string('publication_period')),
            'status' => trim((string) $request->string('status')),
            'relevance' => trim((string) $request->string('relevance')),
        ];

        if ($customerId === null) {
            return Inertia::render('App/Notices/Index', [
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
                'monitoring' => $this->monitoringSummary(null, null),
                'notices' => $this->emptySearchResult(),
            ]);
        }

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = 15;
        $worklist = $this->savedNoticeCounts($customerId);

        if ($mode !== 'live') {
            return Inertia::render('App/Notices/Index', [
                'mode' => $mode,
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
                'notices' => $this->savedNoticeResult($request, $customerId, $mode, $page, $perPage),
            ]);
        }

        Log::debug('[DOFFIN][controller] Incoming live notice search request.', [
            'q' => $filters['q'],
            'keywords' => $filters['keywords'],
            'organization_name' => $filters['organization_name'],
            'cpv' => $filters['cpv'],
            'publication_period' => $filters['publication_period'],
            'status' => $filters['status'],
            'page' => $page,
            'per_page' => $perPage,
            'customer_id' => $customerId,
        ]);

        $searchResponse = $this->liveSearchService->search($filters, $page, $perPage);
        $hits = collect($searchResponse['hits'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->values();
        $accessibleTotal = (int) ($searchResponse['numHitsAccessible'] ?? $searchResponse['numHitsTotal'] ?? $hits->count());
        $total = (int) ($searchResponse['numHitsTotal'] ?? $accessibleTotal);
        $savedExternalIds = $this->activeSavedNoticeQuery($customerId)
            ->whereIn('external_id', $hits->pluck('id')->filter()->map(fn (mixed $id): string => (string) $id)->all())
            ->pluck('external_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $items = $hits
            ->map(fn (array $hit): array => $this->liveNoticeListItem($hit, $savedExternalIds))
            ->all();

        Log::debug('[DOFFIN][ui-contract] Outgoing notice payload ready for frontend.', [
            'result_count' => count($items),
            'total' => $total,
            'accessible_total' => $accessibleTotal,
            'first_item' => $items[0] ?? null,
        ]);

        return Inertia::render('App/Notices/Index', [
            'mode' => $mode,
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
            'notices' => [
                'data' => $items,
                'meta' => $this->livePaginationMeta($request, $page, $perPage, $accessibleTotal, count($items), $total),
            ],
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

        $validated = $request->validate([
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
        ]);

        $record = SavedNotice::query()->firstOrNew([
            'customer_id' => $customerId,
            'external_id' => $validated['notice_id'],
        ]);
        $isNewRecord = ! $record->exists;

        $record->fill([
            'title' => $validated['title'],
            'buyer_name' => $validated['buyer_name'] ?? null,
            'external_url' => $validated['external_url'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'publication_date' => $validated['publication_date'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'status' => $validated['status'] ?? null,
            'cpv_code' => $validated['cpv_code'] ?? null,
            'archived_at' => null,
            'rfi_submission_deadline_at' => $validated['rfi_submission_deadline_at'] ?? null,
            'rfp_submission_deadline_at' => $validated['rfp_submission_deadline_at'] ?? null,
        ]);

        if ($isNewRecord) {
            $record->saved_by_user_id = $user->id;
        }

        $record->save();

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

        $record = $this->activeSavedNoticeQuery($customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $validated = $request->validate([
            'questions_deadline_at' => ['nullable', 'date'],
            'questions_rfi_deadline_at' => ['nullable', 'date'],
            'rfi_submission_deadline_at' => ['nullable', 'date'],
            'questions_rfp_deadline_at' => ['nullable', 'date'],
            'rfp_submission_deadline_at' => ['nullable', 'date'],
            'award_date_at' => ['nullable', 'date'],
        ]);

        $updates = [];

        foreach ([
            'questions_deadline_at',
            'questions_rfi_deadline_at',
            'rfi_submission_deadline_at',
            'questions_rfp_deadline_at',
            'rfp_submission_deadline_at',
            'award_date_at',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        $record->fill($updates);
        $record->save();

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

        $record = $this->archivedSavedNoticeQuery($customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $validated = $request->validate([
            'selected_supplier_name' => ['nullable', 'string', 'max:255'],
            'contract_value_mnok' => ['nullable', 'numeric', 'min:0'],
            'contract_period_months' => ['nullable', 'integer', 'min:0'],
        ]);

        $contractPeriodMonths = array_key_exists('contract_period_months', $validated) && $validated['contract_period_months'] !== null
            ? (int) $validated['contract_period_months']
            : null;

        $contractValueMnok = array_key_exists('contract_value_mnok', $validated) && $validated['contract_value_mnok'] !== null
            ? round((float) $validated['contract_value_mnok'], 2)
            : null;

        $record->fill([
            'selected_supplier_name' => $validated['selected_supplier_name'] ?? null,
            'contract_value_mnok' => $contractValueMnok,
            'contract_period_months' => $contractPeriodMonths,
            'next_process_date_at' => $this->calculateNextProcessDate($record->award_date_at, $contractPeriodMonths),
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

        $record = $this->activeSavedNoticeQuery($customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $record->forceFill([
            'archived_at' => now(),
        ])->save();

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

        $record = $this->activeSavedNoticeQuery($customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

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

        $record = $this->archivedSavedNoticeQuery($customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();

        $record->delete();

        return redirect()
            ->back()
            ->with('success', 'Historikk-kunngjøringen ble slettet.');
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

    private function activeSavedNoticeQuery(int $customerId): Builder
    {
        return SavedNotice::query()
            ->where('customer_id', $customerId)
            ->whereNull('archived_at');
    }

    private function archivedSavedNoticeQuery(int $customerId): Builder
    {
        return SavedNotice::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('archived_at');
    }

    private function savedNoticeCounts(int $customerId): array
    {
        return [
            'saved_count' => $this->activeSavedNoticeQuery($customerId)->count(),
            'history_count' => $this->archivedSavedNoticeQuery($customerId)->count(),
        ];
    }

    private function savedNoticeResult(Request $request, int $customerId, string $mode, int $page, int $perPage): array
    {
        $query = $mode === 'history'
            ? $this->archivedSavedNoticeQuery($customerId)
            : $this->activeSavedNoticeQuery($customerId);

        $total = (clone $query)->count();
        $records = $query
            ->with('savedBy:id,name')
            ->orderByDesc('updated_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $records
                ->map(fn (SavedNotice $notice): array => $this->savedNoticeListItem($notice))
                ->all(),
            'meta' => $this->livePaginationMeta($request, $page, $perPage, $total, $records->count()),
        ];
    }

    private function savedNoticeListItem(SavedNotice $notice): array
    {
        $nextDeadline = $this->nextRelevantSavedNoticeDeadline($notice);

        return [
            'id' => $notice->id,
            'saved_notice_id' => $notice->id,
            'notice_id' => $notice->external_id,
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
            'external_url' => $notice->external_url ?: $this->publicNoticeUrl($notice->external_id),
            'is_saved' => $notice->archived_at === null,
            'next_deadline_type' => $nextDeadline['type'],
            'next_deadline_at' => optional($nextDeadline['at'])?->toIso8601String(),
            'deadline_state' => $nextDeadline['state'],
            'saved_by_name' => $notice->savedBy?->name,
            'saved_at' => optional($notice->created_at)?->toIso8601String(),
            'questions_deadline_at' => optional($notice->questions_deadline_at)?->toIso8601String(),
            'questions_rfi_deadline_at' => optional($notice->questions_rfi_deadline_at)?->toIso8601String(),
            'rfi_submission_deadline_at' => optional($notice->rfi_submission_deadline_at)?->toIso8601String(),
            'questions_rfp_deadline_at' => optional($notice->questions_rfp_deadline_at)?->toIso8601String(),
            'rfp_submission_deadline_at' => optional($notice->rfp_submission_deadline_at)?->toIso8601String(),
            'award_date_at' => optional($notice->award_date_at)?->toIso8601String(),
            'selected_supplier_name' => $notice->selected_supplier_name,
            'contract_value_mnok' => $notice->contract_value_mnok !== null ? (float) $notice->contract_value_mnok : null,
            'contract_period_months' => $notice->contract_period_months,
            'next_process_date_at' => optional($notice->next_process_date_at)?->toIso8601String(),
        ];
    }

    private function calculateNextProcessDate($awardDate, ?int $contractPeriodMonths)
    {
        if ($awardDate === null || $contractPeriodMonths === null) {
            return null;
        }

        return $awardDate->copy()
            ->addMonthsNoOverflow($contractPeriodMonths)
            ->subMonthsNoOverflow(6);
    }

    private function nextRelevantSavedNoticeDeadline(SavedNotice $notice): array
    {
        $now = now();
        $candidates = collect([
            [
                'type' => 'RFI',
                'at' => $notice->rfi_submission_deadline_at,
            ],
            [
                'type' => 'RFP',
                'at' => $notice->rfp_submission_deadline_at,
            ],
        ])->filter(fn (array $candidate): bool => $candidate['at'] !== null)->values();

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

    private function liveNoticeListItem(array $hit, array $savedExternalIds = []): array
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
            'summary' => $description !== '' ? Str::limit(Str::squish($description), 220) : null,
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
        ];
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

    private function discoverySource(string $mode = 'live'): array
    {
        return match ($mode) {
            'saved' => [
                'type' => 'saved_notices',
                'label' => 'Lagrede kunngjøringer',
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
            ])
            ->all();
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
            return 'CPV ' . $cpvCodes->take(3)->implode(', ');
        }

        $description = trim(Str::squish((string) $profile->description));

        if ($description !== '') {
            return Str::limit($description, 90);
        }

        if ($profile->department?->name) {
            return 'Avdeling: ' . $profile->department->name;
        }

        return 'Kriterier definert for dette lagrede søket.';
    }
}
