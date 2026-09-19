<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds a stable graph payload (nodes + edges + summary) from the canonical
 * customer-scoped EnterpriseWikiPageLink graph.
 *
 * Edges — and neighborhood adjacency — are restricted to link_type = wikilink: the
 * only relations derived from actual inline [[wikilinks]] in a page's current
 * content_markdown (see EnterpriseWikiLinkParser/Resolver and
 * EnterpriseWikiBuildPageLinksService::materializeWikilinksForPage()). Historical
 * combinatoric rows (article_to_summary, article_to_concept, etc. — see
 * EnterpriseWikiBuildPageLinksService::build()) still exist in the table but are
 * never surfaced here: they are not derived from page content and would make the
 * graph mix real authored relations with mechanical page-type pairings.
 *
 * Three scopes:
 *   - customer-wide  → no filters
 *   - run-scoped     → pages from an applied maintainer decision run
 *   - neighborhood   → one page and its direct outgoing + incoming neighbors
 *
 * Scope priority: page_id > run_id > customer-wide.
 *
 * Read-only: no writes, no OpenAI.
 */
class EnterpriseWikiGraphDataService
{
    /**
     * @param  list<string>  $visibleStatuses  The same per-viewer status set as
     *                                         WikiController::visibleStatuses()/
     *                                         User::visibleEnterpriseWikiPageStatuses() — the
     *                                         graph must never show a page the ordinary page
     *                                         list would hide from this viewer.
     *
     * @throws \InvalidArgumentException if run_id or page_id is invalid or unscoped
     */
    public function build(int $customerId, array $visibleStatuses, ?int $runId = null, ?int $pageId = null): array
    {
        if ($pageId !== null) {
            return $this->buildNeighborhood($customerId, $pageId, $visibleStatuses);
        }

        if ($runId !== null) {
            return $this->buildRunScoped($customerId, $runId, $visibleStatuses);
        }

        return $this->buildCustomerWide($customerId, $visibleStatuses);
    }

    // =========================================================================
    // Scope builders
    // =========================================================================

