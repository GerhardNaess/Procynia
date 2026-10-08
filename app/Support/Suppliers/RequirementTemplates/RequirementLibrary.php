<?php

namespace App\Support\Suppliers\RequirementTemplates;

/**
 * Kravbiblioteket — the one shared library the kravmaler pick from (docs/supplier-assurance-v2-plan.md
 * §16.1, §16.3). Lives in code, not in the database: nothing here is customer data, and a customer's
 * requirement made from an item is the customer's own row, which never reads this class again.
 *
 * Each item's key (E1, P1, …) is its stable identity: it is stored as template_item_key on the
 * requirement it becomes, and the same item is created at most once per customer, whichever template
 * brings it (partial unique index on (customer_id, template_item_key)). Never key on the title.
 *
 * Only the items the templates in RequirementTemplates use are here; later phases add the rest of
 * §16.3 with their templates. The values are the plan's table, unchanged:
 *  - level: one level per item (Obligatorisk · Viktig · Oppfølging, §7);
 *  - applies_when: the rule over SupplierProfilePredicates, as groups (DNF, §5.3);
 *  - control_point: FK = before_contract, L = ongoing, E = on_change; «L/E» (P2) is ongoing;
 *  - control_interval_months: «utløp» and «—» are null — controlled at the document's expiry, or
 *    without a fixed interval;
 *  - accepted_document_types: guidance, not a constraint.
 *
 * The title is in the lang files (supplier_management.templates.items.<KEY>.title). There is no
 * description, guidance or basis_text yet: the plan gives none, and legal or professional text is
 * added only after the content has been quality-assured (§19.2).
 */
final class RequirementLibrary
{
    /** @var array<string, array{theme: string, level: string, applies_when: list<list<string>>, control_point: string, control_interval_months: int|null, accepted_document_types: list<string>}> */
    public const ITEMS = [
        'E1' => [
            'theme' => 'ethics', 'level' => 'important', 'applies_when' => [],
            'control_point' => 'before_contract', 'control_interval_months' => 24, 'accepted_document_types' => ['code_of_conduct'],
        ],
        'E2' => [
            'theme' => 'ethics', 'level' => 'important', 'applies_when' => [['public_contract_terms'], ['sector:construction'], ['sector:cleaning'], ['sector:staffing']],
            'control_point' => 'before_contract', 'control_interval_months' => 12, 'accepted_document_types' => ['public_certificate'],
        ],
        'F1' => [
            'theme' => 'financial', 'level' => 'important', 'applies_when' => [['on_site_work'], ['criticality_critical']],
            'control_point' => 'before_contract', 'control_interval_months' => null, 'accepted_document_types' => ['insurance_certificate'],
        ],
        'F2' => [
            'theme' => 'financial', 'level' => 'important', 'applies_when' => [['hard_to_replace'], ['criticality_critical']],
            'control_point' => 'ongoing', 'control_interval_months' => 12, 'accepted_document_types' => ['financial_statement'],
        ],
        'Q1' => [
            'theme' => 'quality', 'level' => 'standard', 'applies_when' => [['criticality_important']],
            'control_point' => 'before_contract', 'control_interval_months' => null, 'accepted_document_types' => ['certificate', 'policy'],
        ],
        'L1' => [
            'theme' => 'labour_conditions', 'level' => 'mandatory', 'applies_when' => [['public_contract_terms']],
            'control_point' => 'before_contract', 'control_interval_months' => 12, 'accepted_document_types' => ['self_declaration'],
        ],
        'M1' => [
            'theme' => 'environment', 'level' => 'important', 'applies_when' => [['environmental_impact']],
            'control_point' => 'before_contract', 'control_interval_months' => null, 'accepted_document_types' => ['certificate', 'environmental_documentation'],
        ],
        'S1' => [
            'theme' => 'information_security', 'level' => 'important', 'applies_when' => [['stores_our_data', 'criticality_important']],
            'control_point' => 'ongoing', 'control_interval_months' => null, 'accepted_document_types' => ['certificate', 'audit_report'],
        ],
        'S2' => [
            'theme' => 'information_security', 'level' => 'mandatory', 'applies_when' => [['system_access']],
            'control_point' => 'before_contract', 'control_interval_months' => 12, 'accepted_document_types' => ['security_documentation', 'policy'],
        ],
        'S4' => [
            'theme' => 'information_security', 'level' => 'mandatory', 'applies_when' => [['system_access'], ['processor'], ['confidential_information']],
            'control_point' => 'before_contract', 'control_interval_months' => 24, 'accepted_document_types' => ['agreement', 'data_processing_agreement'],
        ],
        'S5' => [
            'theme' => 'information_security', 'level' => 'important', 'applies_when' => [['sector:ict', 'system_access']],
            'control_point' => 'ongoing', 'control_interval_months' => 12, 'accepted_document_types' => ['security_documentation'],
        ],
        'S6' => [
            'theme' => 'information_security', 'level' => 'important', 'applies_when' => [['stores_our_data']],
            'control_point' => 'before_contract', 'control_interval_months' => 24, 'accepted_document_types' => ['security_documentation'],
        ],
        'S8' => [
            'theme' => 'information_security', 'level' => 'important', 'applies_when' => [['stores_our_data']],
            'control_point' => 'before_contract', 'control_interval_months' => 24, 'accepted_document_types' => ['agreement', 'data_processing_agreement'],
        ],
        'P1' => [
            'theme' => 'privacy', 'level' => 'mandatory', 'applies_when' => [['processor']],
            'control_point' => 'before_contract', 'control_interval_months' => 24, 'accepted_document_types' => ['data_processing_agreement'],
        ],
        'P2' => [
            'theme' => 'privacy', 'level' => 'mandatory', 'applies_when' => [['processor']],
            'control_point' => 'ongoing', 'control_interval_months' => 12, 'accepted_document_types' => ['subcontractor_list'],
        ],
        'P3' => [
            'theme' => 'privacy', 'level' => 'important', 'applies_when' => [['processor'], ['stores_our_data']],
            'control_point' => 'ongoing', 'control_interval_months' => 12, 'accepted_document_types' => ['data_processing_agreement', 'security_documentation'],
        ],
        'P4' => [
            'theme' => 'privacy', 'level' => 'mandatory', 'applies_when' => [['personal_data', 'data_outside_eea']],
            'control_point' => 'before_contract', 'control_interval_months' => 12, 'accepted_document_types' => ['data_processing_agreement', 'other'],
        ],
        'P5' => [
            'theme' => 'privacy', 'level' => 'important', 'applies_when' => [['special_category_data']],
            'control_point' => 'before_contract', 'control_interval_months' => 12, 'accepted_document_types' => ['security_documentation'],
        ],
        'C1' => [
            'theme' => 'continuity', 'level' => 'important', 'applies_when' => [['critical_delivery']],
            'control_point' => 'ongoing', 'control_interval_months' => 12, 'accepted_document_types' => ['policy'],
        ],
    ];

    /** @return array{theme: string, level: string, applies_when: list<list<string>>, control_point: string, control_interval_months: int|null, accepted_document_types: list<string>}|null */
    public static function item(string $key): ?array
    {
        return self::ITEMS[$key] ?? null;
    }

    /** The item's title in the current language. */
    public static function title(string $key): string
    {
        return (string) __("procynia.supplier_management.templates.items.{$key}.title");
    }
}
