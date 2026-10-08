<?php

namespace App\Services\EnterpriseWiki\Knowledge;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fagmodul → strukturert kunnskapsgrunnlag → Enterprise Wiki. The one way a module deposits
 * knowledge in the Wiki.
 *
 * WHAT A MODULE GIVES. A WikiKnowledgeSource adapter finds the record through the module's own
 * access rules and describes what it knows as a WikiKnowledgeDraft — plain-language sections built
 * from its domain objects. The person chooses which sections may be shared and writes the lesson
 * in their own words; the server rebuilds the sections from the record, so nothing the browser
 * sends is trusted as content except that person's own text.
 *
 * WHAT THE WIKI DOES. The result is stored as an ordinary Wiki SOURCE document
 * (EnterpriseWikiDocumentUploadService::storeAuthoredText) and handed to the ordinary document flow
 * (EnterpriseWikiDocumentFlowService::startForDocument). From there the Wiki plans, generates,
 * verifies, links and reviews pages with its own AI clients, on its own queues, under its own
 * publication rules — exactly as for an uploaded file. Nothing here publishes, writes a page or
 * calls a model.
 *
 * PROVENANCE. One EnterpriseWikiDocumentOrigin row per (document, source record). Read by the
 * source module and by AI usage attribution; never shown by a Wiki screen.
 *
 * PERMISSIONS. The module's own write permission on the record AND wiki.source.manage AND the
 * customer's Wiki module. Neither side implies the other.
 */
class WikiKnowledgeHandoffService
{
    /** How many sources one record may hand over. A ceiling on a payload, not a rule. */
    public const MAX_PER_SOURCE = 20;

    public const MAX_TITLE_LENGTH = 255;

    public const MAX_LEARNING_LENGTH = 12000;

    public const ENTRY_KIND_PAGE = 'page';

    public const ENTRY_KIND_SOURCE = 'source';

    public function __construct(
        private readonly WikiKnowledgeSourceRegistry $registry,
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $modules,
        private readonly EnterpriseWikiDocumentUploadService $documentUploads,
        private readonly EnterpriseWikiDocumentFlowService $documentFlow,
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
    ) {}

    public function source(string $sourceType): WikiKnowledgeSource
    {
        return $this->registry->get($sourceType);
    }

    public function canHandOff(User $user, WikiKnowledgeSource $source, Model $record): bool
    {
        $customer = $user->customer_id === null ? null : Customer::query()->find($user->customer_id);

        return $customer instanceof Customer
            && (int) $record->getAttribute('customer_id') === (int) $customer->id
            && $source->canHandOff($user, $record)
            && $this->permissions->has($user, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE)
            && $this->modules->hasModule($customer, 'wiki');
    }

    /** Whether the person may open what was handed over, by the Wiki's own read rule. */
    public function canReadWiki(User $user): bool
    {
        return $this->permissions->has($user, CustomerPermissionCatalog::WIKI_VIEW);
    }

    public function countForSource(string $sourceType, Model $record): int
    {
        return $this->originsFor($sourceType, $record)->count();
    }

    /**
     * Everything the shared «Lag kunnskapsartikkel» panel needs for one record. The draft is only
     * built for someone who may hand it over — the panel never shows module content to anyone the
     * module itself would not show it to, and builds nothing it will not use.
     *
     * @return array<string, mixed>
     */
    public function panel(User $user, string $sourceType, Model $record): array
    {
        $source = $this->source($sourceType);
        $canCreate = $this->canHandOff($user, $source, $record);

        return [
            'can_create' => $canCreate,
            'entries' => $this->describe($sourceType, $record, $this->canReadWiki($user)),
            'submit_url' => $canCreate ? $source->storeUrl($record) : null,
            'draft' => $canCreate ? $source->draft($user, $record)->toArray() : null,
            'limits' => [
                'title' => self::MAX_TITLE_LENGTH,
                'learning' => self::MAX_LEARNING_LENGTH,
                'reached' => $this->countForSource($sourceType, $record) >= self::MAX_PER_SOURCE,
            ],
        ];
    }

    /**
     * Hand one record over: the chosen sections of the module's draft plus the person's own lesson.
     *
     * @param  list<string>  $sectionKeys
     * @return array{document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun, run_started: bool, origin: EnterpriseWikiDocumentOrigin}
     */
    public function handOff(User $actor, string $sourceType, Model $record, string $title, array $sectionKeys, string $learning): array
    {
        $source = $this->source($sourceType);

        return $this->deposit(
            customerId: (int) $record->getAttribute('customer_id'),
            actor: $actor,
            sourceModule: $source->sourceModule(),
            sourceType: $sourceType,
            sourceId: (int) $record->getKey(),
            title: $title,
            markdown: $this->render($source->draft($actor, $record), $title, $sectionKeys, $learning),
        );
    }

