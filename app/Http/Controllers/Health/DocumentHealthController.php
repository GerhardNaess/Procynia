<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Health\DocumentHealthService;
use Illuminate\Http\JsonResponse;

class DocumentHealthController extends Controller
{
    /**
     * Purpose: Return the document parsing health payload.
     * Inputs: The document health service.
     * Returns: A JSON response with the document parsing status.
     * Side effects: Reads local requirement extraction and failed-job data.
     */
    public function documentParsing(DocumentHealthService $service): JsonResponse
    {
        return $this->jsonResponse($service->documentParsing());
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
