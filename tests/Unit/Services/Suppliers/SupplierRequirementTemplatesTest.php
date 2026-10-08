<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Services\Suppliers\Assurance\SupplierProfilePredicates;
use App\Services\Suppliers\Assurance\SupplierRequirementApplicability as Applicability;
use App\Services\Suppliers\Assurance\SupplierRequirementRule;
use App\Support\Suppliers\RequirementTemplates\RequirementLibrary;
use App\Support\Suppliers\RequirementTemplates\RequirementTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kravmalene's structure, not their wording (docs/supplier-assurance-v2-plan.md §16): every item has a
 * stable key, a valid level, theme, control point, interval, document types and a rule over the
 * fixed predicates; every template refers only to library items, and its gates are exactly its
 * mandatory items (the plan's «Gates» column); every title and template text exists in NO and EN.
 * Whether the content is professionally or legally right is not something a test can say.
 *
 * Then, table-driven, which items of a template apply to a few typical suppliers — through the same
 * SupplierRequirementApplicability::decide() every requirement goes through.
 */
class SupplierRequirementTemplatesTest extends TestCase
{
    public function test_the_library_and_the_templates_are_well_formed(): void
    {
        foreach (RequirementLibrary::ITEMS as $key => $item) {
            $this->assertMatchesRegularExpression('/^[A-Z]{1,2}[0-9]{1,2}$/', $key);
            $this->assertSame(['theme', 'level', 'applies_when', 'control_point', 'control_interval_months', 'accepted_document_types'], array_keys($item), $key);
            $this->assertContains($item['theme'], SupplierControlRequirement::THEMES, $key);
            $this->assertContains($item['level'], SupplierControlRequirement::LEVELS, $key);
            $this->assertContains($item['control_point'], SupplierControlRequirement::CONTROL_POINTS, $key);
            $this->assertTrue($item['control_interval_months'] === null || in_array($item['control_interval_months'], SupplierControlRequirement::CONTROL_INTERVALS, true), $key);
            $this->assertTrue(SupplierRequirementRule::isValid($item['applies_when']), $key);
            $this->assertNotSame([], $item['accepted_document_types'], $key);
            $this->assertSame(array_values(array_unique($item['accepted_document_types'])), $item['accepted_document_types'], $key);
            $this->assertSame([], array_diff($item['accepted_document_types'], SupplierDocument::TYPES), $key);
        }

        $used = [];

        $this->assertSame([RequirementTemplates::PUBLIC_SECTOR_GENERAL, RequirementTemplates::IT_SAAS, RequirementTemplates::DATA_PROCESSOR, RequirementTemplates::HUMAN_RIGHTS_RISK], RequirementTemplates::keys());

        foreach (RequirementTemplates::TEMPLATES as $key => $template) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key);
            $this->assertNotSame('', $template['version'], $key);
            $this->assertSame(array_values(array_unique($template['items'])), $template['items'], $key);
            $this->assertSame([], array_diff($template['items'], array_keys(RequirementLibrary::ITEMS)), $key);
            $mandatory = array_values(array_filter($template['items'], fn (string $item): bool => RequirementLibrary::ITEMS[$item]['level'] === SupplierControlRequirement::LEVEL_MANDATORY));
            $this->assertEqualsCanonicalizing($template['gates'], $mandatory, "{$key}: the gates are the mandatory items");
            $used = [...$used, ...$template['items']];
        }

        // Nothing in the library that no template brings.
        $this->assertEqualsCanonicalizing(array_keys(RequirementLibrary::ITEMS), array_values(array_unique($used)));

        foreach (['no', 'en'] as $language) {
            $texts = (require dirname(__DIR__, 4)."/lang/{$language}/procynia.php")['supplier_management']['templates'];
            $this->assertEqualsCanonicalizing(array_keys(RequirementLibrary::ITEMS), array_keys($texts['items']), $language);
            $this->assertEqualsCanonicalizing(RequirementTemplates::keys(), array_keys($texts['list']), $language);

            foreach ($texts['items'] as $key => $item) {
                $this->assertNotSame('', trim($item['title'] ?? ''), "{$language} {$key}");
            }

            foreach ($texts['list'] as $key => $template) {
                foreach (['name', 'purpose', 'suited_for'] as $field) {
                    $this->assertNotSame('', trim($template[$field] ?? ''), "{$language} {$key}.{$field}");
                }
            }
        }
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>|null, 3: list<string>}> */
    public static function typicalSuppliers(): array
    {
        return [
            'IT/SaaS: databehandler with data in EØS, Viktig' => [
                RequirementTemplates::IT_SAAS,
                ['criticality' => 'important', 'processes_personal_data' => true, 'has_system_access' => true],
                ['data_role' => 'processor', 'special_category_data' => 'no', 'stores_our_data' => 'yes', 'data_location' => 'eea', 'confidential_information' => 'no', 'privileged_access' => 'no', 'uses_subcontractors' => 'no', 'sectors' => ['ict']],
                ['S1', 'S2', 'S4', 'S5', 'S6', 'S8', 'P1', 'P2', 'P3'],
            ],
            // Plan §12: no system access, no data, no personal data — none of these requirements.
            'IT/SaaS: no access, no data' => [
                RequirementTemplates::IT_SAAS,
                ['criticality' => 'important'],
                ['stores_our_data' => 'no', 'confidential_information' => 'no', 'uses_subcontractors' => 'no', 'sectors' => ['goods']],
                [],
            ],
            'Databehandler: data outside EØS, special categories not clarified' => [
                RequirementTemplates::DATA_PROCESSOR,
                ['processes_personal_data' => true],
                ['data_role' => 'processor', 'special_category_data' => 'unknown', 'stores_our_data' => 'yes', 'data_location' => 'outside_eea', 'confidential_information' => 'no'],
                ['P1', 'P2', 'P3', 'P4', 'P5', 'S4', 'S6', 'S8'],
            ],
            'Databehandler: own controller, no data of ours' => [
                RequirementTemplates::DATA_PROCESSOR,
                ['processes_personal_data' => true],
                ['data_role' => 'controller', 'special_category_data' => 'no', 'stores_our_data' => 'no', 'confidential_information' => 'no'],
                [],
            ],
            // A wholly empty profile only yields the criticality facts (plan §5.3).
            'Generell: no profile, Standard' => [RequirementTemplates::PUBLIC_SECTOR_GENERAL, [], null, ['E1']],
            'Generell: no profile, Kritisk' => [RequirementTemplates::PUBLIC_SECTOR_GENERAL, ['criticality' => 'critical'], null, ['E1', 'F1', 'F2', 'Q1']],
            'Generell: public contract terms, on site' => [
                RequirementTemplates::PUBLIC_SECTOR_GENERAL,
                [],
                ['public_contract_terms' => 'yes', 'on_site_work' => 'yes', 'significant_environmental_impact' => 'no'],
                ['E1', 'E2', 'F1', 'L1'],
            ],
            // Mal 4 (phase 7).
            'Menneskerettighetsrisiko: textiles, Standard' => [
                RequirementTemplates::HUMAN_RIGHTS_RISK,
                [],
                ['high_risk_categories' => ['textiles'], 'production_outside_eea' => 'no', 'significant_environmental_impact' => 'no'],
                ['H1', 'H2', 'E1'],
            ],
            'Menneskerettighetsrisiko: textiles, Viktig, environmental impact' => [
                RequirementTemplates::HUMAN_RIGHTS_RISK,
                ['criticality' => 'important'],
                ['high_risk_categories' => ['textiles'], 'production_outside_eea' => 'yes', 'significant_environmental_impact' => 'yes'],
                ['H1', 'H2', 'H3', 'E1', 'M1'],
            ],
            'Menneskerettighetsrisiko: production outside EEA not clarified, no category' => [
                RequirementTemplates::HUMAN_RIGHTS_RISK,
                ['criticality' => 'important'],
                ['high_risk_categories' => [], 'production_outside_eea' => 'unknown', 'significant_environmental_impact' => 'no'],
                ['H1', 'E1'],
            ],
            'Menneskerettighetsrisiko: none of it' => [
                RequirementTemplates::HUMAN_RIGHTS_RISK,
                [],
                ['high_risk_categories' => [], 'production_outside_eea' => 'no', 'significant_environmental_impact' => 'no'],
                ['E1'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $supplierAttributes
     * @param  array<string, mixed>|null  $answers
     * @param  list<string>  $expected
     */
    #[DataProvider('typicalSuppliers')]
    public function test_which_template_items_apply_to_a_typical_supplier(string $templateKey, array $supplierAttributes, ?array $answers, array $expected): void
    {
        $supplier = (new Supplier)->forceFill($supplierAttributes + [
            'id' => 1,
            'customer_id' => 1,
            'criticality' => 'standard',
            'processes_personal_data' => false,
            'has_system_access' => false,
            'supports_critical_delivery' => false,
            'hard_to_replace' => false,
        ]);
        $answers = $answers !== null ? $answers + SupplierProfile::emptyAnswers() : null;
        $facts = SupplierProfilePredicates::evaluate($supplier, $answers);
        $uncertain = SupplierProfilePredicates::uncertain($supplier, $answers);

        $applies = array_values(array_filter(RequirementTemplates::TEMPLATES[$templateKey]['items'], function (string $key) use ($supplier, $facts, $uncertain): bool {
            $requirement = (new SupplierControlRequirement)->forceFill(RequirementLibrary::ITEMS[$key] + [
                'id' => 1, 'customer_id' => 1, 'title' => $key, 'status' => 'active', 'supplier_id' => null,
            ]);

            return Applicability::decide($requirement, $supplier, $facts, $uncertain, null)['applies'];
        }));

        $this->assertSame($expected, $applies);
    }
}
