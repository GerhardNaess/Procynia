<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\DB;

/**
 * Who carries overall responsibility for a Wiki page.
 *
 * This is a different question from document-owner approval. That one asks "is the content from my
 * document correctly represented in this version?" and is answered per version, by every contributing
 * document's owner. This one asks "who owns this page as a whole?" and has exactly one answer, fixed
 * when the page is created.
 *
 * The page owner is inherited from the owner of the ORIGINAL source document — the one whose run
 * created the page. Later documents enriching the same page do not change it, and neither does a
 * later handover of that original document: once the page exists, its ownership is a standing
 * responsibility, not a mirror of the document's current owner. See
 * docs/enterprise-wiki-approval-model.md §3.1.
 *
 * Only `enterprise_wiki_document` sources can confer ownership today. A run sourced from a
 * `knowledge_item_version` leaves the page unowned rather than reaching for a substitute — see
 * ownerUserIdForRun().
 */
class EnterpriseWikiPageOwnerService
{
    /**
     * The user who should own a page created by this run, or null when the source cannot name one.
     *
     * Null is a real answer here, not a failure. There is deliberately no fallback to the acting
     * user, a System Owner or any customer admin: an owner that nobody actually accepted would make
     * the field say something untrue, which is worse than saying nothing.
     */
    public function ownerUserIdForRun(?EnterpriseWikiIngestRun $run): ?int
    {
        if ($run === null || $run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return null;
        }

        $document = EnterpriseWikiDocument::query()
            ->where('id', $run->source_id)
            ->where('customer_id', $run->customer_id)
            ->first();

        return $document?->owner_user_id !== null ? (int) $document->owner_user_id : null;
    }

    /**
     * Give an owner to pages that never got one, from the document that originally created them.
     *
     * Idempotent, and narrow on purpose: only pages whose ownership follows unambiguously from the
     * data are touched. A page with no `created` pivot row, more than one, a missing run, a deleted
     * document, an unowned document or a non-document source is left alone and counted as skipped —
     * guessing an owner would put a name against work that person never agreed to carry.
     *
     * @return array{assigned: int, skipped: int, skipped_page_ids: list<int>}
     */
    public function backfillMissingOwners(?int $customerId = null): array
    {
        $pages = EnterpriseWikiPage::query()
            ->whereNull('owner_user_id')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->get(['id', 'customer_id']);

        $assigned = 0;
        $skipped = [];

        foreach ($pages as $page) {
            $ownerUserId = $this->originalOwnerUserIdForPage($page);

            if ($ownerUserId === null) {
                $skipped[] = (int) $page->id;

                continue;
            }

            // Guarded on owner_user_id again so a concurrent write is never overwritten.
            $updated = EnterpriseWikiPage::query()
                ->where('id', $page->id)
                ->whereNull('owner_user_id')
                ->update(['owner_user_id' => $ownerUserId]);

            $updated > 0 ? $assigned++ : $skipped[] = (int) $page->id;
        }

        return [
            'assigned' => $assigned,
            'skipped' => count($skipped),
            'skipped_page_ids' => $skipped,
        ];
    }

    /**
     * The owner of the document whose run created this page, or null when that is not unambiguous.
     *
     * Only `created` pivot rows are consulted. An `updated` row means a later run attached content
     * to a page that already existed — that run's document is a contributor, not the origin, and
     * using it would hand the page to whoever happened to enrich it last.
     */
    private function originalOwnerUserIdForPage(EnterpriseWikiPage $page): ?int
    {
        $runIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('action', EnterpriseWikiIngestRunPage::ACTION_CREATED)
            ->pluck('enterprise_wiki_ingest_run_id');

        if ($runIds->count() !== 1) {
            return null;
        }

        $run = EnterpriseWikiIngestRun::query()->find($runIds->first());

        if ($run === null || (int) $run->customer_id !== (int) $page->customer_id) {
            return null;
        }

        return $this->ownerUserIdForRun($run);
    }

    /**
     * How the pages that still have no owner break down — the counts the backfill decision rests on.
     *
     * @return array<string, int>
     */
    public function unownedPageDiagnostics(?int $customerId = null): array
    {
        $rows = DB::table('enterprise_wiki_pages as p')
            ->leftJoin('enterprise_wiki_ingest_run_pages as rp', function ($join): void {
                $join->on('rp.enterprise_wiki_page_id', '=', 'p.id')
                    ->where('rp.action', '=', EnterpriseWikiIngestRunPage::ACTION_CREATED);
            })
            ->leftJoin('enterprise_wiki_ingest_runs as r', 'r.id', '=', 'rp.enterprise_wiki_ingest_run_id')
            ->whereNull('p.owner_user_id')
            ->when($customerId !== null, fn ($query) => $query->where('p.customer_id', $customerId))
            ->groupBy('p.id')
            ->selectRaw('p.id, count(rp.id) as created_rows, count(r.id) as runs, min(r.source_type) as source_type, min(r.source_id) as source_id')
            ->get();

        $counts = [
            'resolvable' => 0,
            'no_created_row' => 0,
            'multiple_created_rows' => 0,
            'missing_run' => 0,
            'missing_document' => 0,
            'document_without_owner' => 0,
            'non_document_source' => 0,
        ];

        foreach ($rows as $row) {
            $bucket = match (true) {
                (int) $row->created_rows === 0 => 'no_created_row',
                (int) $row->created_rows > 1 => 'multiple_created_rows',
                (int) $row->runs === 0 => 'missing_run',
                $row->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT => 'non_document_source',
                default => $this->documentBucket((int) $row->source_id),
            };

            $counts[$bucket]++;
        }

        return $counts;
    }

    private function documentBucket(int $documentId): string
    {
        $document = EnterpriseWikiDocument::query()->find($documentId);

        return match (true) {
            $document === null => 'missing_document',
            $document->owner_user_id === null => 'document_without_owner',
            default => 'resolvable',
        };
    }
}