    /**
     * The shared deposit: store the source, record where it came from, start the ordinary run.
     *
     * Also the entry point for a module that composes its own text (Kvalitet's activity article).
     * The document is stored for the record's customer and the origin is written for that same
     * customer, and the composite key on the origin table refuses anything else. `$beforeStart`
     * lets such a module record its own link to the document before the run can read it.
     *
     * @param  (\Closure(EnterpriseWikiDocument): void)|null  $beforeStart
     * @return array{document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun, run_started: bool, origin: EnterpriseWikiDocumentOrigin}
     */
    public function deposit(
        int $customerId,
        User $actor,
        string $sourceModule,
        string $sourceType,
        int $sourceId,
        string $title,
        string $markdown,
        ?\Closure $beforeStart = null,
    ): array {
        if ((int) $actor->customer_id !== $customerId) {
            throw new InvalidArgumentException('A Wiki knowledge handoff must be made by a user of the source record\'s customer.');
        }

        $title = trim($title);
        $markdown = trim($markdown);

        // The same store, identity and reconciliation every uploaded source gets. When the identical
        // text already is a source, that document is reused and gains this record as an origin.
        $stored = $this->documentUploads->storeAuthoredText(
            customerId: $customerId,
            filename: $this->sourceFilename($title),
            text: Str::startsWith($markdown, '# ') ? $markdown : "# {$title}\n\n{$markdown}",
            // Owner only where the person qualifies; the Wiki handles an unowned source.
            ownerUserId: $actor->canBeEnterpriseWikiDocumentOwner() ? (int) $actor->id : null,
            uploadedByUserId: (int) $actor->id,
        );

        $document = $stored['document'];

        $origin = DB::transaction(fn (): EnterpriseWikiDocumentOrigin => EnterpriseWikiDocumentOrigin::query()->firstOrCreate(
            [
                'enterprise_wiki_document_id' => (int) $document->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'customer_id' => $customerId,
                'source_module' => $sourceModule,
                'created_by_user_id' => (int) $actor->id,
            ],
        ));

        if ($beforeStart !== null) {
            $beforeStart($document);
        }

        // After the origin commits: the run's AI calls attribute their cost to this source.
        $prepared = $this->documentFlow->startForDocument($customerId, (int) $document->id);

        return [
            'document' => $document,
            'run' => $prepared['run'],
            'run_started' => (bool) $prepared['created'],
            'origin' => $origin,
        ];
    }

    /**
     * What this record has handed over, read live from the Wiki: the pages its sources' runs
     * created, or the source itself while no page exists yet. Links only for a Wiki reader.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(string $sourceType, Model $record, bool $withLinks): array
    {
        $customerId = (int) $record->getAttribute('customer_id');

        $rows = $this->originsFor($sourceType, $record)
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

        // Only pages a run CREATED: a page the run merely updated did not come out of this record.
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
                'state_label' => __('procynia.knowledge_handoff.source_state'),
                'url' => $withLinks ? route('app.wiki.index', ['tab' => 'sources']) : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        }

        return $entries;
    }

    /**
     * The source text: the title, the person's lesson first — it is what the Wiki should learn —
     * then the chosen sections exactly as the module described them. Deterministic, so the same
     * choice over the same record produces the same document (and the store reuses it).
     *
     * @param  list<string>  $sectionKeys
     */
    public function render(WikiKnowledgeDraft $draft, string $title, array $sectionKeys, string $learning): string
    {
        $chosen = array_values(array_filter(
            $draft->offeredSections(),
            static fn (WikiKnowledgeDraftSection $section): bool => in_array($section->key, $sectionKeys, true),
        ));

        $parts = ['# '.$this->singleLine($title)];
        $learning = trim($learning);

        if ($learning !== '') {
            $parts[] = '## '.__('procynia.knowledge_handoff.learning_heading')."\n\n".$learning;
        }

        foreach ($chosen as $section) {
            $lines = array_map($this->singleLine(...), $section->lines);
            $body = $section->asList
                ? implode("\n", array_map(static fn (string $line): string => '- '.$line, $lines))
                : implode("\n\n", $lines);

            $parts[] = '## '.$this->singleLine($section->heading)."\n\n".$body;
        }

        return implode("\n\n", $parts)."\n";
    }

    private function originsFor(string $sourceType, Model $record)
    {
        return EnterpriseWikiDocumentOrigin::query()
            ->where('customer_id', (int) $record->getAttribute('customer_id'))
            ->where('source_type', $sourceType)
            ->where('source_id', (int) $record->getKey());
    }

    /** Module text is data, never document structure: a line can never open a heading of its own. */
    private function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', ltrim($text, "# \t")));
    }

    /** What the source is called in Wiki → Kildedokumenter: the person's own title. */
    private function sourceFilename(string $title): string
    {
        $name = trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $title) ?? '');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::limit($name !== '' ? $name : __('procynia.knowledge_handoff.default_filename'), 180, '').'.md';
    }
}
