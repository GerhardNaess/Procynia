<?php

namespace App\Services\Quality;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityActivityWikiPage;
use Illuminate\Support\Collection;

/**
 * Which Enterprise Wiki pages each activity of one process is behind.
 *
 * ONE ANSWER, THREE READERS. The flow screen, the per-item graph projection and the full-customer
 * graph rebuild all ask this, and a step that showed two pages while the graph drew three would be
 * a bug nobody could see from either side. They share this, not a convention.
 *
 * It is a pure read, with no dependencies at all — deliberately separate from
 * QualityActivityArticleService, which writes and therefore carries the AI client and the whole
 * Wiki document flow with it. A projection job has no business constructing either.
 *
 * WHAT "BEHIND" MEANS. An activity produces a SOURCE: the article someone wrote standing on that
 * step, stored as an ordinary EnterpriseWikiDocument. The pages are whatever the Wiki's own ingest
 * run made of it — normally several, of several types, because that is what the maintainer
 * decision is for. Only pages a run CREATED count: a run that updated or patched a page the Wiki
 * already had did not produce it, and recording the activity as its origin would be a provenance
 * claim nobody made.
 *
 * Rows written before the source direction existed name one hand-made page instead, and resolve to
 * exactly that page. See QualityActivityWikiPage.
 */
class QualityActivityKnowledgeResolver
{
    /**
     * The provenance rows of one process, each with the pages it resolves to and the run whose
     * state explains a row that has none yet.
     *
     * One query per kind for the whole flow — a flow with eighty nodes must not be eighty queries.
     *
     * @return array{
     *     rows: Collection<int, QualityActivityWikiPage>,
     *     pages_by_row: array<int, list<EnterpriseWikiPage>>,
     *     run_by_row: array<int, EnterpriseWikiIngestRun|null>,
     * }
     */
    public function resolve(int $customerId, int $itemId): array
    {
        /** @var Collection<int, QualityActivityWikiPage> $rows */
        $rows = QualityActivityWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $itemId)
            ->with(['document'])
            ->orderBy('id')
            ->get();

        $documentIds = $rows->pluck('enterprise_wiki_document_id')->filter()->map(intval(...))->unique()->values();

        // Every run this source has had, not only the latest: a re-ingest adds pages rather than
        // disowning the ones already there, and all of them came out of this activity.
        $runs = $documentIds->isEmpty()
            ? collect()
            : EnterpriseWikiIngestRun::query()
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->whereIn('source_id', $documentIds)
                ->orderBy('id')
                ->get();

        $documentIdByRunId = [];
        $latestRunByDocument = [];

        foreach ($runs as $run) {
            $documentIdByRunId[(int) $run->id] = (int) $run->source_id;
            $latestRunByDocument[(int) $run->source_id] = $run;
        }

        $pageIdsByDocument = [];

        if ($runs->isNotEmpty()) {
            $runPages = EnterpriseWikiIngestRunPage::query()
                ->whereIn('enterprise_wiki_ingest_run_id', $runs->pluck('id'))
                ->where('action', EnterpriseWikiIngestRunPage::ACTION_CREATED)
                ->orderBy('id')
                ->get(['enterprise_wiki_ingest_run_id', 'enterprise_wiki_page_id']);

            foreach ($runPages as $runPage) {
                $documentId = $documentIdByRunId[(int) $runPage->enterprise_wiki_ingest_run_id] ?? null;

                if ($documentId !== null) {
                    $pageIdsByDocument[$documentId][] = (int) $runPage->enterprise_wiki_page_id;
                }
            }
        }

        $allPageIds = array_merge(
            $rows->pluck('enterprise_wiki_page_id')->filter()->map(intval(...))->all(),
            ...array_values($pageIdsByDocument),
        );

        // Customer-scoped, so a row that somehow named another tenant's page resolves to nothing
        // rather than leaking it. Read access to a Wiki page is not gated on its approval status —
        // status gates actions, never reading — so there is no status filter here, and a page still
        // in draft resolves as a draft rather than being hidden.
        $pages = $allPageIds === []
            ? collect()
            : EnterpriseWikiPage::query()
                ->where('customer_id', $customerId)
                ->whereIn('id', array_values(array_unique($allPageIds)))
                ->with(['currentVersion', 'publishedVersion'])
                ->orderBy('id')
                ->get()
                ->keyBy('id');

        $pagesByRow = [];
        $runByRow = [];

        foreach ($rows as $row) {
            $documentId = $row->enterprise_wiki_document_id !== null ? (int) $row->enterprise_wiki_document_id : null;

            if ($documentId === null) {
                $legacy = $pages->get((int) $row->enterprise_wiki_page_id);
                $pagesByRow[(int) $row->id] = $legacy instanceof EnterpriseWikiPage ? [$legacy] : [];
                $runByRow[(int) $row->id] = null;

                continue;
            }

            $runByRow[(int) $row->id] = $latestRunByDocument[$documentId] ?? null;
            $pagesByRow[(int) $row->id] = array_values(array_filter(array_map(
                static fn (int $pageId): ?EnterpriseWikiPage => $pages->get($pageId),
                array_values(array_unique($pageIdsByDocument[$documentId] ?? [])),
            )));
        }

        return ['rows' => $rows, 'pages_by_row' => $pagesByRow, 'run_by_row' => $runByRow];
    }

    /**
     * The same answer with nothing but identity in it, which is all the graph carries.
     *
     * @return array<string, list<int>>
     */
    public function pageIdsByActivity(int $customerId, int $itemId): array
    {
        $resolved = $this->resolve($customerId, $itemId);
        $pageIds = [];

        foreach ($resolved['rows'] as $row) {
            foreach ($resolved['pages_by_row'][(int) $row->id] ?? [] as $page) {
                $pageIds[(string) $row->activity_key][] = (int) $page->id;
            }
        }

        return array_map(
            static fn (array $ids): array => array_values(array_unique($ids)),
            $pageIds,
        );
    }

    /** The source's title, as the flow shows it while the run is still working. */
    public function sourceTitle(EnterpriseWikiDocument $document): string
    {
        $name = pathinfo((string) $document->original_filename, PATHINFO_FILENAME);

        return $name !== '' ? $name : (string) $document->original_filename;
    }
}
