<?php

namespace App\Services\Api;

use Illuminate\Validation\ValidationException;

/**
 * The opaque keyset cursor of the external API's lists: the last id of the previous page, nothing
 * else. It carries no customer and no filter, so it can only continue a list the access service has
 * already scoped — a cursor from another customer's list finds nothing it may not see.
 */
final class ApiCursor
{
    public static function encode(int $lastId): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['after' => $lastId])), '+/', '-_'), '=');
    }

    /** The id to continue after. Anything but a cursor this class wrote is a 422. */
    public static function decode(string $cursor): int
    {
        $json = preg_match('/^[A-Za-z0-9_-]+$/', $cursor) === 1
            ? base64_decode(strtr($cursor, '-_', '+/'), true)
            : false;
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $after = is_array($decoded) ? ($decoded['after'] ?? null) : null;

        if (! is_int($after) || $after < 1 || count($decoded) !== 1) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return $after;
    }
}
