<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityPageClassification;
use App\Models\QualityRelation;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Quality\QualityStructureService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kvalitet — the faglig view of the Wiki.
 *
 * The module owns no content. Every document it lists is a Wiki page, with Wiki's owner, status,
 * review and version history; Kvalitet adds what kind of styrende dokument each one is and how they
 * govern each other, and then presents them the way a kvalitetsleder needs to read them rather than
 * the way the Wiki pipeline produced them.
 *
 * Entitlement is not checked here. Every route in this controller is named under `app.quality.`,
 * and config/procynia_modules.php maps that prefix to the `quality` module, so EnsureModuleIsEnabled
 * has already refused the request if the customer has not bought it — including the write actions.
 */
class QualityController extends Controller
{
    private const TABS = ['overview', 'processes', 'controls', 'checklists'];

    /**
     * Which quality types each tab shows. Oversikt deliberately shows all of them: it is the whole
     * document hierarchy in one place, and it is the only tab policies, procedures and
     * arbeidsinstrukser appear on.
     *
     * @var array<string, list<string>>
     */
    private const TAB_TYPES = [
        'overview' => QualityPageClassification::TYPES,
        'processes' => [QualityPageClassification::TYPE_PROCESS],
        'controls' => [QualityPageClassification::TYPE_CONTROL],
        'checklists' => [QualityPageClassification::TYPE_CHECKLIST],
    ];

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly QualityStructureService $structure,
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';

        $documents = $this->documentRows($customerId, $user, self::TAB_TYPES[$tab]);

        $props = [
            'active_tab' => $tab,
            // Classifying a page and drawing a relation are statements about the kvalitetssystem,
            // so they use the same authority that already vouches for Wiki content — System Owner,
            // or a role the customer has given Wiki claim approval to. No new permission was added.
            'can_manage' => $user?->canApproveWikiClaims() ?? false,
            'documents' => $documents,
            'type_counts' => $this->typeCounts($customerId),
            'quality_types' => QualityPageClassification::TYPES,
            'relation_types' => $this->relationTypeMatrix(),
        ];

        if ($tab === 'overview') {
            $props += [
                'relations' => $this->relationRows($customerId),
                'unclassified_pages' => $this->unclassifiedPages($customerId, $user, $request),
                'unclassified_search' => trim((string) $request->query('search', '')),
                // Only classified pages can be an end of a relation, so the form offers exactly
                // those — with their type, so the UI can narrow each select to the matrix.
                'relation_page_options' => $documents->map(static fn (array $row): array => [
                    'page_id' => $row['page_id'],
                    'title' => $row['title'],
                    'quality_type' => $row['quality_type'],
                    'quality_code' => $row['quality_code'],
                ])->values()->all(),
            ];
        }