    private function buildCustomerWide(int $customerId, array $visibleStatuses): array
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $visibleStatuses)
            ->get();

        $pageIds = $pages->pluck('id')->all();

        // Only edges whose both endpoints survived the status filter above — a link may
        // reference a page this viewer isn't allowed to see (see class docblock).
        $links = empty($pageIds) ? collect() : EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->whereIn('from_page_id', $pageIds)
            ->whereIn('to_page_id', $pageIds)
            ->get();

        return $this->assemble($pages, $links, $customerId, 'customer', null, null);
    }

    private function buildRunScoped(int $customerId, int $runId, array $visibleStatuses): array
    {
        $run = EnterpriseWikiIngestRun::query()
            ->where('id', $runId)
            ->where('customer_id', $customerId)
            ->first();

        if ($run === null) {
            throw new \InvalidArgumentException("Run [{$runId}] not found or does not belong to this customer.");
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$runId}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can be used for graph scoping."
            );
        }

        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $runId)
            ->pluck('enterprise_wiki_page_id')
            ->all();

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $pivotPageIds)
            ->whereIn('status', $visibleStatuses)
            ->get();

        $actualPageIds = $pages->pluck('id')->all();

        // Only edges whose both endpoints are within the run's (visible) page set
        $links = empty($actualPageIds) ? collect() : EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->whereIn('from_page_id', $actualPageIds)
            ->whereIn('to_page_id', $actualPageIds)
            ->get();

        return $this->assemble($pages, $links, $customerId, 'run', $runId, null);
    }

    private function buildNeighborhood(int $customerId, int $pageId, array $visibleStatuses): array
    {
        $centerPage = EnterpriseWikiPage::query()
            ->where('id', $pageId)
            ->where('customer_id', $customerId)
            ->whereIn('status', $visibleStatuses)
            ->first();

        if ($centerPage === null) {
            throw new \InvalidArgumentException("Page [{$pageId}] not found or does not belong to this customer.");
        }

        $outgoing = EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->where('from_page_id', $pageId)
            ->pluck('to_page_id');

        $incoming = EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->where('to_page_id', $pageId)
            ->pluck('from_page_id');

        $pageIdSet = $outgoing->merge($incoming)->push($pageId)->unique()->all();

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $pageIdSet)
            ->whereIn('status', $visibleStatuses)
            ->get();

        $actualPageIds = $pages->pluck('id')->all();

        // All edges between any two pages in the (visible) neighborhood
        $links = EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->whereIn('from_page_id', $actualPageIds)
            ->whereIn('to_page_id', $actualPageIds)
            ->get();

        return $this->assemble($pages, $links, $customerId, 'page', null, $pageId);
    }

    // =========================================================================
    // Assembly
    // =========================================================================

    private function assemble(
        Collection $pages,
        Collection $links,
        int $customerId,
        string $scopeType,
        ?int $scopeRunId,
        ?int $scopePageId,
    ): array {
        $pageIds = $pages->pluck('id')->all();

        // --- Bulk aggregate queries (no N+1) ---

        $claimCounts = empty($pageIds) ? collect() : EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->groupBy('enterprise_wiki_page_id')
            ->selectRaw('enterprise_wiki_page_id, count(*) as cnt')
            ->pluck('cnt', 'enterprise_wiki_page_id');

        $sourceRefCounts = empty($pageIds) ? collect() : EnterpriseWikiSourceReference::query()
            ->join('enterprise_wiki_claims', 'enterprise_wiki_source_references.enterprise_wiki_claim_id', '=', 'enterprise_wiki_claims.id')
            ->whereIn('enterprise_wiki_claims.enterprise_wiki_page_id', $pageIds)
            ->groupBy('enterprise_wiki_claims.enterprise_wiki_page_id')
            ->selectRaw('enterprise_wiki_claims.enterprise_wiki_page_id, count(enterprise_wiki_source_references.id) as cnt')
            ->pluck('cnt', 'enterprise_wiki_page_id');

        $lintRows = empty($pageIds) ? collect() : EnterpriseWikiLintFinding::query()
            ->where('customer_id', $customerId)
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->whereNotNull('enterprise_wiki_page_id')
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->groupBy('enterprise_wiki_page_id', 'severity')
            ->selectRaw('enterprise_wiki_page_id, severity, count(*) as cnt')
            ->get();

        $lintErrorCounts = $lintRows->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)->pluck('cnt', 'enterprise_wiki_page_id');
        $lintWarningCounts = $lintRows->where('severity', EnterpriseWikiLintFinding::SEVERITY_WARNING)->pluck('cnt', 'enterprise_wiki_page_id');

        $currentVersionIds = empty($pageIds) ? collect() : EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id', 'enterprise_wiki_page_id');

        // The grounding for every edge, read once for the whole graph. A link row records THAT page
        // A links to page B; the link_intent behind it records what the source page's own content
        // says the link is for. That has been persisted per block since the intent contract
        // (EnterpriseWikiLinkIntentMaterializer) and was simply never carried into the graph.
        $linkIntentsByPair = $this->linkIntentsForSourcePages(
            $links->pluck('from_page_id')->unique()->values()->all(),
        );

        ['by_page' => $documentIdsByPageId, 'documents' => $documentsPayload, 'owners' => $ownersPayload] =
            $this->documentProvenanceForPages($pageIds, $customerId);

        // --- Nodes ---

        $nodes = $pages->map(function (EnterpriseWikiPage $page) use (
            $claimCounts,
            $sourceRefCounts,
            $lintErrorCounts,
            $lintWarningCounts,
            $currentVersionIds,
            $documentIdsByPageId,
        ): array {
            $errors = (int) ($lintErrorCounts[$page->id] ?? 0);
            $warnings = (int) ($lintWarningCounts[$page->id] ?? 0);

            return [
                'id' => "page-{$page->id}",
                'page_id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'page_type' => $page->page_type,
                'url' => "/app/wiki/{$page->slug}",
                'current_version_id' => $currentVersionIds[$page->id] ?? null,
                'claim_count' => (int) ($claimCounts[$page->id] ?? 0),
                'source_reference_count' => (int) ($sourceRefCounts[$page->id] ?? 0),
                'lint_error_count' => $errors,
                'lint_warning_count' => $warnings,
                'status' => match (true) {
                    $errors > 0 => 'error',
                    $warnings > 0 => 'warning',
                    default => 'ok',
                },
                'document_ids' => $documentIdsByPageId[$page->id] ?? [],
            ];
        })->values()->all();

        // --- Edges ---

        // An edge carries what the domain actually knows about the relation, and nothing more.
        // For link_type=wikilink that is: the source page's text contains [[anchor_text]] resolving
        // to the target page. anchor_text is the author's own wording and is therefore the only
        // honest answer to "why are these two connected" — there is no relation reason, claim or
        // excerpt behind a wikilink to show, and inventing one in the UI would misrepresent the
        // graph. It is null on rows written before the anchor was recorded, and the UI hides the
        // field rather than filling it in.
        $edges = $links->map(function (EnterpriseWikiPageLink $link) use ($linkIntentsByPair): array {
            $anchorText = $link->metadata['anchor_text'] ?? null;
            $anchorText = is_string($anchorText) ? trim($anchorText) : null;

            return [
                'id' => "link-{$link->id}",
                'link_id' => $link->id,
                'source' => "page-{$link->from_page_id}",
                'target' => "page-{$link->to_page_id}",
                'from_page_id' => $link->from_page_id,
                'to_page_id' => $link->to_page_id,
                'link_type' => $link->link_type,
                'confidence' => $link->confidence,
                // How the row was established (deterministic parse vs. maintainer decision) —
                // provenance the reviewer can act on, unlike an internal id.
                'origin' => $link->source,
                'anchor_text' => ($anchorText === null || $anchorText === '') ? null : $anchorText,
                // What the source page's own content says this link is for, and the sentence it
                // sits in. One entry per intent: a page may legitimately link the same target from
                // more than one block, and the link row records only one of them.
                'intents' => $linkIntentsByPair["{$link->from_page_id}:{$link->to_page_id}"] ?? [],
            ];
        })->values()->all();

        // --- Summary ---

        $nodesCol = collect($nodes);
        $edgesCol = collect($edges);

        $connectedPageIds = $edgesCol
            ->flatMap(fn ($e) => [$e['from_page_id'], $e['to_page_id']])
            ->unique();

        $orphanCount = $nodesCol
            ->filter(fn ($n) => ! $connectedPageIds->contains($n['page_id']))
            ->count();

        $summary = [
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'article_count' => $nodesCol->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE)->count(),
            'summary_count' => $nodesCol->where('page_type', EnterpriseWikiPage::PAGE_TYPE_SUMMARY)->count(),
            'concept_count' => $nodesCol->where('page_type', EnterpriseWikiPage::PAGE_TYPE_CONCEPT)->count(),
            'entity_count' => $nodesCol->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ENTITY)->count(),
            'lint_error_count' => (int) $nodesCol->sum('lint_error_count'),
            'lint_warning_count' => (int) $nodesCol->sum('lint_warning_count'),
            'orphan_count' => $orphanCount,
        ];

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'summary' => $summary,
            'documents' => $documentsPayload,
            'owners' => $ownersPayload,
            'scope' => [
                'type' => $scopeType,
                'run_id' => $scopeRunId,
                'page_id' => $scopePageId,
            ],
        ];
    }

    /**
     * Maximum characters of surrounding prose shown as an edge's context. Long enough for the
     * sentence the link sits in, short enough that a graph payload does not turn into page content.
     */
    private const LINK_CONTEXT_MAX_CHARS = 220;

    /**
     * The link intents recorded on each source page's CURRENT version, keyed "fromPageId:toPageId".
     *
     * WHY THIS IS NOT A NEW RELATION MODEL. Every entry here was already written and persisted:
     * WikiPageContentAiClient requires a `reason` on each link intent, the materializer keeps the
     * intent verbatim on the block it belongs to, and EnterpriseWikiPageContentBlockService stores
     * link_intents in content_blocks_json. The graph simply never read it, so a line could say no
     * more than "these two pages are linked". Nothing is inferred, classified or generated here —
     * an intent with no reason yields no reason.
     *
     * One query for the whole graph, and the payload is bounded: only the anchor, the reason and a
     * trimmed excerpt of the block the link sits in leave this method, never the block itself.
     *
     * @param  list<int>  $sourcePageIds
     * @return array<string, list<array{anchor_text: ?string, reason: ?string, context: ?string}>>
     */
    private function linkIntentsForSourcePages(array $sourcePageIds): array
    {
        if ($sourcePageIds === []) {
            return [];
        }

        $byPair = [];

        $versions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $sourcePageIds)
            ->where('is_current', true)
            ->get(['enterprise_wiki_page_id', 'content_blocks_json']);

        foreach ($versions as $version) {
            $fromPageId = (int) $version->enterprise_wiki_page_id;

            foreach ((array) ($version->content_blocks_json ?? []) as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $plain = $this->plainTextForContext((string) ($block['markdown'] ?? ''));

                foreach ((array) ($block['link_intents'] ?? []) as $intent) {
                    if (! is_array($intent) || ! is_int($intent['target_page_id'] ?? null)) {
                        continue;
                    }

                    $anchor = trim((string) ($intent['anchor_text'] ?? ''));
                    $reason = trim((string) ($intent['reason'] ?? ''));

                    $byPair["{$fromPageId}:{$intent['target_page_id']}"][] = [
                        'anchor_text' => $anchor === '' ? null : $anchor,
                        'reason' => $reason === '' ? null : $reason,
                        'context' => $this->linkContextExcerpt($plain, $anchor),
                    ];
                }
            }
        }

        return $byPair;
    }

    /**
     * A block's markdown as plain prose: [[slug|anchor]] becomes the anchor words and headings lose
     * their hashes. Wiki syntax is an implementation detail of how the page is stored and has no
     * business in a tooltip or a side panel.
     */
    private function plainTextForContext(string $markdown): string
    {
        $plain = preg_replace_callback(
            '/\[\[([^\[\]]*)\]\]/u',
            static function (array $match): string {
                $parts = explode('|', $match[1]);

                return trim(end($parts));
            },
            $markdown,
        ) ?? $markdown;

        return trim(preg_replace('/\s+/u', ' ', preg_replace('/^#+\s*/mu', '', $plain)) ?? '');
    }

    /**
     * The prose AROUND the link, not merely the start of the block it happens to live in.
     *
     * A block is often several sentences and the anchor can sit in the last of them, so taking the
     * opening words would show text that has nothing to do with this relation — worse than showing
     * nothing, because it reads as if it explains the link. The window is therefore centred on the
     * anchor's own occurrence, and an ellipsis marks each side that was cut so a partial sentence
     * is never mistaken for the whole one.
     */
    private function linkContextExcerpt(string $plain, string $anchorText): ?string
    {
        if ($plain === '') {
            return null;
        }

        if (mb_strlen($plain) <= self::LINK_CONTEXT_MAX_CHARS) {
            return $plain;
        }

        // An anchor that is not found falls back to the block's opening prose, which is still the
        // most representative sentence available.
        $anchorAt = $anchorText === '' ? false : mb_strpos($plain, $anchorText);
        $start = 0;

        if ($anchorAt !== false) {
            $slack = (int) ((self::LINK_CONTEXT_MAX_CHARS - mb_strlen($anchorText)) / 2);
            $start = max(0, $anchorAt - max(0, $slack));
        }

        $excerpt = mb_substr($plain, $start, self::LINK_CONTEXT_MAX_CHARS);
        $cutAtEnd = $start + self::LINK_CONTEXT_MAX_CHARS < mb_strlen($plain);

        if ($start > 0) {
            // Start at a word boundary so the excerpt never opens mid-word.
            $firstSpace = mb_strpos($excerpt, ' ');
            $excerpt = $firstSpace === false ? $excerpt : mb_substr($excerpt, $firstSpace + 1);
        }

        if ($cutAtEnd) {
            $lastSpace = mb_strrpos($excerpt, ' ');
            $excerpt = $lastSpace === false ? $excerpt : mb_substr($excerpt, 0, $lastSpace);
        }

        $excerpt = trim($excerpt, ' ,;:');

        return ($start > 0 ? '…' : '').$excerpt.($cutAtEnd ? '…' : '');
    }

    /**
     * Purpose: Derive which source document(s) each page is associated with, using the same
     *          provenance model as EnterpriseWikiDocumentDeletionService — most Enterprise Wiki
     *          tables carry no direct document_id column, so a page's document dependency is
     *          derived from enterprise_wiki_ingest_run_pages (the pivot between ingest runs and
     *          the pages those runs touched) joined to each run's own source_type/source_id.
     *          Article and summary pages typically resolve to exactly one document (possibly
     *          reached via several re-ingestion runs of the same document); concept and entity
     *          pages are shared across documents by design and may resolve to zero, one, or many.
     *          Each document also carries its owner_user_id (EnterpriseWikiDocument::owner(),
     *          the same field the customer-frontend document-owner UI already uses) so the
     *          frontend can filter by document owner without a separate lookup — a page has a
     *          given owner in scope whenever at least one of its documents is owned by them
     *          (document and owner filters are independent conditions over the same
     *          document_ids array, not a requirement that one specific document satisfy both).
     * Inputs: The page ids visible in this graph payload, and the customer id (source_id and
     *         owner_user_id carry no enforced customer scoping of their own here, so both the
     *         resolved document and its owner are always re-verified against this customer to
     *         prevent a stale/cross-tenant id from ever surfacing another customer's data).
     * Returns: 'by_page' — page_id => list<document_id> (only pages with at least one resolved
     *          document are present); 'documents' — the deduplicated {id, title, owner_user_id}
     *          list for exactly the documents referenced by at least one page in this payload;
     *          'owners' — the deduplicated {id, name} list for exactly the owners of those
     *          documents (a document with no owner contributes nothing here).
     * Side effects: None (read-only).
     *
     * @param  list<int>  $pageIds
     * @return array{
     *     by_page: array<int, list<int>>,
     *     documents: list<array{id: int, title: string, owner_user_id: ?int}>,
     *     owners: list<array{id: int, name: string}>,
     * }
     */
    private function documentProvenanceForPages(array $pageIds, int $customerId): array
    {
        $empty = ['by_page' => [], 'documents' => [], 'owners' => []];

        if ($pageIds === []) {
            return $empty;
        }

        $runPageRows = EnterpriseWikiIngestRunPage::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->get(['enterprise_wiki_ingest_run_id', 'enterprise_wiki_page_id']);

        if ($runPageRows->isEmpty()) {
            return $empty;
        }

        $runIds = $runPageRows->pluck('enterprise_wiki_ingest_run_id')->unique()->all();

        $documentIdByRunId = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('id', $runIds)
            ->pluck('source_id', 'id');

        if ($documentIdByRunId->isEmpty()) {
            return $empty;
        }

        // Defense in depth: source_id carries no foreign key constraint — re-verify every
        // candidate document id actually belongs to this customer before trusting its filename
        // or owner.
        $documentRowsById = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $documentIdByRunId->unique()->values()->all())
            ->get(['id', 'original_filename', 'owner_user_id'])
            ->keyBy('id');

        $byPage = [];

        foreach ($runPageRows->groupBy('enterprise_wiki_page_id') as $pageId => $rows) {
            $documentIds = $rows
                ->map(fn (EnterpriseWikiIngestRunPage $row): ?int => $documentIdByRunId[$row->enterprise_wiki_ingest_run_id] ?? null)
                ->filter(fn (?int $documentId): bool => $documentId !== null && $documentRowsById->has($documentId))
                ->unique()
                ->values()
                ->all();

            if ($documentIds !== []) {
                $byPage[(int) $pageId] = $documentIds;
            }
        }

        $usedDocumentIds = collect($byPage)->flatten()->unique();

        $usedDocuments = $documentRowsById->filter(
            fn (EnterpriseWikiDocument $document): bool => $usedDocumentIds->contains($document->id)
        );

        // Defense in depth: owner_user_id has a real foreign key to users, but re-verify the
        // owner belongs to this customer before returning their name — a document's owner must
        // always be a member of the same customer in normal operation, but this is the same
        // cross-tenant safety margin already applied to the document lookup above.
        $ownerIds = $usedDocuments->pluck('owner_user_id')->filter()->unique()->values()->all();

        $ownerNameById = $ownerIds === [] ? collect() : User::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $ownerIds)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                $user->id => trim((string) $user->name) !== '' ? $user->name : $user->email,
            ]);

        $documents = $usedDocuments
            ->map(function (EnterpriseWikiDocument $document) use ($ownerNameById): array {
                $ownerId = $document->owner_user_id;

                return [
                    'id' => $document->id,
                    'title' => $document->original_filename,
                    'owner_user_id' => $ownerId !== null && $ownerNameById->has($ownerId) ? $ownerId : null,
                ];
            })
            ->values()
            ->all();

        $owners = $ownerNameById
            ->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
            ->values()
            ->all();

        return ['by_page' => $byPage, 'documents' => $documents, 'owners' => $owners];
    }
}
