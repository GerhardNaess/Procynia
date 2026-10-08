<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\SupplierRequirementOverride;

/**
 * «Gjelder fordi …» in words (docs/supplier-assurance-v2-plan.md §5.2): what a person reads, never
 * a predicate name. Exactly one primary reason per applying requirement, in the plan's priority:
 * for this supplier · included manually · the first holding group of the rule (the others under
 * «Også fordi …») · all suppliers.
 *
 * A fact that holds only because an answer is «Ikke avklart» or not answered says so («det er ikke
 * avklart om …») instead of stating it as known.
 */
class SupplierRequirementReasonText
{
    private const KEY = 'procynia.supplier_management.control';

    /**
     * @param  array<string, mixed>  $decision  SupplierRequirementApplicability::decide()
     * @return array{text: string, note: string|null, also: list<string>}
     */
    public function because(array $decision): array
    {
        $groups = $decision['groups'];

        return match ($decision['source']) {
            SupplierRequirementApplicability::SOURCE_SUPPLIER_SPECIFIC => $this->reason(__(self::KEY.'.reasons.supplier_specific')),
            SupplierRequirementApplicability::SOURCE_MANUAL_INCLUDE => $this->reason($this->override('included', $decision['override']), $decision['override']->reason),
            SupplierRequirementApplicability::SOURCE_RULE => $this->reason(
                __(self::KEY.'.reasons.because', ['reasons' => $this->group($groups[0] ?? [])]),
                also: array_map(fn (array $group): string => $this->group($group), array_slice($groups, 1)),
            ),
            SupplierRequirementApplicability::SOURCE_ALL_SUPPLIERS => $this->reason(__(self::KEY.'.reasons.all_suppliers')),
            default => $this->reason(''),
        };
    }

    /**
     * Why an excluded requirement does not apply: «Kari Hansen utelukket kravet 08.10.2026» and the
     * begrunnelse. Null for a requirement whose rule simply does not hold — rule() says when it would.
     *
     * @param  array<string, mixed>  $decision
     * @return array{text: string, note: string|null, also: list<string>}|null
     */
    public function notApplying(array $decision): ?array
    {
        return $decision['source'] === SupplierRequirementApplicability::SOURCE_MANUAL_EXCLUDE
            ? $this->reason($this->override('excluded', $decision['override']), $decision['override']->reason)
            : null;
    }

    /**
     * When a requirement applies, in words: «Gjelder alle leverandører», or «Gjelder når …» with the
     * groups joined by «eller».
     *
     * @param  list<list<string>>  $rule
     */
    public function rule(array $rule): string
    {
        if ($rule === []) {
            return __(self::KEY.'.reasons.all_suppliers');
        }

        $groups = array_map(
            fn (array $group): string => $this->group(array_map(fn (string $predicate): array => ['predicate' => $predicate, 'uncertain' => false], $group)),
            $rule,
        );

        return __(self::KEY.'.reasons.when', ['conditions' => implode(__(self::KEY.'.reasons.or'), $groups)]);
    }

    /** One predicate as a person reads it, for the condition choices in the form. */
    public function predicate(string $predicate): string
    {
        return $this->phrase($predicate, false);
    }

    /** What an override did, for the history: «Kravet ble inkludert manuelt». */
    public function action(string $action): string
    {
        return __(self::KEY.'.history.'.$action);
    }

    /** @param  list<array{predicate: string, uncertain: bool}>  $group */
    private function group(array $group): string
    {
        $phrases = array_map(fn (array $fact): string => $this->phrase($fact['predicate'], $fact['uncertain']), $group);
        $last = array_pop($phrases);

        return $phrases === [] ? (string) $last : implode(', ', $phrases).__(self::KEY.'.reasons.and').$last;
    }

    private function phrase(string $predicate, bool $uncertain): string
    {
        if (str_starts_with($predicate, SupplierProfilePredicates::SECTOR_PREFIX)) {
            $sector = substr($predicate, strlen(SupplierProfilePredicates::SECTOR_PREFIX));

            return __(self::KEY.'.predicates.sector', ['sector' => __('procynia.supplier_management.profile.sectors.'.$sector)]);
        }

        return __(self::KEY.($uncertain ? '.uncertain.' : '.predicates.').$predicate);
    }

    private function override(string $what, SupplierRequirementOverride $override): string
    {
        return __(self::KEY.'.reasons.'.$what, [
            'name' => $override->createdBy?->name ?? __('procynia.supplier_management.unknown_user'),
            'date' => $override->created_at?->format('d.m.Y'),
        ]);
    }

    /**
     * @param  list<string>  $also
     * @return array{text: string, note: string|null, also: list<string>}
     */
    private function reason(string $text, ?string $note = null, array $also = []): array
    {
        return ['text' => $text, 'note' => $note, 'also' => $also];
    }
}
