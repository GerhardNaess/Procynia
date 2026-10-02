<?php

namespace App\Services\Quality;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Ai\Quality\ProcessActivityArticleAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Support\Ai\AiCallContextScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An activity is a source of knowledge articles.
 *
 * THE DIRECTION THIS RUNS IN.
 *
 *   prosess -> aktivitet -> "Opprett kunnskapsartikkel" -> utkast -> Enterprise Wiki draft
 *
 * Not the other way round. A prosessaktivitet is where the virksomhet knows something that is not
 * written down anywhere, and Kvalitet's job at that point is to get it into Enterprise Wiki — not to
 * hunt through Wiki for a page that might already cover it. Procynia drafts the article from the
 * activity's place in the process — the step before it, the branch condition that sends the work
 * there, what judges the result afterwards, and what the user has already settled about the process
 * (see QualityActivityArticleContextBuilder) — in the one fixed structure every activity article
 * has. The user corrects it; what is created is an ordinary Wiki page in draft, which then goes
 * through Wiki's own review, approval and publication exactly like a page that arrived from a
 * document ingest.
 *
 * WIKI OWNS THE ARTICLE. Nothing of the page's content is stored anywhere in Kvalitet — not the
 * title, not the text, not the status. The flow holds no article content at all, and this service
 * never writes to a page again after creating it. Editing happens in Wiki, because that is where
 * the page lives.
 *
 * WHAT KVALITET KEEPS. One row per created article, saying which activity it came out of — see
 * QualityActivityWikiPage. That is provenance and nothing else, and it is what lets the flow show
 * "this step has produced two articles" and the graph record
 * `(:QualityActivity)-[:SOURCE_OF_ARTICLE]->(:EnterpriseWikiPage)`.
 *
 * WHY THE CONTENT IS HUMAN-AUTHORED. The draft was written by a model, but what is created is what
 * the person approved after reading and editing it, and it is grounded in no document. Recording it
 * as source-based would claim a document backs it, which is exactly the thing Procynia must never
 * do; recording it as best-practice would put it into Wiki's own review queue for a recommendation
 * Procynia did not make. `human_authored` is the honest answer, and it is the one Wiki's manual
 * editing already uses — so the page is editable in Wiki from the moment it exists.
 */
class QualityActivityArticleService
{
    /** How many articles one activity may be the source of. A ceiling on a payload, not a rule. */
    public const MAX_PER_ACTIVITY = 20;

    public function __construct(
        private readonly ProcessActivityArticleAiClient $client,
        private readonly QualityActivityArticleContextBuilder $contextBuilder,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
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
     * The article, as an ordinary Enterprise Wiki page in draft.
     *
     * The page is owned by whoever created it, so they are the one who can send it for review —
     * which is the next step of Wiki's own flow, taken in Wiki and not here.
     *
     * @return array{page: EnterpriseWikiPage, provenance: QualityActivityWikiPage}
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
        // activity, and a page created from one would have provenance pointing at nothing.
        $this->activityOrFail($blueprint, $activityKey);

        $title = trim($title);
        $markdown = trim($markdown);

        return DB::transaction(function () use ($item, $activityKey, $title, $markdown, $actor): array {
            $page = $this->createPage((int) $item->customer_id, $title, $actor);

            $this->versionWriter->writeNewCurrentVersion($page, [
                'content_markdown' => $markdown,
                'content_blocks_json' => $this->blocks($markdown),
                'generated_by_model' => null,
                'created_by_user_id' => (int) $actor->id,
            ]);

            $provenance = QualityActivityWikiPage::query()->create([
                'customer_id' => (int) $item->customer_id,
                'quality_item_id' => (int) $item->id,
                'activity_key' => $activityKey,
                'enterprise_wiki_page_id' => (int) $page->id,
                'created_by_user_id' => (int) $actor->id,
            ]);

            return ['page' => $page, 'provenance' => $provenance];
        });
    }

