<?php

namespace App\Services\Risk;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Risk;
use App\Models\RiskWikiSource;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Str;

/**
 * Risiko → «Lag kunnskapsartikkel» → Enterprise Wiki.
 *
 * THE SAME HANDOFF KVALITET USES. What the user writes is stored as an ordinary Wiki source
 * (EnterpriseWikiDocumentUploadService::storeAuthoredText) and handed to the ordinary document flow
 * (EnterpriseWikiDocumentFlowService::startForDocument) — exactly what QualityActivityArticleService
 * does for a process activity, and what Kildedokumenter does for an upload. From there the Wiki
 * plans, generates, verifies and links the pages, and its own review and publication rules decide
 * when anything is published. Risiko owns no part of that pipeline.
 *
 * NOTHING IS COPIED FROM THE RISK. The title and text are what the person typed, and only that.
 * Risk title, årsak, hendelse, konsekvens, utfyllende informasjon, vurderinger, score, tiltak and
 * aksept never reach this service — a risk is sensitive, and what is reusable about it has to be
 * formulated by a person who decided it may be shared. No AI here either.
 *
 * ONE DIRECTION. The risk keeps a RiskWikiSource row (ids only). The Wiki never reads it, so no
 * Wiki reader can learn which risk — or that any risk — was behind a page.
 */
class RiskWikiKnowledgeService
{
    /** How many sources one risk may hand over. A ceiling on a payload, not a rule. */
    public const MAX_PER_RISK = 20;

    public const MAX_TITLE_LENGTH = 255;

    public const MAX_MARKDOWN_LENGTH = 12000;

    public const ENTRY_KIND_PAGE = 'page';

    public const ENTRY_KIND_SOURCE = 'source';

    public function __construct(
        private readonly RiskAccessService $access,
        private readonly CustomerPermissionService $permissions,
        private readonly EnterpriseWikiDocumentUploadService $documentUploads,
        private readonly EnterpriseWikiDocumentFlowService $documentFlow,
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
    ) {}

