<?php

namespace App\Services\Suppliers\Assurance;

/**
 * A control requirement's rule, applies_when (docs/supplier-assurance-v2-plan.md §5.3): a list of
 * groups over the fixed SupplierProfilePredicates. It applies when at least one group has all its
 * predicates true; [] applies to every supplier. Not an expression language — two levels, fixed
 * names, at most 6 groups of at most 4.
 *
 * The form writes it the simple way: «Alle leverandører» or «Når ett av disse gjelder» (one
 * predicate per group), optionally «Bare for Viktig og Kritisk» / «Bare for Kritisk», which is added
 * to every group. One representation, two ways of writing it; the full form is for the templates.
 */
final class SupplierRequirementRule
{
    public const MAX_GROUPS = 6;

    public const MAX_PREDICATES = 4;

    public const MODE_ALL = 'all';

    public const MODE_CONDITIONS = 'conditions';

    public const SCOPE_IMPORTANT = 'important';

    public const SCOPE_CRITICAL = 'critical';

    private const SCOPE_PREDICATES = [
        self::SCOPE_IMPORTANT => 'criticality_important',
        self::SCOPE_CRITICAL => 'criticality_critical',
    ];

    /**
     * The predicates the form offers as conditions: all of them but the two criticality levels,
     * which are the separate «Bare for …» choice.
     *
     * @return list<string>
     */
    public static function conditions(): array
    {
        return array_values(array_diff(SupplierProfilePredicates::all(), self::SCOPE_PREDICATES));
    }

    public static function isValid(mixed $rule): bool
    {
        if (! is_array($rule) || ! array_is_list($rule) || count($rule) > self::MAX_GROUPS) {
            return false;
        }

        $known = SupplierProfilePredicates::all();

        foreach ($rule as $group) {
            if (! is_array($group) || ! array_is_list($group) || $group === [] || count($group) > self::MAX_PREDICATES) {
                return false;
            }

            foreach ($group as $predicate) {
                if (! is_string($predicate) || ! in_array($predicate, $known, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The rule as the form writes it.
     *
     * @param  list<string>  $conditions
     * @return list<list<string>>
     */
    public static function fromForm(string $mode, array $conditions, ?string $scope): array
    {
        $scoped = self::SCOPE_PREDICATES[$scope ?? ''] ?? null;

        if ($mode === self::MODE_ALL) {
            return $scoped !== null ? [[$scoped]] : [];
        }

        $conditions = array_values(array_intersect(self::conditions(), $conditions));

        return array_map(fn (string $condition): array => $scoped !== null ? [$condition, $scoped] : [$condition], $conditions);
    }

    /**
     * The form's fields for a stored rule, or null when the rule is not one the form writes.
     *
     * @param  list<list<string>>  $rule
     * @return array{rule_mode: string, conditions: list<string>, criticality_scope: string}|null
     */
    public static function toForm(array $rule): ?array
    {
        if ($rule === []) {
            return ['rule_mode' => self::MODE_ALL, 'conditions' => [], 'criticality_scope' => ''];
        }

        foreach (self::SCOPE_PREDICATES as $scope => $predicate) {
            if ($rule === [[$predicate]]) {
                return ['rule_mode' => self::MODE_ALL, 'conditions' => [], 'criticality_scope' => $scope];
            }
        }

        $scope = '';
        $conditions = [];

        foreach ($rule as $index => $group) {
            $groupScope = '';
            $rest = [];

            foreach ($group as $predicate) {
                $found = array_search($predicate, self::SCOPE_PREDICATES, true);
                $found !== false ? $groupScope = $found : $rest[] = $predicate;
            }

            if (count($rest) !== 1 || ($index > 0 && $groupScope !== $scope)) {
                return null;
            }

            $scope = $groupScope;
            $conditions[] = $rest[0];
        }

        return self::fromForm(self::MODE_CONDITIONS, $conditions, $scope) === $rule
            ? ['rule_mode' => self::MODE_CONDITIONS, 'conditions' => $conditions, 'criticality_scope' => $scope]
            : null;
    }
}
