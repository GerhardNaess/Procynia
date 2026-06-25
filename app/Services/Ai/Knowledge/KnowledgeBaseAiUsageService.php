<?php

namespace App\Services\Ai\Knowledge;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KnowledgeBaseAiUsageService
{
    private const COUNTED_STATUSES = [
        'suggested',
        'selected',
    ];

    /**
     * Returns a per-document aggregate of knowledge items sent to AI as grounding sources.
     *
     * Only includes evidence rows with selection_status 'suggested' or 'selected'.
     * Double-scoped: both knowledge_items.customer_id and saved_notices.customer_id
     * must equal $customerId to prevent cross-customer leakage.
     *
     * Supported filter keys:
     *   version_status (string) — 'current' or 'superseded'; omit to include all.
     *     Rows with null knowledge_item_version_id are excluded when this filter is set.
     *   primary_only (bool) — when true, only count is_primary evidence rows.
     *   date_from (string)  — lower bound on sae.updated_at (inclusive).
     *   date_to (string)    — upper bound on sae.updated_at (inclusive).
     *
     * Returned rows contain (at minimum):
     *   knowledge_item_id, title, original_filename, document_type, document_status,
     *   current_version_no, current_version_approval_status,
     *   case_count, requirement_count, evidence_count, primary_count,
     *   avg_match_score, last_used_at, evidence_on_superseded_version_count
     */
    public function documentAggregate(int $customerId, array $filters = []): Collection
    {
        // Subquery for the current version of each knowledge item (at most one row per item).
        $currentVersionSubquery = DB::table('knowledge_item_versions')
            ->where('is_current', true)
            ->select(['knowledge_item_id', 'version_no', 'approval_status', 'original_filename']);

        $query = DB::table('saved_notice_ai_evidence as sae')
            ->join('knowledge_items as ki', 'ki.id', '=', 'sae.knowledge_item_id')
            ->leftJoin('knowledge_item_versions as kiv_used', 'kiv_used.id', '=', 'sae.knowledge_item_version_id')
            ->leftJoinSub($currentVersionSubquery, 'kiv_current', 'kiv_current.knowledge_item_id', '=', 'ki.id')
            ->join('saved_notice_ai_requirements as snair', 'snair.id', '=', 'sae.saved_notice_ai_requirement_id')
            ->join('saved_notices as sn', 'sn.id', '=', 'snair.saved_notice_id')
            ->where('ki.customer_id', '=', $customerId)
            ->where('sn.customer_id', '=', $customerId)
            ->whereIn('sae.selection_status', self::COUNTED_STATUSES);

        $this->applyFilters($query, $filters, 'kiv_used');

        $query
            ->select([
                'ki.id as knowledge_item_id',
                'ki.title',
                'kiv_current.original_filename as original_filename',
                'ki.document_type',
                'ki.document_status',
                'kiv_current.version_no as current_version_no',
                'kiv_current.approval_status as current_version_approval_status',
                DB::raw('COUNT(DISTINCT sn.id) as case_count'),
                DB::raw('COUNT(DISTINCT snair.id) as requirement_count'),
                DB::raw('COUNT(sae.id) as evidence_count'),
                DB::raw('SUM(CASE WHEN sae.is_primary THEN 1 ELSE 0 END) as primary_count'),
                DB::raw('ROUND(AVG(sae.match_score)) as avg_match_score'),
                DB::raw('MAX(sae.updated_at) as last_used_at'),
                // Count evidence rows where the version at time of use is no longer current.
                // Null version_id rows do not contribute to this count.
                DB::raw('SUM(CASE WHEN kiv_used.is_current IS FALSE THEN 1 ELSE 0 END) as evidence_on_superseded_version_count'),
            ])
            ->groupBy([
                'ki.id',
                'ki.title',
                'kiv_current.original_filename',
                'ki.document_type',
                'ki.document_status',
                'kiv_current.version_no',
                'kiv_current.approval_status',
            ])
            ->orderByRaw('MAX(sae.updated_at) DESC');

        return $query->get();
    }

