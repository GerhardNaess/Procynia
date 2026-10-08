<?php

namespace App\Support\Suppliers\RequirementTemplates;

/**
 * Kravmalene (docs/supplier-assurance-v2-plan.md §16.1, §16.2): a template is a chosen set of items
 * from RequirementLibrary — a starting point for the customer's Kontrollkrav, never an approval, an
 * assessment or a score. Applying one fills the customer's catalogue; it is never assigned to a
 * supplier. Which suppliers each requirement applies to is decided by its rule against the profile,
 * as for any other requirement.
 *
 * The key is the template's stable identity (stored as template_key on the requirements it creates),
 * and version is stored as template_version. A later version never changes what a customer already
 * has: there is no update path from a template to an existing requirement (§16.1, §18).
 *
 * gates are the plan's «Gates» column, kept here so the structure test can check that they are
 * exactly the template's mandatory items — the plan and the library cannot drift apart unnoticed.
 *
 * Name, purpose and the suppliers it suits are in the lang files
 * (supplier_management.templates.list.<key>).
 */
final class RequirementTemplates
{
    public const PUBLIC_SECTOR_GENERAL = 'public_sector_general';

    public const IT_SAAS = 'it_saas';

    public const DATA_PROCESSOR = 'data_processor';

    public const HUMAN_RIGHTS_RISK = 'human_rights_risk';

    /** @var array<string, array{version: string, items: list<string>, gates: list<string>}> */
    public const TEMPLATES = [
        self::PUBLIC_SECTOR_GENERAL => [
            'version' => '1',
            'items' => ['E1', 'E2', 'F1', 'F2', 'Q1', 'L1', 'M1'],
            'gates' => ['L1'],
        ],
        self::IT_SAAS => [
            'version' => '1',
            'items' => ['S1', 'S2', 'S4', 'S5', 'S6', 'S8', 'P1', 'P2', 'P3', 'P4', 'C1'],
            'gates' => ['S2', 'S4', 'P1', 'P2', 'P4'],
        ],
        self::DATA_PROCESSOR => [
            'version' => '1',
            'items' => ['P1', 'P2', 'P3', 'P4', 'P5', 'S4', 'S6', 'S8'],
            'gates' => ['P1', 'P2', 'P4', 'S4'],
        ],
        // Mal 4 (phase 7). The plan's «+ aktsomhetsvurdering» is not an item: the assessment is its own
        // record (SupplierDueDiligenceService), expected by the same profile facts (signal 10).
        self::HUMAN_RIGHTS_RISK => [
            'version' => '1',
            'items' => ['H1', 'H2', 'H3', 'E1', 'M1'],
            'gates' => ['H1'],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /** @return array{version: string, items: list<string>, gates: list<string>}|null */
    public static function find(string $key): ?array
    {
        return self::TEMPLATES[$key] ?? null;
    }

    public static function name(string $key): string
    {
        return (string) __("procynia.supplier_management.templates.list.{$key}.name");
    }
}