    /**
     * How many articles this activity has already produced.
     *
     * Read before creating another, so the ceiling is enforced where it means something rather than
     * discovered by a payload that got too big to render.
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
     * The articles each activity of one process has produced, as the flow needs to show them.
     *
     * Resolved fresh on every page load, which is the whole point of holding nothing but the
     * reference: the activity shows what the article is called and how far it has got through
     * publication *now*. One query for the whole flow — a flow with eighty nodes must not be eighty
     * queries — and a page that has been deleted simply is not there to resolve, because the
     * provenance row went with it.
     *
     * @return array<string, list<array<string, mixed>>> activity key to its articles
     */
    public function describeForItem(int $customerId, int $itemId): array
    {
        $rows = QualityActivityWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $itemId)
            // Read access to a Wiki page is not gated on its approval status — status gates
            // actions, never reading — so the only filter is the tenant, and an article still in
            // draft is shown as a draft rather than hidden.
            ->whereHas('page', fn ($query) => $query->where('customer_id', $customerId))
            ->with(['page.currentVersion', 'page.publishedVersion'])
            ->orderBy('id')
            ->get();

        $described = [];

        foreach ($rows as $row) {
            $page = $row->page;

            if (! $page instanceof EnterpriseWikiPage) {
                continue;
            }

            $described[(string) $row->activity_key][] = [
                'page_id' => (int) $page->id,
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'status' => $page->status,
                'url' => route('app.wiki.show', ['slug' => $page->slug]),
                'created_at' => $row->created_at?->toDateTimeString(),
                // The same presenter the Wiki list and the Wiki page use, so one page cannot read
                // as approved in Kvalitet and in review in Wiki.
                'publication' => $this->publicationStatus->forPage($page, $page->currentVersion),
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
     * The page row itself.
     *
     * `generated_by` is `manual`, because a person decided what it says and a person created it.
     * `owner_user_id` is that person: Wiki's submit-for-review gate is owner-or-System-Owner, so an
     * article nobody owns would be an article nobody could hand on.
     */
    private function createPage(int $customerId, string $title, User $actor): EnterpriseWikiPage
    {
        $base = $this->slugBase($title);

        // A slug collides with a page that already exists under the same name, which is ordinary —
        // two activities may both produce "Sikkerhetskrav". Suffix rather than refuse: the user has
        // already written the article, and losing it to a name clash would be absurd. The unique
        // constraint on (customer_id, slug) is what decides, so a concurrent create is caught and
        // retried rather than assumed away.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $slug = $attempt === 0 ? $base : $base.'-'.($attempt + 1);

            if (EnterpriseWikiPage::query()->where('customer_id', $customerId)->where('slug', $slug)->exists()) {
                continue;
            }

            try {
                return EnterpriseWikiPage::query()->create([
                    'customer_id' => $customerId,
                    'owner_user_id' => (int) $actor->id,
                    'slug' => $slug,
                    'title' => $title,
                    'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
                    'status' => EnterpriseWikiPage::STATUS_DRAFT,
                    'generated_by' => EnterpriseWikiPage::GENERATED_BY_MANUAL,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Somebody took this slug between the check and the insert. Try the next one.
                continue;
            }
        }

        throw new RuntimeException("QualityActivityArticleService: could not allocate a slug for [{$base}].");
    }

    private function slugBase(string $title): string
    {
        $slug = Str::limit(Str::slug($title), 180, '');

        return $slug !== '' ? $slug : 'kunnskapsartikkel-'.Str::lower(Str::random(8));
    }

    /**
     * The article's paragraphs, as content blocks.
     *
     * Blocks and not just Markdown, because blocks are what Wiki edits, lints, renders and anchors
     * review against — a page stored as a single string of Markdown is a page the owner cannot edit
     * one paragraph of afterwards. Split on blank lines, the same split
     * EnterpriseWikiPageContentBlockService uses, so the keys and positions read the same as every
     * other page's.
     *
     * Every block is `human_authored` with no source provenance at all. See the class note.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(string $markdown): array
    {
        $blocks = [];

        foreach (preg_split("/\n{2,}/", trim($markdown)) ?: [] as $part) {
            $text = trim($part);

            if ($text === '') {
                continue;
            }

            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) (count($blocks) + 1), 4, '0', STR_PAD_LEFT),
                'position' => count($blocks),
                'markdown' => $text,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_HUMAN_AUTHORED,
                'source_type' => null,
                'source_id' => null,
                'source_label' => null,
                'source_hash' => null,
                'document_version_hash' => null,
                'source_element_key' => null,
                'source_element_type' => null,
                'source_row_key' => null,
                'source_excerpt' => null,
                'page_reference' => null,
                'source_elements' => [],
                'best_practice_reason' => null,
                'link_intents' => [],
            ];
        }

        return $blocks;
    }
}