        return Inertia::render('App/Quality/Index', $props);
    }

    public function storeClassification(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);

        $validated = $request->validate([
            'page_id' => ['required', 'integer'],
            'quality_type' => ['required', 'string'],
            'quality_code' => ['nullable', 'string', 'max:50'],
        ]);

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->findOrFail($validated['page_id']);

        $this->structure->classify(
            $customerId,
            $page,
            $validated['quality_type'],
            $validated['quality_code'] ?? null,
            $user,
        );

        return back()->with('success', __('procynia.quality.flash.classified'));
    }

    public function destroyClassification(QualityPageClassification $classification): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);
        $this->assertOwnedByCustomer((int) $classification->customer_id, $customerId);

        $this->structure->unclassify($customerId, $classification);

        return back()->with('success', __('procynia.quality.flash.unclassified'));
    }

    public function storeRelation(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);

        $validated = $request->validate([
            'from_page_id' => ['required', 'integer'],
            'to_page_id' => ['required', 'integer'],
            'relation_type' => ['required', 'string'],
        ]);

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', [$validated['from_page_id'], $validated['to_page_id']])
            ->get()
            ->keyBy('id');

        $fromPage = $pages->get($validated['from_page_id']);
        $toPage = $pages->get($validated['to_page_id']);

        abort_if($fromPage === null || $toPage === null, 404);

        $this->structure->relate($customerId, $fromPage, $toPage, $validated['relation_type'], $user);

        return back()->with('success', __('procynia.quality.flash.related'));
    }

    public function destroyRelation(QualityRelation $relation): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeManagement($user);
        $this->assertOwnedByCustomer((int) $relation->customer_id, $customerId);

        $this->structure->unrelate($customerId, $relation);

        return back()->with('success', __('procynia.quality.flash.unrelated'));
    }

    /**
     * One row per classified page: the quality facts, plus the Wiki facts read straight off the
     * page rather than copied into the quality tables.
     *
     * @param  list<string>  $types
     * @return Collection<int, array<string, mixed>>
     */
    private function documentRows(?int $customerId, ?User $user, array $types): Collection
    {
        $classifications = QualityPageClassification::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_type', $types)
            ->whereHas('page', fn ($query) => $query->whereIn('status', $this->visibleStatuses($user)))
            ->with([
                'page.owner',
                'page.currentVersion',
                'page.publishedVersion',
            ])
            ->get();

        $relationsByPage = $this->relationsByPage(
            $customerId,
            $classifications->pluck('enterprise_wiki_page_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $typeOrder = array_flip(QualityPageClassification::TYPES);

        return $classifications
            ->map(function (QualityPageClassification $classification) use ($relationsByPage): array {
                $page = $classification->page;
                $pageId = (int) $classification->enterprise_wiki_page_id;

                return [
                    'id' => $classification->id,
                    'page_id' => $pageId,
                    'quality_type' => $classification->quality_type,
                    'quality_code' => $classification->quality_code,
                    'title' => $page?->title,
                    'slug' => $page?->slug,
                    // The row's whole purpose is to lead back to the Wiki page that holds the
                    // content; Kvalitet never becomes a second place to read it.
                    'wiki_url' => $page !== null ? route('app.wiki.show', ['slug' => $page->slug]) : null,
                    'page_type' => $page?->page_type,
                    'status' => $page?->status,
                    'owner_name' => $page?->owner?->name,
                    // The same presenter the Wiki list and the Wiki page use, so a document cannot
                    // read as approved in Kvalitet and in review in Wiki.
                    'publication' => $page !== null
                        ? $this->publicationStatus->forPage($page, $page->currentVersion)
                        : null,
                    'relations' => $relationsByPage[$pageId] ?? [],
                    'updated_at' => $page?->updated_at,
                ];
            })
            // Governing documents first, then alphabetically within each type — the order a
            // kvalitetshåndbok is read in, not the order rows happened to be created in.
            ->sortBy(static fn (array $row): string => sprintf(
                '%02d|%s',
                $typeOrder[$row['quality_type']] ?? 99,
                mb_strtolower((string) $row['title']),
            ))
            ->values();
    }

    /**
     * Both directions of every relation that touches these pages, keyed by page id.
     *
     * Stored rows are one-directional — see QualityRelation — so the incoming side is produced here
     * for presentation only. A row knows which end it is looking from, because "styres av" and
     * "styrer" are not the same statement.
     *
     * @param  list<int>  $pageIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function relationsByPage(?int $customerId, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $relations = QualityRelation::query()
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->whereIn('from_page_id', $pageIds)->orWhereIn('to_page_id', $pageIds))
            ->with(['fromPage:id,title,slug', 'toPage:id,title,slug'])
            ->orderBy('id')
            ->get();

        $byPage = [];

        foreach ($relations as $relation) {
            $fromId = (int) $relation->from_page_id;
            $toId = (int) $relation->to_page_id;

            $byPage[$fromId][] = [
                'id' => $relation->id,
                'relation_type' => $relation->relation_type,
                'direction' => 'outgoing',
                'other_page_id' => $toId,
                'other_page_title' => $relation->toPage?->title,
                'other_page_slug' => $relation->toPage?->slug,
            ];

            $byPage[$toId][] = [
                'id' => $relation->id,
                'relation_type' => $relation->relation_type,
                'direction' => 'incoming',
                'other_page_id' => $fromId,
                'other_page_title' => $relation->fromPage?->title,
                'other_page_slug' => $relation->fromPage?->slug,
            ];
        }

        return $byPage;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationRows(?int $customerId): array
    {
        return QualityRelation::query()
            ->where('customer_id', $customerId)
            ->with(['fromPage:id,title,slug', 'toPage:id,title,slug'])
            ->orderBy('relation_type')
            ->orderBy('id')
            ->get()
            ->map(static fn (QualityRelation $relation): array => [
                'id' => $relation->id,
                'relation_type' => $relation->relation_type,
                'from_page_id' => (int) $relation->from_page_id,
                'from_title' => $relation->fromPage?->title,
                'to_page_id' => (int) $relation->to_page_id,
                'to_title' => $relation->toPage?->title,
            ])
            ->values()
            ->all();
    }

    /**
     * Wiki pages that are not part of the kvalitetssystem yet.
     *
     * Capped and searchable rather than complete: a mature Wiki has thousands of pages and almost
     * none of them are styrende dokumenter, so this is a picker, not a list to work through.
     *
     * @return list<array<string, mixed>>
     */
    private function unclassifiedPages(?int $customerId, ?User $user, Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        $query = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $this->visibleStatuses($user))
            ->whereDoesntHave('qualityClassification');

        if ($search !== '') {
            $searchLower = strtolower($search);
            $query->where(fn ($sub) => $sub
                ->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$searchLower}%"])
            );
        }

        return $query
            ->orderBy('title')
            ->limit(50)
            ->get(['id', 'title', 'slug', 'page_type', 'status'])
            ->map(static fn (EnterpriseWikiPage $page): array => [
                'page_id' => (int) $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'page_type' => $page->page_type,
                'status' => $page->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function typeCounts(?int $customerId): array
    {
        $counts = QualityPageClassification::query()
            ->where('customer_id', $customerId)
            ->selectRaw('quality_type, COUNT(*) AS total')
            ->groupBy('quality_type')
            ->pluck('total', 'quality_type');

        $result = [];

        foreach (QualityPageClassification::TYPES as $type) {
            $result[$type] = (int) ($counts[$type] ?? 0);
        }

        return $result;
    }

    /**
     * The relation matrix, shipped to the client so the relation form can offer only legal pairs.
     * The backend still enforces it — this is what keeps the form from proposing work the service
     * will refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function relationTypeMatrix(): array
    {
        return array_map(static fn (string $type): array => [
            'key' => $type,
            'from_types' => QualityRelation::allowedFromTypes($type),
            'to_types' => QualityRelation::allowedToTypes($type),
        ], QualityRelation::TYPES);
    }

    /**
     * @return list<string>
     */
    private function visibleStatuses(?User $user): array
    {
        return $user?->visibleEnterpriseWikiPageStatuses() ?? [];
    }

    private function authorizeManagement(?User $user): void
    {
        abort_unless($user?->canApproveWikiClaims() ?? false, 403);
    }

    private function assertOwnedByCustomer(int $rowCustomerId, ?int $customerId): void
    {
        abort_unless($customerId !== null && $rowCustomerId === $customerId, 404);
    }
}
