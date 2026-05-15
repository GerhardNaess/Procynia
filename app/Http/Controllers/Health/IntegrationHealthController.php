<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Health\IntegrationHealthService;
use Illuminate\Http\JsonResponse;

class IntegrationHealthController extends Controller
{
    /**
     * Purpose: Return the Doffin import freshness health payload.
     * Inputs: The integration health service.
     * Returns: A JSON response with the Doffin freshness status.
     * Side effects: None.
     */
    public function doffinImportFreshness(IntegrationHealthService $service): JsonResponse
    {
        return $this->jsonResponse($service->doffinImportFreshness());
    }

    /**
     * Purpose: Return the OpenAI connectivity health payload.
     * Inputs: The integration health service.
     * Returns: A JSON response with the OpenAI connectivity status.
     * Side effects: Performs one minimal OpenAI API request.
     */
    public function openAiConnectivity(IntegrationHealthService $service): JsonResponse
    {
        return $this->jsonResponse($service->openAiConnectivity());
    }

    /**
     * Purpose: Return the Stripe webhook processing health payload.
     * Inputs: The integration health service.
     * Returns: A JSON response with the Stripe webhook status.
     * Side effects: Reads local billing and failed-job data.
     */
    public function stripeWebhooks(IntegrationHealthService $service): JsonResponse
    {
        return $this->jsonResponse($service->stripeWebhooks());
    }

    /**
     * Purpose: Convert a normalized health payload into a JSON response.
     * Inputs: A normalized health payload from a service.
     * Returns: A JSON response with a matching HTTP status code.
     * Side effects: None.
     *
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): JsonResponse
    {
        $statusCode = ($payload['status'] ?? 'fail') === 'ok' ? 200 : 503;

        return response()->json($payload, $statusCode);
    }
}
