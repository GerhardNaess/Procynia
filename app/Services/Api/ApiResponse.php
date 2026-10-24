<?php

namespace App\Services\Api;

use Illuminate\Http\JsonResponse;

/**
 * The success envelope of the external API: {"data": …, "meta": {"as_of", "request_id", …}}.
 *
 * Times are UTC, ISO 8601. as_of says when the answer was computed.
 */
final class ApiResponse
{
    /**
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function ok(array $data, ApiRequestContext $context, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => array_merge([
                'as_of' => now()->utc()->toIso8601ZuluString(),
                'request_id' => $context->requestId,
            ], $meta),
        ], $status);
    }

    /**
     * A record created by the request (201), in the same envelope.
     *
     * @param  array<mixed>  $data
     */
    public static function created(array $data, ApiRequestContext $context): JsonResponse
    {
        return self::ok($data, $context, [], 201);
    }
}
