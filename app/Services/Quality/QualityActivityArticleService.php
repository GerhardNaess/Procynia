<?php

namespace App\Services\Quality;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Ai\Quality\ProcessActivityArticleAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Support\Ai\AiCallContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An activity is a source of knowledge.
 *
 * THE DIRECTION THIS RUNS IN.
 *
 *   prosess -> aktivitet -> "Opprett kunnskapsartikkel" -> utkast -> Wiki-kilde -> ingest-kjøring
 *
 * Not the other way round. A prosessaktivitet is where the virksomhet knows something that is not
 * written down anywhere, and Kvalitet's job at that point is to get it into Enterprise Wiki — not
 * to hunt through Wiki for a page that might already cover it. Procynia drafts the article from
 * the activity's place in the process — the step before it, the branch condition that sends the
 * work there, what judges the result afterwards, and what the user has already settled about the
 * process (see QualityActivityArticleContextBuilder) — in the one fixed structure every activity
 * article has. The user corrects it, and what the user approves becomes a Wiki SOURCE.
 *
 * WHY A SOURCE AND NOT A PAGE. This service used to write an EnterpriseWikiPage itself. It
 * produced a page, and nothing else: no concept pages, no entity pages, no summary, none of the
 * relations between them. Every one of those is planned by the maintainer decision, and a
 * maintainer decision only ever exists for an EnterpriseWikiDocument — so an activity that wrote
 * its own page was an activity that skipped the entire ingest run. The article is therefore
 * stored as an ordinary Wiki source document (EnterpriseWikiDocumentUploadService::storeAuthoredText)
 * and handed to the ordinary document flow (EnterpriseWikiDocumentFlowService::startForDocument),
 * which is the same call the Kildedokumenter list makes. From there the article is treated exactly
 * like a policy somebody uploaded: planned, generated, claim-extracted, verified, linked, QA'd,
 * and projected to Neo4j by Wiki's own code.
 *
 * KVALITET OWNS NO ENRICHMENT. There is no concept logic, no entity logic and no linking here, and
 * there must never be: a second answer to "what concepts does this text have" is a second Wiki.
 * The draft is plain prose for the same reason — a source document does not contain [[wikilinks]],
 * and the pages the run generates get their links from EnterpriseWikiBuildPageLinksService like
 * every other page.
 *
 * WHAT KVALITET KEEPS. One row per created source, saying which activity it came out of — see
 * QualityActivityWikiPage. That is provenance and nothing else, and it is what lets the flow show
 * what a step has produced and the graph record
 * `(:QualityActivity)-[:SOURCE_OF_ARTICLE]->(:EnterpriseWikiPage)` for each page the run made.
 */
class QualityActivityArticleService
{
    /** How many articles one activity may be the source of. A ceiling on a payload, not a rule. */
    public const MAX_PER_ACTIVITY = 20;

    /** A page the run created from this activity's source. */
    public const ENTRY_KIND_PAGE = 'page';

    /**
     * The source itself, shown while the run has produced no page yet — or has stopped without
     * producing one. Never a link: there is nothing to open.
     */
    public const ENTRY_KIND_SOURCE = 'source';

    public function __construct(
        private readonly ProcessActivityArticleAiClient $client,
        private readonly QualityActivityArticleContextBuilder $contextBuilder,
        private readonly EnterpriseWikiDocumentUploadService $documentUploads,
        private readonly EnterpriseWikiDocumentFlowService $documentFlow,
        private readonly QualityActivityKnowledgeResolver $knowledge,
        private readonly EnterpriseWikiPublicationStatusService $publicationStatus,
        private readonly AiCallContextScope $contextScope,
    ) {}

