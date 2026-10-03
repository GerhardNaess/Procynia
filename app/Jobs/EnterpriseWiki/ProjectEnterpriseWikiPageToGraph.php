<?php

namespace App\Jobs\EnterpriseWiki;

use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProjectEnterpriseWikiPageToGraph implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enterprise-wiki';

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  int|null  $deletedForCustomerId  set only when the page is already gone from SQL. The
     *                                          projector cannot look the customer up from a row that
     *                                          no longer exists, so a delete has to carry it.
     */
    public function __construct(
        public readonly int $pageId,
        public readonly ?int $deletedForCustomerId = null,
    ) {
        $this->queue = self::QUEUE;
    }

    public function handle(EnterpriseWikiGraphProjector $projector): void
    {
        if ($this->deletedForCustomerId !== null) {
            $projector->deletePage($this->deletedForCustomerId, $this->pageId);

            return;
        }

        $projector->projectPage($this->pageId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[WIKI_GRAPH_PROJECTION] Page projection job failed.', [
            'page_id' => $this->pageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
