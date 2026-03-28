<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeAttention;
use App\Models\NoticeDocument;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\WatchProfile;
use App\Services\Cpv\CustomerNoticeCpvSearchService;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
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
                'source' => $this->discoverySource(),
                'supportMode' => [
                    'active' => $user->isSuperAdmin(),
                    'message' => __('procynia.frontend.super_admin_context_required'),
                ],
                'filters' => $filters,
                'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
                'savedSearches' => [],
                'notices' => $this->emptySearchResult(),
            ]);
        }

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = 15;

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
        $total = (int) ($searchResponse['numHitsAccessible'] ?? $searchResponse['numHitsTotal'] ?? $hits->count());
        $savedExternalIds = SavedNotice::query()
            ->where('customer_id', $customerId)
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
            'first_item' => $items[0] ?? null,
        ]);

        return Inertia::render('App/Notices/Index', [
            'source' => $this->discoverySource(),
            'supportMode' => [
                'active' => false,
                'message' => null,
            ],
            'filters' => $filters,
            'cpvSelector' => $this->cpvSelectorPayload($filters['cpv']),
            'savedSearches' => $this->savedSearchesForCustomer($customerId),
            'notices' => [
                'data' => $items,
                'meta' => $this->livePaginationMeta($request, $page, $perPage, $total, count($items)),
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


    public function storeSavedNotice(Request $request)
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
    ]);

    SavedNotice::query()->updateOrCreate(
        [
            'customer_id' => $customerId,
            'external_id' => $validated['notice_id'],
        ],
        [
            'title' => $validated['title'],
            'buyer_name' => $validated['buyer_name'] ?? null,
            'external_url' => $validated['external_url'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'publication_date' => $validated['publication_date'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'status' => $validated['status'] ?? null,
            'cpv_code' => $validated['cpv_code'] ?? null,
        ],
    );

    return redirect()
        ->back()
        ->with('success', 'Anskaffelsen ble lagret.');
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

    private function livePaginationMeta(Request $request, int $page, int $perPage, int $total, int $count): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $from = $total > 0 ? (($page - 1) * $perPage) + 1 : null;
        $to = $count > 0 && $from !== null ? $from + $count - 1 : null;

        return [
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $from,
            'to' => $to,
            'prev_page_url' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            'next_page_url' => $page < $lastPage ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
        ];
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
            ],
        ];
    }

    private function discoverySource(): array
    {
        return [
            'type' => 'doffin_live_search',
            'label' => 'Live søk i Doffin',
        ];
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

    private function savedSearchesForCustomer(int $customerId): array
    {
        return WatchProfile::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->with([
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
                'frequency' => null,
            ])
            ->all();
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
