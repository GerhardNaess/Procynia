<?php

namespace App\Services\Api;

use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\User;

/**
 * Who is calling, for one API request: filled in by the API middleware as each check passes.
 *
 * A fresh instance per request (LogApiRequest binds it), so nothing carries over from an earlier
 * request in the same process. The log line at the end of the request reads it.
 */
final class ApiRequestContext
{
    public const MODE_APPLICATION = 'application';

    public ?ApiClient $client = null;

    public ?string $mode = null;

    public ?Customer $customer = null;

    public ?User $actor = null;

    /** The token carries the write app role. Required by every write route, granting none. */
    public bool $writeRole = false;

    /** How many records the response returned, when the endpoint says. */
    public ?int $rows = null;

    public function __construct(public readonly string $requestId) {}

    public function authenticated(ApiClient $client, bool $writeRole = false): void
    {
        $this->client = $client;
        $this->mode = self::MODE_APPLICATION;
        $this->writeRole = $writeRole;
    }
}
