<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Wiki graph that tests/e2e/wiki-graph-focus.spec.js and wiki-graph-labels.spec.js draw, owned
 * by those specs and seeded into the customer they are given (the E2E customer).
 *
 * The smallest graph that holds the cases the specs are about:
 *
 *  - a hub with more than twenty neighbours — the neighbourhood a single ring could not hold. It
 *    links out to twenty pages and two link to it, so both directions have something to draw;
 *  - a focus page with a middling neighbourhood of four, one of them the hub, so a second hop
 *    visibly grows the picture, and one of them linking in, so "Lenker inn" is not empty;
 *  - one long title among the focus page's neighbours, which the canvas has to truncate and the
 *    node panel must show in full.
 *
 * Pages are projected into the graph store, which the focus view reads. Test-only (autoload-dev),
 * invoked through tinker. seed() starts from a clean slate and returns the two page ids as JSON;
 * cleanup() removes the pages from the graph store and from SQL, taking their links with them.
 */
class WikiGraphFocusE2EFixture
{
    private const SLUG_PREFIX = 'e2e-graffokus-';

    private const HUB_TITLE = 'Sikkerhetssenter';

    private const FOCUS_TITLE = 'Hendelseshåndtering';

    public const LONG_TITLE = 'Styringsnivåer: strategisk, taktisk og operativt ansvar i tjenesteleveransen';

    private const HUB_OUTGOING = 20;

    private const HUB_INCOMING = 2;

    public static function seed(int $customerId): string
    {
        self::cleanup($customerId);

        $ids = DB::transaction(function () use ($customerId): array {
            $page = fn (string $title): int => (int) EnterpriseWikiPage::query()->create([
                'customer_id' => $customerId,
                'slug' => self::SLUG_PREFIX.Str::slug($title),
                'title' => $title,
                'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'status' => EnterpriseWikiPage::STATUS_APPROVED,
                'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            ])->id;

            $link = fn (int $from, int $to) => EnterpriseWikiPageLink::query()->create([
                'customer_id' => $customerId,
                'from_page_id' => $from,
                'to_page_id' => $to,
                'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
                'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            ]);

            $hub = $page(self::HUB_TITLE);
            $focus = $page(self::FOCUS_TITLE);

            // The focus page is one of the hub's twenty outgoing neighbours.
            $link($hub, $focus);

            for ($n = 1; $n < self::HUB_OUTGOING; $n++) {
                $link($hub, $page(sprintf('Sikkerhetsrutine %02d', $n)));
            }

            for ($n = 1; $n <= self::HUB_INCOMING; $n++) {
                $link($page(sprintf('Sikkerhetsrapport %02d', $n)), $hub);
            }

            $link($focus, $page(self::LONG_TITLE));
            $link($focus, $page('Eskalering'));
            $link($page('Beredskapsplan'), $focus);

            return ['hub_page_id' => $hub, 'focus_page_id' => $focus];
        });

        $projector = app(EnterpriseWikiGraphProjector::class);

        foreach (self::pageIds($customerId) as $pageId) {
            $projector->projectPage($pageId);
        }

        return (string) json_encode($ids);
    }

    public static function cleanup(int $customerId): void
    {
        $pageIds = self::pageIds($customerId);
        $projector = app(EnterpriseWikiGraphProjector::class);

        foreach ($pageIds as $pageId) {
            $projector->deletePage($customerId, $pageId);
        }

        DB::transaction(function () use ($customerId, $pageIds): void {
            EnterpriseWikiPageLink::query()
                ->where('customer_id', $customerId)
                ->where(fn ($query) => $query->whereIn('from_page_id', $pageIds)->orWhereIn('to_page_id', $pageIds))
                ->delete();
            EnterpriseWikiPage::query()->whereKey($pageIds)->delete();
        });
    }

    /**
     * @return list<int>
     */
    private static function pageIds(int $customerId): array
    {
        return EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