    /**
     * A first draft of the article this activity is the source of.
     *
     * Nothing is stored. The draft goes to the screen, the user corrects it, and only then is
     * anything created — which is the whole reason drafting and creating are two calls.
     *
     * @return array{title: string, markdown: string, model: string}
     *
     * @throws ProcessFlowInterpretationException|AiCostControlException
     */
    public function draft(
        QualityItem $item,
        QualityProcessBlueprint $blueprint,
        string $activityKey,
        string $languageCode,
    ): array {
        $this->activityOrFail($blueprint, $activityKey);

        // Built before the call rather than inside it: it is a read of the flow and of what the user
        // has already settled about this process, and a context that cannot be assembled is not an
        // AI failure.
        $context = $this->contextBuilder->build($item, $blueprint, $activityKey)
            ?? throw new RuntimeException("QualityActivityArticleService: no activity [{$activityKey}] on this flow.");

        return $this->contextScope->within(
            new AiCallContext(
                customerId: (int) $item->customer_id,
                feature: 'quality',
                operation: 'process_activity_article_draft',
                resourceType: 'quality_item',
                resourceId: (int) $item->id,
            ),
            function () use ($context, $languageCode): array {
                try {
                    // No link catalog, deliberately. What is drafted here is the text of a source
                    // document, and a source document is prose — the Wiki pages generated from it
                    // are linked by the run, by the same service that links every other page.
                    $drafted = $this->client->draft($context, $languageCode);
                } catch (AiCostControlException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw ProcessFlowInterpretationException::unavailable($exception);
                }

                return [
                    'title' => Str::limit($drafted['title'], ProcessActivityArticleAiClient::MAX_TITLE_LENGTH, ''),
                    'markdown' => Str::limit($drafted['markdown'], ProcessActivityArticleAiClient::MAX_MARKDOWN_LENGTH, ''),
                    'model' => ProcessActivityArticleAiClient::model(),
                ];
            },
        );
    }

    /**
     * The article, as an ordinary Enterprise Wiki source, with the ordinary ingest run started.
     *
     * Two steps and no more. The text becomes a document in the customer's Wiki document store,
     * and that document is given to the document flow. Everything the Wiki does with a source —
     * deciding which pages it should become, writing them, extracting and verifying their claims,
     * linking them, projecting them — happens in Wiki's own code, on Wiki's own queues, and
     * nothing of it is reimplemented, triggered piecemeal or second-guessed here.
     *
     * The run is started after the transaction commits, for the reason every queued read does:
     * the job reads SQL that must already be there.
     *
     * @return array{document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun, run_started: bool, provenance: QualityActivityWikiPage}
     */
    public function create(
        QualityItem $item,
        QualityProcessBlueprint $blueprint,
        string $activityKey,
        string $title,
        string $markdown,
        User $actor,
    ): array {
        // Resolved before anything is written: an activity key that is not on this flow is not an
        // activity, and a source created from one would have provenance pointing at nothing.
        $this->activityOrFail($blueprint, $activityKey);

        $customerId = (int) $item->customer_id;
        $title = trim($title);
        $markdown = trim($markdown);

        // The same store, the same identity, the same reconciliation every uploaded source gets.
        // `reused` is the honest answer when the identical text is already a source: the activity
        // is recorded as one of its origins rather than a second copy being written.
        $stored = $this->documentUploads->storeAuthoredText(
            customerId: $customerId,
            filename: $this->sourceFilename($title),
            text: $this->sourceText($title, $markdown),
            // Owner where the person qualifies for it, null where they do not. The document-owner
            // sign-off is Wiki's, and Wiki already handles an unowned source; naming an owner who
            // may not hold that responsibility would be worse than naming none.
            ownerUserId: $actor->canBeEnterpriseWikiDocumentOwner() ? (int) $actor->id : null,
            uploadedByUserId: (int) $actor->id,
        );

        $document = $stored['document'];

        $provenance = DB::transaction(fn (): QualityActivityWikiPage => QualityActivityWikiPage::query()->firstOrCreate(
            [
                'quality_item_id' => (int) $item->id,
                'activity_key' => $activityKey,
                'enterprise_wiki_document_id' => (int) $document->id,
            ],
            [
                'customer_id' => $customerId,
                'created_by_user_id' => (int) $actor->id,
            ],
        ));

        $prepared = $this->documentFlow->startForDocument($customerId, (int) $document->id);

        return [
            'document' => $document,
            'run' => $prepared['run'],
            'run_started' => (bool) $prepared['created'],
            'provenance' => $provenance,
        ];
    }

