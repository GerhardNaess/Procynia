<?php

namespace App\Services\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The correlation id of one API request: in every error body, in the X-Request-Id header and in
 * every log line the request writes.
 *
 * Always minted here, never taken from the client. A client-chosen id could collide with another
 * request's, or be crafted to make log lines look related.
 */
final class ApiRequestId
{
    private const ATTRIBUTE = 'procynia.api.request_id';

    public static function for(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($id) || $id === '') {
            $id = (string) Str::ulid();
            $request->attributes->set(self::ATTRIBUTE, $id);
        }

        return $id;
    }
}
