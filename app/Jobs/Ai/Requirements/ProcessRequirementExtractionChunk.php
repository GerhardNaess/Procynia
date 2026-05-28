<?php

namespace App\Jobs\Ai\Requirements;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Purpose: Represent one future AI requirement chunk job in the ai-requirements queue.
 * Inputs: The extraction call id and, optionally, the parent extraction run id.
 * Returns: None.
 * Side effects: None yet; this class establishes the split-job boundary before the production fan-out is wired in.
 */
class ProcessRequirementExtractionChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $callId,
        public readonly ?int $runId = null,
    ) {
        $this->queue = 'ai-requirements';
    }

    public function handle(): void
    {
        // Intentionally empty until the split orchestration is connected in a later step.
    }
}