    /**
     * How many articles this activity has already produced.
     *
     * Read before creating another, so the ceiling is enforced where it means something rather than
     * discovered by a payload that got too big to render. Counted on the provenance rows — one per
     * source the activity produced — and not on the pages, because one source legitimately becomes
     * several pages and that is the pipeline working, not the user asking twice.
     */
    public function countForActivity(int $customerId, int $itemId, string $activityKey): int
    {
        return QualityActivityWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $itemId)
            ->where('activity_key', $activityKey)
            ->count();
    }

    /**
     * What each activity of one process has produced, as the flow needs to show it.
     *
     * Resolved fresh on every page load, which is the whole point of holding nothing but the
     * reference: the activity shows what the knowledge is called and how far it has got through
     * publication *now*. A page that has been deleted simply is not there to resolve.
     *
     * An entry is either a page the run created, or — while the run has produced none — the source
     * itself, so a step never looks empty for the minutes its ingest takes.
     *
     * @return array<string, list<array<string, mixed>>> activity key to its entries
     */
    public function describeForItem(int $customerId, int $itemId): array
    {
        $resolved = $this->knowledge->resolve($customerId, $itemId);
        $described = [];

        foreach ($resolved['rows'] as $row) {
            $key = (string) $row->activity_key;
            $pages = $resolved['pages_by_row'][(int) $row->id] ?? [];

            foreach ($pages as $page) {
                $described[$key][] = [
                    'kind' => self::ENTRY_KIND_PAGE,
                    'page_id' => (int) $page->id,
                    'title' => (string) $page->title,
                    'slug' => (string) $page->slug,
                    'page_type' => (string) $page->page_type,
                    'status' => $page->status,
                    'url' => route('app.wiki.show', ['slug' => $page->slug]),
                    'created_at' => $row->created_at?->toDateTimeString(),
                    // The same presenter the Wiki list and the Wiki page use, so one page cannot
                    // read as approved in Kvalitet and in review in Wiki.
                    'publication' => $this->publicationStatus->forPage($page, $page->currentVersion),
                ];
            }

            if ($pages !== []) {
                continue;
            }

            $document = $row->document;

            if (! $document instanceof EnterpriseWikiDocument) {
                // A legacy row whose hand-made page is gone. Nothing to show and nothing to
                // explain — the page took the provenance with it.
                continue;
            }

            $run = $resolved['run_by_row'][(int) $row->id] ?? null;

            $described[$key][] = [
                'kind' => self::ENTRY_KIND_SOURCE,
                'page_id' => null,
                'title' => $this->knowledge->sourceTitle($document),
                'slug' => null,
                'page_type' => null,
                // The run's status, not the page's: there is no page. Null when no run exists yet,
                // which the screen reads the same way as queued.
                'status' => $run?->status,
                'url' => null,
                'created_at' => $row->created_at?->toDateTimeString(),
                'publication' => null,
            ];
        }

        return $described;
    }

    /**
     * The activity, named by its key on this flow, with the role resolved from its lane.
     *
     * The lane's label rather than its key: "who does this" is the question the article is written
     * for, and the key is an implementation detail of the payload.
     *
     * Null when the flow has no such node, which is what a hand-made request looks like — the
     * caller answers that with a 404 rather than this inventing an activity to satisfy it.
     *
     * @return array{key: string, label: string, description: string, role: string}|null
     */
    public function activity(QualityProcessBlueprint $blueprint, string $activityKey): ?array
    {
        $laneLabels = [];

        foreach ($blueprint->lanes() as $lane) {
            $laneLabels[(string) ($lane['key'] ?? '')] = (string) ($lane['label'] ?? '');
        }

        foreach ($blueprint->nodes() as $node) {
            if ((string) ($node['key'] ?? '') !== $activityKey) {
                continue;
            }

            return [
                'key' => $activityKey,
                'label' => trim((string) ($node['label'] ?? '')),
                'description' => trim((string) ($node['description'] ?? '')),
                'role' => $laneLabels[(string) ($node['lane'] ?? '')] ?? '',
            ];
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, description: string, role: string}
     */
    private function activityOrFail(QualityProcessBlueprint $blueprint, string $activityKey): array
    {
        return $this->activity($blueprint, $activityKey)
            ?? throw new RuntimeException("QualityActivityArticleService: no activity [{$activityKey}] on this flow.");
    }

    /**
     * The source document's text.
     *
     * The title is written in as the document's own H1 rather than left on the filename alone:
     * the planner reads the text, and a source whose subject is only in its filename is a source
     * whose subject the planner has to guess at.
     */
    private function sourceText(string $title, string $markdown): string
    {
        return Str::startsWith($markdown, '# ') ? $markdown : "# {$title}\n\n{$markdown}";
    }

    /**
     * What the source is called in Wiki → Kildedokumenter.
     *
     * The article's own title, so somebody looking at the customer's sources recognises what it is
     * without having to open it. `.md` because that is what the stored bytes are.
     */
    private function sourceFilename(string $title): string
    {
        $name = trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $title) ?? '');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            $name = 'Kunnskapsartikkel';
        }

        return Str::limit($name, 180, '').'.md';
    }
}
