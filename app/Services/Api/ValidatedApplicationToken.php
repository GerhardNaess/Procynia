<?php

namespace App\Services\Api;

use App\Models\ApiClient;

/** A verified application-mode access token, reduced to what Procynia uses. */
final class ValidatedApplicationToken
{
    public function __construct(
        public readonly ApiClient $client,
        public readonly string $tenantId,
        /** The client's service principal (`oid`), for the log. */
        public readonly ?string $objectId,
        /**
         * The token carries the write app role (Procynia.Write.All). A ceiling only: it lets the
         * client ask for a write operation, never grants one (IntegrationOperationAuthority).
         */
        public readonly bool $writeRole = false,
    ) {}
}
