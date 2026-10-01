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

    public function __construct(public readonly int $pageId)
    {
        $this->queue = self::QUEUE;
    }

    public function handle(EnterpriseWikiGraphProjector $projector): void
    {
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
