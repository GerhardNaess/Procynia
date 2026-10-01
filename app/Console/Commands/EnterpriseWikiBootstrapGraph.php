<?php

namespace App\Console\Commands;

use App\Services\EnterpriseWiki\GraphProjection\GraphProjectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the schema the Enterprise Wiki graph projection depends on. Kept as an explicit
 * command rather than a bootstrap on every request: the projection store is rebuildable
 * infrastructure, so provisioning it is an operator action, not a request-path side effect.
 */
#[Signature('wiki:graph-bootstrap')]
#[Description('Create the Enterprise Wiki graph projection schema. Idempotent.')]
class EnterpriseWikiBootstrapGraph extends Command
{
    public function handle(GraphProjectionService $projection): int
    {
        if (! (bool) config('services.neo4j.enabled', false)) {
            // The container binds the null projection in this case, so ensureSchema() would be a
            // silent no-op. Say so rather than reporting a constraint that was never created.
            $this->warn('[WIKI_GRAPH_PROJECTION] Neo4j is disabled (NEO4J_ENABLED=false). No schema was created.');

            return self::SUCCESS;
        }

        try {
            $projection->ensureSchema();
        } catch (Throwable $e) {
            Log::error('[WIKI_GRAPH_PROJECTION] Schema bootstrap failed.', [
                'error' => $e->getMessage(),
            ]);

            $this->error('[WIKI_GRAPH_PROJECTION] Schema bootstrap failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('[WIKI_GRAPH_PROJECTION] Graph projection schema is in place.');

        return self::SUCCESS;
    }
}