    /**
     * risk.edit in the risk's area AND the Wiki permission Kvalitet's handoff requires to create a
     * Wiki source. The caller has already reached the risk through visibleRisks(); a Wiki permission
     * never makes a risk visible.
     */
    public function canHandOff(User $user, Risk $risk): bool
    {
        return $this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk)
            && $this->permissions->has($user, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE);
    }

    /** Whether the person may open what was handed over, by the Wiki's own read rule. */
    public function canReadWiki(User $user): bool
    {
        return $this->permissions->has($user, CustomerPermissionCatalog::WIKI_VIEW);
    }

    public function countForRisk(Risk $risk): int
    {
        return RiskWikiSource::query()
            ->where('customer_id', $risk->customer_id)
            ->where('risk_id', $risk->id)
            ->count();
    }

    /**
     * @return array{document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun, provenance: RiskWikiSource}
     */
    public function create(User $actor, Risk $risk, string $title, string $markdown): array
    {
        $customerId = (int) $risk->customer_id;
        $title = trim($title);
        $markdown = trim($markdown);

        $stored = $this->documentUploads->storeAuthoredText(
            customerId: $customerId,
            filename: $this->sourceFilename($title),
            text: Str::startsWith($markdown, '# ') ? $markdown : "# {$title}\n\n{$markdown}",
            // Same rule as Kvalitet: owner only where the person qualifies; Wiki handles an unowned source.
            ownerUserId: $actor->canBeEnterpriseWikiDocumentOwner() ? (int) $actor->id : null,
            uploadedByUserId: (int) $actor->id,
        );

        $document = $stored['document'];

        $provenance = RiskWikiSource::query()->firstOrCreate(
            ['risk_id' => (int) $risk->id, 'enterprise_wiki_document_id' => (int) $document->id],
            ['customer_id' => $customerId, 'created_by_user_id' => (int) $actor->id],
        );

        $prepared = $this->documentFlow->startForDocument($customerId, (int) $document->id);

        return ['document' => $document, 'run' => $prepared['run'], 'provenance' => $provenance];
    }

    /**
     * What this risk has handed over, read live from the Wiki: the pages its sources' runs created,
     * or the source itself while no page exists yet. Links only for someone who may read the Wiki.
     *
     * @return list<array<string, mixed>>
     */
    public function describeForRisk(Risk $risk, bool $withLinks): array
    {
        $customerId = (int) $risk->customer_id;

        $rows = RiskWikiSource::query()
            ->where('customer_id', $customerId)
            ->where('risk_id', $risk->id)
            ->whereHas('document', fn ($query) => $query->where('customer_id', $customerId))
            ->with('document')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $documentIds = $rows->pluck('enterprise_wiki_document_id')->map(intval(...))->unique()->values();

        $runs = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('source_id', $documentIds)
            ->get(['id', 'source_id']);

        $documentIdByRunId = $runs->mapWithKeys(fn (EnterpriseWikiIngestRun $run): array => [(int) $run->id => (int) $run->source_id])->all();

        // Only pages a run CREATED: a page the run merely updated did not come out of this risk.
        $pageIdsByDocument = [];

        if ($runs->isNotEmpty()) {
            EnterpriseWikiIngestRunPage::query()
                ->whereIn('enterprise_wiki_ingest_run_id', $runs->pluck('id'))
                ->where('action', EnterpriseWikiIngestRunPage::ACTION_CREATED)
                ->orderBy('id')
                ->get(['enterprise_wiki_ingest_run_id', 'enterprise_wiki_page_id'])
                ->each(function (EnterpriseWikiIngestRunPage $runPage) use ($documentIdByRunId, &$pageIdsByDocument): void {
                    $documentId = $documentIdByRunId[(int) $runPage->enterprise_wiki_ingest_run_id] ?? null;

                    if ($documentId !== null) {
                        $pageIdsByDocument[$documentId][] = (int) $runPage->enterprise_wiki_page_id;
                    }
                });
        }

        $allPageIds = array_values(array_unique(array_merge([], ...array_values($pageIdsByDocument))));

        // Customer-scoped, and a deleted page simply is not there to resolve.
        $pages = $allPageIds === []
            ? collect()
            : EnterpriseWikiPage::query()
                ->where('customer_id', $customerId)
                ->whereIn('id', $allPageIds)
                ->with(['currentVersion', 'publishedVersion'])
                ->get()
                ->keyBy('id');

        $entries = [];

        foreach ($rows as $row) {
            $documentId = (int) $row->enterprise_wiki_document_id;
            $rowPages = array_values(array_filter(array_map(
                static fn (int $pageId): ?EnterpriseWikiPage => $pages->get($pageId),
                array_values(array_unique($pageIdsByDocument[$documentId] ?? [])),
            )));

            foreach ($rowPages as $page) {
                $entries[] = [
                    'kind' => self::ENTRY_KIND_PAGE,
                    'key' => "page-{$row->id}-{$page->id}",
                    'title' => (string) $page->title,
                    'state_label' => $this->publicationStatus->forPage($page, $page->currentVersion)['state_label'],
                    'url' => $withLinks ? route('app.wiki.show', ['slug' => $page->slug]) : null,
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            }

            if ($rowPages !== []) {
                continue;
            }

            $name = pathinfo((string) $row->document->original_filename, PATHINFO_FILENAME);

            $entries[] = [
                'kind' => self::ENTRY_KIND_SOURCE,
                'key' => "source-{$row->id}",
                'title' => $name !== '' ? $name : (string) $row->document->original_filename,
                'state_label' => __('procynia.risk.wiki_knowledge.source_state'),
                'url' => $withLinks ? route('app.wiki.index', ['tab' => 'sources']) : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        }

        return $entries;
    }

    /** What the source is called in Wiki → Kildedokumenter: the user's own title. */
    private function sourceFilename(string $title): string
    {
        $name = trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $title) ?? '');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::limit($name !== '' ? $name : 'Kunnskapsartikkel', 180, '').'.md';
    }
}
