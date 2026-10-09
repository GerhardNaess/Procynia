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
 * exactly the template's mandatory items — the plan and the library cannot drift apart unnoticed. An
 * item has one level whichever template brings it (§16.3), so gates are the items mandatory in the
 * library: for Bemanning and Helse that is more than the plan's column names (L1; P2, P4, S2, S4),
 * which are mandatory items there as everywhere else.
 *
 * recommended_levels is the plan's «anbefalt» (§16.3): a stricter level the template suggests for an
 * item (E2 in Bygg og anlegg). It is shown on applying and never applied — the item is created at its
 * library level, and the customer decides whether to raise it.
 *
 * Name, purpose and the suppliers it suits are in the lang files
 * (supplier_management.templates.list.<key>).
 */
final class RequirementTemplates
{
    /**
     * Whether the templates' content has passed the professional and legal review (plan §19.2,
     * docs/supplier-assurance-template-review.md). Until it has, the templates stay available but
     * Kontrollkrav says plainly that they are not quality-assured. Set to true only in the commit
     * that records the signed review.
     */
    public const CONTENT_REVIEWED = false;

    public const PUBLIC_SECTOR_GENERAL = 'public_sector_general';

    public const IT_SAAS = 'it_saas';

    public const DATA_PROCESSOR = 'data_processor';

    public const HUMAN_RIGHTS_RISK = 'human_rights_risk';

    public const CRITICAL_ICT = 'critical_ict';

    public const CONSTRUCTION = 'construction';

    public const CLEANING = 'cleaning';

    public const STAFFING = 'staffing';

    public const HEALTH_CARE = 'health_care';

    /** @var array<string, array{version: string, items: list<string>, gates: list<string>, recommended_levels: array<string, string>}> */
    public const TEMPLATES = [
        self::PUBLIC_SECTOR_GENERAL => [
            'version' => '1',
            'items' => ['E1', 'E2', 'F1', 'F2', 'Q1', 'L1', 'M1'],
            'gates' => ['L1'],
            'recommended_levels' => [],
        ],
        self::IT_SAAS => [
            'version' => '1',
            'items' => ['S1', 'S2', 'S4', 'S5', 'S6', 'S8', 'P1', 'P2', 'P3', 'P4', 'C1'],
            'gates' => ['S2', 'S4', 'P1', 'P2', 'P4'],
            'recommended_levels' => [],
        ],
        self::DATA_PROCESSOR => [
            'version' => '1',
            'items' => ['P1', 'P2', 'P3', 'P4', 'P5', 'S4', 'S6', 'S8'],
            'gates' => ['P1', 'P2', 'P4', 'S4'],
            'recommended_levels' => [],
        ],
        // Mal 4 (phase 7). The plan's «+ aktsomhetsvurdering» is not an item: the assessment is its own
        // record (SupplierDueDiligenceService), expected by the same profile facts (signal 10).
        self::HUMAN_RIGHTS_RISK => [
            'version' => '1',
            'items' => ['H1', 'H2', 'H3', 'E1', 'M1'],
            'gates' => ['H1'],
            'recommended_levels' => [],
        ],
        // Mal 5–9 (phase 8). Kritisk IKT is mal 2 and six more items — the same items, not copies.
        self::CRITICAL_ICT => [
            'version' => '1',
            'items' => ['S1', 'S2', 'S4', 'S5', 'S6', 'S8', 'P1', 'P2', 'P3', 'P4', 'C1', 'S3', 'S7', 'C2', 'C3', 'F2', 'Q2'],
            'gates' => ['S2', 'S4', 'P1', 'P2', 'P4', 'S7'],
            'recommended_levels' => [],
        ],
        self::CONSTRUCTION => [
            'version' => '1',
            'items' => ['E2', 'L1', 'L2', 'L3', 'L4', 'L5', 'B1', 'F1', 'M1', 'M2'],
            'gates' => ['L1', 'L3'],
            'recommended_levels' => ['E2' => 'mandatory'],
        ],
        self::CLEANING => [
            'version' => '1',
            'items' => ['R1', 'E2', 'L1', 'L2', 'L3', 'L4', 'F1'],
            'gates' => ['R1', 'L1', 'L3'],
            'recommended_levels' => [],
        ],
        self::STAFFING => [
            'version' => '1',
            'items' => ['ST1', 'ST2', 'L1', 'L4', 'E2'],
            'gates' => ['ST1', 'L1'],
            'recommended_levels' => [],
        ],
        self::HEALTH_CARE => [
            'version' => '1',
            'items' => ['HE1', 'HE2', 'HE3', 'P1', 'P2', 'P4', 'P5', 'S2', 'S4'],
            'gates' => ['HE1', 'HE2', 'P1', 'P2', 'P4', 'S2', 'S4'],
            'recommended_levels' => [],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /** @return array{version: string, items: list<string>, gates: list<string>, recommended_levels: array<string, string>}|null */
    public static function find(string $key): ?array
    {
        return self::TEMPLATES[$key] ?? null;
    }

    public static function name(string $key): string
    {
        return (string) __("procynia.supplier_management.templates.list.{$key}.name");
    }
}
