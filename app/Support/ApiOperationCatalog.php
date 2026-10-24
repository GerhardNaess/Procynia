<?php

namespace App\Support;

/**
 * The closed list of operations an integration can be allowed to perform through the API, beyond
 * reading through the permissions in its roles (CustomerPermissionCatalog).
 *
 * An operation is narrow and named for what it does — never an existing edit key. It is reached
 * only when all of these hold: the customer's System Owner has switched the operation on for the
 * grant (api_client_customer_grants.allowed_operations), the domain's own access service says yes
 * (IntegrationOperationAuthority is asked from there), and — for a write — the token carries the
 * write app role (the ceiling). Human decisions stay with HumanDecisionGuard and are never
 * operations.
 *
 * Most operations write. «Spør Wiki» (D19) does not: it reads published Wiki knowledge but spends
 * the customer's AI capacity, which is why it is a choice per grant and not part of reading. It
 * needs no write role, because it writes nothing.
 *
 * Reading Anbud (D23) is an operation too, because Anbud has no permission in the catalog: people
 * reach cases through bid_role, which an integration never holds. Switching it on reads the whole
 * customer's tender portfolio, so it is the System Owner's explicit choice per grant.
 *
 * Adding an operation is adding a row here, the domain check that asks for it, and its label.
 */
final class ApiOperationCatalog
{
    public const RISK_TREATMENT_ACTION_CREATE = 'risk.treatment_action.create';

    /** Registering an avvik or a forbedringsforslag. It is created open; everything after is people's. */
    public const IMPROVEMENT_CASE_CREATE = 'improvement.case.create';

    /** Adding a planned tiltak to an open or ongoing case. Working, completing or verifying it is not. */
    public const IMPROVEMENT_ACTION_CREATE = 'improvement.action.create';

    /** «Spør Wiki» over published Wiki knowledge (D18, D19). Reads, and spends AI capacity. */
    public const WIKI_ASK = 'wiki.ask';

    /** Reading every tender case of the customer and its requirements' metadata (D23, D24). */
    public const TENDER_READ = 'tender.read';

    /**
     * Per operation: the module the customer must have ordered, the label key in Kundemiljø, and
     * whether it writes (and so needs the write app role).
     */
    private const OPERATIONS = [
        self::RISK_TREATMENT_ACTION_CREATE => [
            'module' => 'risk',
            'label' => 'procynia.customer_env.integrations.operations.risk_treatment_action_create',
        ],
        self::IMPROVEMENT_CASE_CREATE => [
            'module' => 'improvements',
            'label' => 'procynia.customer_env.integrations.operations.improvement_case_create',
        ],
        self::IMPROVEMENT_ACTION_CREATE => [
            'module' => 'improvements',
            'label' => 'procynia.customer_env.integrations.operations.improvement_action_create',
        ],
        self::WIKI_ASK => [
            'module' => 'wiki',
            'label' => 'procynia.customer_env.integrations.operations.wiki_ask',
            'writes' => false,
        ],
        self::TENDER_READ => [
            'module' => 'tender',
            'label' => 'procynia.customer_env.integrations.operations.tender_read',
            'writes' => false,
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

    /** Whether the operation writes, and so needs the write app role. Unknown operations do. */
    public static function requiresWriteRole(string $operation): bool
    {
        return (bool) (self::OPERATIONS[$operation]['writes'] ?? true);
    }
}
