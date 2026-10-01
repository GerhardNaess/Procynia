<?php

namespace App\Jobs\Quality;

use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects one page's quality layer — its classification and its outgoing quality relations — onto
 * the graph.
 *
 * Runs on the existing `enterprise-wiki` queue rather than a queue of its own. It writes to the
 * same Neo4j nodes ProjectEnterpriseWikiPageToGraph writes to, and sharing the queue is what keeps
 * two writes to one node serialised behind the same worker. A dedicated queue would also have to be
 * added to docker-compose.yml and the Azure worker array for nothing — see
 * tests/Feature/Azure/QueueTopologyContractTest.php.
 *
 * SQL is the source of truth, so a failed projection is a stale graph, never lost data:
 * `wiki:graph-project` rebuilds the customer from SQL and repairs it.
 */
class ProjectQualityPageToGraph implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enterprise-wiki';

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $pageId)
    {
        $this->queue = self::QUEUE;
    }

    public function handle(EnterpriseWikiGraphProjector $projector): void
    {
        $projector->projectPageQuality($this->pageId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[QUALITY_GRAPH_PROJECTION] Quality projection job failed.', [
            'page_id' => $this->pageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
