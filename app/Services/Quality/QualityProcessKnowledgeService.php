<?php

namespace App\Services\Quality;

use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;

/**
 * The knowledge behind one activity.
 *
 * WHY A REFERENCE AND NOT CONTENT.
 *
 * An activity on a prosessflyt — "Kontroller leverandøren" — is carried out against something the
 * virksomhet knows, and that knowledge is already written down: it is an Enterprise Wiki page, with
 * its own ownership, its own approval and its own history. Copying any of it onto the node would
 * make a second copy that is wrong the first time the page is edited. So the node holds nothing but
 * the page's id, and every view resolves it fresh. Edit the Wiki page and the activity points at the
 * edited page, because there was never anything to keep in step.
 *
 * This is the same shape QualityProcessSubprocessService gives a step that stands for another
 * process, for the same reason, and it is why nothing moved out of the payload: the node points
 * out, by the id of a row that already exists and is already customer-scoped. One list of integers
 * on the node, `knowledge_page_ids`.
 *
 * WIKI/SQL IS THE SOURCE OF TRUTH. The graph records the relation
 * `(:QualityActivity)-[:REQUIRES_KNOWLEDGE]->(:EnterpriseWikiPage)` and nothing else — see
 * QualityGraphProjector. Nothing is ever read back out of the graph to decide what an activity
 * knows.
 *
 * WHAT THIS CLASS IS FOR.
 *
 *  - A reference resolves only inside the customer that owns the flow. Another tenant's page id is
 *    dropped, not refused: dropping is the answer the blueprint service gives every unresolvable
 *    row, and refusing the save would trap the user in the one edit they cannot make.
 *  - A page that has been deleted is dropped the same way, so a flow never breaks because the
 *    knowledge behind one of its activities was removed from Wiki.
 *  - Read access to a Wiki page is not gated on its approval status — a draft page is visible to
 *    every authorised Wiki user of the customer, and status only gates actions. So the only filter
 *    here is the tenant, and a linked page that is still a draft is shown as a draft rather than
 *    hidden.
 */
class QualityProcessKnowledgeService
{
    /**
     * How many Wiki pages one activity may draw on.
     *
     * Not a modelling limit — an activity may legitimately rest on several pages — but a ceiling on
     * what one node can say before the indicator on the diagram stops meaning anything, and on what
     * a single payload can carry.
     */
    public const MAX_PER_ACTIVITY = 20;

    /** How many pages the picker offers at a time. Searchable rather than complete; see options(). */
    private const OPTION_LIMIT = 50;

    public function __construct(
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
    ) {}

    /**
     * Which of the page ids a whole flow asks for actually belong to this customer.
     *
     * All of them in one query rather than one per node: every node asks the same question of the
     * same table, and a flow with eighty nodes would otherwise be eighty queries on every save.
     * Read here and nowhere else in the call, so it cannot be stale.
     *
     * @param  list<mixed>  $rawRows  the raw node rows, as submitted
     * @return array<int, true> the page ids that resolve, as a set
     */
    public function resolveAll(int $customerId, array $rawRows): array
    {
        $asked = [];

        foreach ($rawRows as $row) {
            foreach ($this->rawIds(is_array($row) ? ($row['knowledge_page_ids'] ?? null) : null) as $id) {
                $asked[$id] = true;
            }
        }

        if ($asked === []) {
            return [];
        }

        $existing = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', array_keys($asked))
            ->pluck('id');

        $resolved = [];

        foreach ($existing as $id) {
            $resolved[(int) $id] = true;
        }

        return $resolved;
    }

    /**
     * One node's references, as they will be stored.
     *
     * Order is the order they were given — the user controls it by adding and removing — and
     * duplicates are collapsed, because the same page twice behind one activity says nothing twice.
     *
     * @param  array<int, true>  $resolved  the set resolveAll() returned
     * @return list<int>
     */
    public function filter(mixed $raw, array $resolved): array
    {
        $ids = [];

        foreach ($this->rawIds($raw) as $id) {
            if (! isset($resolved[$id]) || isset($ids[$id])) {
                continue;
            }

            $ids[$id] = true;

            if (count($ids) >= self::MAX_PER_ACTIVITY) {
                break;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * The Wiki pages a flow points at, as the page needs to show them.
     *
     * Read fresh on every page load, which is the whole point of holding a reference: the activity
     * shows what the page is called and how far it has got through publication *now*. A reference
     * that no longer resolves is simply absent from the map, so the view drops it.
     *
     * @param  list<mixed>  $rawRows  the stored node rows
     * @return array<int, array<string, mixed>> page id to descriptor
     */
    public function describeAll(int $customerId, array $rawRows): array
    {
        $asked = [];

        foreach ($rawRows as $row) {
            foreach ($this->rawIds(is_array($row) ? ($row['knowledge_page_ids'] ?? null) : null) as $id) {
                $asked[$id] = true;
            }
        }

        if ($asked === []) {
            return [];
        }

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', array_keys($asked))
            ->with(['currentVersion', 'publishedVersion'])
            ->get();

        $described = [];

        foreach ($pages as $page) {
            $described[(int) $page->id] = $this->describePage($page);
        }

        return $described;
    }

    /**
     * The Wiki pages an activity can be pointed at.
     *
     * Capped and searchable rather than complete, exactly like the item-level picker in
     * QualityController: a mature Wiki has thousands of pages, and there is nothing to work through
     * here — this is a picker, not a list to be read.
     *
     * @return list<array<string, mixed>>
     */
    public function options(int $customerId, string $search = ''): array
    {
        $query = EnterpriseWikiPage::query()->where('customer_id', $customerId);

        $search = trim($search);

        if ($search !== '') {
            $needle = mb_strtolower($search);

            $query->where(fn ($sub) => $sub
                ->whereRaw('LOWER(title) LIKE ?', ["%{$needle}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$needle}%"])
            );
        }

        return $query
            ->orderBy('title')
            ->limit(self::OPTION_LIMIT)
            ->get(['id', 'title', 'slug', 'page_type', 'status'])
            ->map(static fn (EnterpriseWikiPage $page): array => [
                'page_id' => (int) $page->id,
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'page_type' => $page->page_type,
                'status' => $page->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function describePage(EnterpriseWikiPage $page): array
    {
        return [
            'page_id' => (int) $page->id,
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'page_type' => $page->page_type,
            'status' => $page->status,
            'url' => route('app.wiki.show', ['slug' => $page->slug]),
            // The same presenter the Wiki list, the Wiki page and the item-level links use, so one
            // page cannot read as approved in Kvalitet and in review in Wiki.
            'publication' => $this->publicationStatus->forPage($page, $page->currentVersion),
        ];
    }

    /**
     * The ids on one node, as written, before anything has been checked.
     *
     * @return list<int>
     */
    private function rawIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = is_numeric($value) ? (int) $value : 0;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
