<?php

namespace App\Support\Ai;

use InvalidArgumentException;

/**
 * Reads config/ai_operations.php: which feature an operation belongs to, which model performs it,
 * and how a historical operation or feature name maps onto today's standard.
 *
 * Deliberately static: AI clients expose their model through static helpers (prompt builders,
 * metadata labels) that run without a container-built instance.
 */
final class AiOperationCatalog
{
    public const FEATURE_SYSTEM = 'system';

    /** The model that performs an operation, falling back one segment at a time. */
    public static function model(string $operation): string
    {
        // Operation keys contain dots, so they are looked up in the array — never through config()'s
        // dot notation, which would split them.
        $operations = (array) config('ai_operations.operations', []);
        $key = $operation;

        while ($key !== '') {
            $model = trim((string) ($operations[$key]['model'] ?? ''));

            if ($model !== '') {
                return $model;
            }

            $cut = strrpos($key, '.');
            $key = $cut === false ? '' : substr($key, 0, $cut);
        }

        throw new InvalidArgumentException(sprintf('AI operation [%s] has no model in config/ai_operations.php.', $operation));
    }

    /** The feature is the operation's first segment — never stored separately from it. */
    public static function featureFor(string $operation): ?string
    {
        $feature = strstr($operation, '.', true);

        return $feature !== false && $feature !== '' ? $feature : null;
    }

    public static function isKnownFeature(?string $feature): bool
    {
        return $feature !== null && in_array($feature, (array) config('ai_operations.features', []), true);
    }

    /**
     * Whether an operation key follows `<feature>.<operation>` with a known feature. Registered
     * operations, job-level steps (`wiki.document_flow`) and operator commands all qualify.
     */
    public static function isWellFormed(?string $operation): bool
    {
        return is_string($operation)
            && preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $operation) === 1
            && self::isKnownFeature(self::featureFor($operation));
    }

    /** Translate a feature name found on a historical row into today's standard. */
    public static function canonicalFeature(?string $feature): ?string
    {
        if ($feature === null || $feature === '' || $feature === 'unclassified') {
            return null;
        }

        return (string) (((array) config('ai_operations.legacy_features', []))[$feature] ?? $feature);
    }

    /** Translate an operation key found on a historical row into today's standard. */
    public static function canonicalOperation(?string $operation): ?string
    {
        if ($operation === null || $operation === '' || $operation === 'unclassified') {
            return null;
        }

        $exact = config('ai_operations.legacy_operations', [])[$operation] ?? null;

        if (is_string($exact)) {
            return $exact;
        }

        foreach ((array) config('ai_operations.legacy_operation_prefixes', []) as $legacy => $current) {
            if (str_starts_with($operation, (string) $legacy)) {
                return $current.substr($operation, strlen((string) $legacy));
            }
        }

        return $operation;
    }

    public static function enforcementIsStrict(): bool
    {
        return config('ai_operations.context_enforcement', 'warn') === 'strict';
    }
}
