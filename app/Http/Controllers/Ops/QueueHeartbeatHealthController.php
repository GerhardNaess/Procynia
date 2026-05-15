<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Operations\QueueHeartbeatHealthService;
use Illuminate\Http\JsonResponse;

class QueueHeartbeatHealthController extends Controller
{
    /**
     * Purpose: Return the queue-specific heartbeat health payload.
     * Inputs: The queue name and the queue heartbeat health service.
     * Returns: A JSON response with the queue heartbeat status.
     * Side effects: None.
     */
    public function check(string $queue, QueueHeartbeatHealthService $service): JsonResponse
    {
        if (! $service->supports($queue)) {
            return response()->json([
                'status' => 'fail',
                'queue' => $queue,
                'message' => 'Unknown queue',
            ], 404);
        }

        $payload = $service->evaluate($queue);

        return response()->json($payload, $payload['status'] === 'ok' ? 200 : 503);
    }
}
