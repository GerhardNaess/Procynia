<?php

namespace App\Services\Api;

use App\Models\ApiClientCustomerGrant;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Security\ActorChannel;
use App\Support\ApiOperationCatalog;

/**
 * Whether an integration identity may perform one named operation now (ApiOperationCatalog).
 *
 * Asked by the domain access services, not by controllers, so the answer is the same however the
 * domain service is reached. Every condition is read fresh and fails closed:
 *
 *  - the actor is an active integration identity;
 *  - the operation is in the catalog;
 *  - the request came through the API, as this identity — outside an API request an integration
 *    does nothing — and, for an operation that writes, with a token carrying the write app role
 *    (Procynia.Write.All);
 *  - the identity's grant is active, for its own customer, and its client is enabled and is the
 *    one that called;
 *  - the System Owner has switched the operation on for that grant;
 *  - the customer is active and has the operation's module.
 *
 * It never decides which record: the domain service still asks its own rules (customer, fagområde,
 * business rules) for the record at hand. A person is never an integration and gets false here —
 * people keep their ordinary rights, which this does not read.
 */
class IntegrationOperationAuthority
{
    public function __construct(private readonly ModuleEntitlementService $entitlements) {}

    public function allows(?User $actor, string $operation): bool
    {
        return $this->refusal($actor, $operation) === null;
    }

    /** Why not, for logs and tests; null when allowed. */
    public function refusal(?User $actor, string $operation): ?string
    {
        if (! $actor instanceof User || ! $actor->isIntegration() || ! $actor->is_active || $actor->customer_id === null) {
            return 'not_an_active_integration';
        }

        if (! ApiOperationCatalog::exists($operation)) {
            return 'unknown_operation';
        }

        // Resolved per question, not held: the channel follows the request being handled.
        $context = app()->bound(ApiRequestContext::class) ? app(ApiRequestContext::class) : null;

        if (! app(ActorChannel::class)->isApi() || $context === null || $context->actor === null || (int) $context->actor->id !== (int) $actor->id) {
            return 'not_an_api_request';
        }

        if (ApiOperationCatalog::requiresWriteRole($operation) && ! $context->writeRole) {
            return 'missing_write_role';
        }

        $grant = ApiClientCustomerGrant::query()
            ->active()
            ->where('integration_user_id', (int) $actor->id)
            ->where('customer_id', (int) $actor->customer_id)
            ->with(['client', 'customer'])
            ->first();

        if ($grant === null || $grant->client === null || ! $grant->client->is_enabled || (int) $grant->client->id !== (int) $context->client?->id) {
            return 'no_active_grant';
        }

        if (! $grant->allowsOperation($operation)) {
            return 'operation_not_allowed';
        }

        $customer = $grant->customer;

        if ($customer === null || ! $customer->is_active || ! $this->entitlements->hasModule($customer, (string) ApiOperationCatalog::module($operation))) {
            return 'module_unavailable';
        }

        return null;
    }
}
