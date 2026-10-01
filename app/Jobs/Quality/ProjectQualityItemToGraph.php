<?php

namespace App\Jobs\Quality;

use App\Services\Quality\QualityGraphProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects one quality item onto the graph — its node, its outgoing relations, and the Wiki pages
 * it draws on.
 *
 * Runs on the existing `enterprise-wiki` queue rather than one of its own. It writes to the same
 * Neo4j database ProjectEnterpriseWikiPageToGraph writes to, and touches the same page nodes
 * through SUPPORTED_BY; sharing the queue is what keeps two writes to one node serialised behind
 * the same worker. A dedicated queue would also have to be added to docker-compose.yml and the
 * Azure worker array for nothing — see tests/Feature/Azure/QueueTopologyContractTest.php.
 *
 * SQL is the source of truth, so a failed projection is a stale graph, never lost data:
 * `wiki:graph-project` rebuilds the customer from SQL and repairs it.
 */
class ProjectQualityItemToGraph implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enterprise-wiki';

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  int|null  $deletedForCustomerId  set only when the item is already gone from SQL. The
     *                                          projector cannot look the customer up from a row that
     *                                          no longer exists, so a delete has to carry it.
     */
    public function __construct(
        public readonly int $itemId,
        public readonly ?int $deletedForCustomerId = null,
    ) {
        $this->queue = self::QUEUE;
    }

    public function handle(QualityGraphProjector $projector): void
    {
        if ($this->deletedForCustomerId !== null) {
            $projector->deleteItem($this->deletedForCustomerId, $this->itemId);

            return;
        }

        $projector->projectItem($this->itemId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[QUALITY_GRAPH_PROJECTION] Quality projection job failed.', [
            'quality_item_id' => $this->itemId,
            'error' => $exception->getMessage(),
        ]);
    }
}
