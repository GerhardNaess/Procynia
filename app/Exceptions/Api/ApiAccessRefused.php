<?php

namespace App\Exceptions\Api;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The client application is known, but this request is not one it may make (403).
 *
 * The reason is for the log only. Every 403 reads the same to the client — a customer without a
 * grant, a revoked grant and a customer that does not exist are indistinguishable.
 */
class ApiAccessRefused extends AuthorizationException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('API access refused.');
    }
}
