<?php

namespace App\Support;

/**
 * The closed list of write operations an integration can be allowed to perform through the API.
 *
 * An operation is narrow and named for what it does — never an existing edit key. It is reached
 * only when all three hold: the token carries the write app role (the ceiling), the customer's
 * System Owner has switched the operation on for the grant (api_client_customer_grants.
 * allowed_operations), and the domain's own access service says yes for this record
 * (IntegrationOperationAuthority is asked from there). Human decisions stay with HumanDecisionGuard
 * and are never operations.
 *
 * Adding an operation is adding a row here, the domain check that asks for it, and its label.
 */
final class ApiOperationCatalog
{
    public const RISK_TREATMENT_ACTION_CREATE = 'risk.treatment_action.create';

    /** Per operation: the module the customer must have ordered, and the label key in Kundemiljø. */
    private const OPERATIONS = [
        self::RISK_TREATMENT_ACTION_CREATE => [
            'module' => 'risk',
            'label' => 'procynia.customer_env.integrations.operations.risk_treatment_action_create',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::OPERATIONS);
    }

    public static function exists(string $operation): bool
    {
        return array_key_exists($operation, self::OPERATIONS);
    }

    public static function module(string $operation): ?string
    {
        return self::OPERATIONS[$operation]['module'] ?? null;
    }

    public static function labelKey(string $operation): ?string
    {
        return self::OPERATIONS[$operation]['label'] ?? null;
    }
}