    /**
     * Returns a per-chunk aggregate of knowledge item chunks sent to AI as grounding sources.
     *
     * Only includes evidence rows with selection_status 'suggested' or 'selected'.
     * Double-scoped: both knowledge_items.customer_id and saved_notices.customer_id
     * must equal $customerId to prevent cross-customer leakage.
     *
     * Version info (version_no_used, version_is_current, version_approval_status)
     * reflects the version referenced by the most recent evidence row for each chunk,
     * resolved via a DISTINCT ON query. Fields are null when no version is referenced
     * (older evidence rows created before version tracking was active).
     *
     * Supported filter keys: same as documentAggregate().
     *
     * Returned rows contain (at minimum):
     *   knowledge_item_chunk_id, knowledge_item_id, original_filename,
     *   chunk_index, chunk_type, section_title, heading_path, topic, sub_topic,
     *   version_no_used, version_is_current, version_approval_status,
     *   case_count, requirement_count, evidence_count, primary_count,
     *   avg_match_score, max_match_score, last_used_at
     */
    public function chunkAggregate(int $customerId, array $filters = []): Collection
    {
        $currentVersionSubquery = DB::table('knowledge_item_versions')
            ->where('is_current', true)
            ->select(['knowledge_item_id', 'original_filename']);

        $query = DB::table('saved_notice_ai_evidence as sae')
            ->join('knowledge_item_chunks as kic', 'kic.id', '=', 'sae.knowledge_item_chunk_id')
            ->join('knowledge_items as ki', 'ki.id', '=', 'kic.knowledge_item_id')
            ->leftJoin('knowledge_item_versions as kiv_used', 'kiv_used.id', '=', 'sae.knowledge_item_version_id')
            ->leftJoinSub($currentVersionSubquery, 'kiv_current', 'kiv_current.knowledge_item_id', '=', 'ki.id')
            ->join('saved_notice_ai_requirements as snair', 'snair.id', '=', 'sae.saved_notice_ai_requirement_id')
            ->join('saved_notices as sn', 'sn.id', '=', 'snair.saved_notice_id')
            ->where('ki.customer_id', '=', $customerId)
            ->where('sn.customer_id', '=', $customerId)
            ->whereIn('sae.selection_status', self::COUNTED_STATUSES);

        $this->applyFilters($query, $filters, 'kiv_used');

        $query
            ->select([
                'kic.id as knowledge_item_chunk_id',
                'ki.id as knowledge_item_id',
                'kiv_current.original_filename as original_filename',
                'kic.chunk_index',
                'kic.chunk_type',
                'kic.section_title',
                'kic.heading_path',
                'kic.topic',
                'kic.sub_topic',
                DB::raw('COUNT(DISTINCT sn.id) as case_count'),
                DB::raw('COUNT(DISTINCT snair.id) as requirement_count'),
                DB::raw('COUNT(sae.id) as evidence_count'),
                DB::raw('SUM(CASE WHEN sae.is_primary THEN 1 ELSE 0 END) as primary_count'),
                DB::raw('ROUND(AVG(sae.match_score)) as avg_match_score'),
                DB::raw('MAX(sae.match_score) as max_match_score'),
                DB::raw('MAX(sae.updated_at) as last_used_at'),
            ])
            ->groupBy([
                'kic.id',
                'ki.id',
                'kiv_current.original_filename',
                'kic.chunk_index',
                'kic.chunk_type',
                'kic.section_title',
                'kic.heading_path',
                'kic.topic',
                'kic.sub_topic',
            ])
            ->orderByRaw('COUNT(sae.id) DESC, MAX(sae.updated_at) DESC');

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $chunkIds = $rows->pluck('knowledge_item_chunk_id')->map(fn ($id) => (int) $id)->toArray();
        $versionInfoMap = $this->latestVersionInfoByChunkId($chunkIds);

        return $rows->map(function (object $row) use ($versionInfoMap): object {
            $info = $versionInfoMap[(int) $row->knowledge_item_chunk_id] ?? null;
            $row->version_no_used = $info?->version_no_used ?? null;
            $row->version_is_current = $info?->version_is_current ?? null;
            $row->version_approval_status = $info?->version_approval_status ?? null;

            return $row;
        });
    }

    /**
     * Fetches version display details for each chunk from its most recently used evidence row.
     *
     * Uses DISTINCT ON (PostgreSQL) ordered by sae.updated_at DESC so the latest row wins.
     * Returns a map keyed by knowledge_item_chunk_id (int).
     */
    private function latestVersionInfoByChunkId(array $chunkIds): array
    {
        if (empty($chunkIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($chunkIds), '?'));

        $rows = DB::select(
            "SELECT DISTINCT ON (sae.knowledge_item_chunk_id)
                sae.knowledge_item_chunk_id,
                kiv.version_no       AS version_no_used,
                kiv.is_current       AS version_is_current,
                kiv.approval_status  AS version_approval_status
            FROM saved_notice_ai_evidence sae
            LEFT JOIN knowledge_item_versions kiv ON kiv.id = sae.knowledge_item_version_id
            WHERE sae.knowledge_item_chunk_id IN ({$placeholders})
              AND sae.selection_status IN ('suggested', 'selected')
            ORDER BY sae.knowledge_item_chunk_id, sae.updated_at DESC",
            $chunkIds,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->knowledge_item_chunk_id] = $row;
        }

        return $map;
    }

    /**
     * Applies filter criteria to the query builder.
     *
     * $versionAlias must be the alias for the knowledge_item_versions join that represents
     * the version used in the evidence row (not the knowledge item's current version).
     */
    private function applyFilters(Builder $query, array $filters, string $versionAlias): void
    {
        $versionStatus = $filters['version_status'] ?? null;

        if ($versionStatus === 'current') {
            $query->where("{$versionAlias}.is_current", '=', true);
        } elseif ($versionStatus === 'superseded') {
            $query->where("{$versionAlias}.is_current", '=', false);
        }

        if (! empty($filters['primary_only'])) {
            $query->where('sae.is_primary', '=', true);
        }

        if (! empty($filters['date_from'])) {
            $query->where('sae.updated_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('sae.updated_at', '<=', $filters['date_to']);
        }
    }
}
