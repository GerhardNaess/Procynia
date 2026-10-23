<?php

namespace App\Exceptions\Api;

use Illuminate\Auth\AuthenticationException;

/**
 * The bearer token does not identify an accepted client application (401).
 *
 * The reason is for the log only. Every 401 reads the same to the client, so a caller probing with
 * forged tokens learns nothing about which check failed.
 */
class ApiAuthenticationFailed extends AuthenticationException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('API authentication failed.');
    }
}
