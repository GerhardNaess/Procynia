<?php

namespace Tests\Unit;

use App\Support\CustomerPermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Leverandøroppfølging ships in both languages with the same structure: its own strings (including
 * PageHelp with sections), the Tilganger labels of its four permissions and the domain, and the rail
 * and Styring labels. A key added to one language only fails here.
 */
class SupplierManagementTranslationsTest extends TestCase
{
    public function test_both_languages_carry_the_same_supplier_strings_and_every_permission_label(): void
    {
        $no = require dirname(__DIR__, 2).'/lang/no/procynia.php';
        $en = require dirname(__DIR__, 2).'/lang/en/procynia.php';

        $this->assertSame($this->keys($no['supplier_management']), $this->keys($en['supplier_management']));
        $this->assertNotEmpty($no['supplier_management']['help']['index']['sections']);

        foreach ([$no, $en] as $strings) {
            $roles = $strings['customer_env']['roles'];
            $this->assertNotSame('', $roles['domains'][CustomerPermissionCatalog::DOMAIN_SUPPLIER] ?? '');

            foreach (CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_SUPPLIER] as $key) {
                $this->assertNotSame('', $roles['permissions'][CustomerPermissionCatalog::translationKey($key)] ?? '', $key);
            }

            $this->assertNotSame('', $strings['navigation']['modules']['suppliers'] ?? '');

            // The template review notice sits beside the templates, not among them (2d369f0b).
            $templates = $strings['supplier_management']['templates'];
            $this->assertIsString($templates['unreviewed'] ?? null);
            foreach ($templates['list'] as $key => $template) {
                $this->assertIsArray($template, "templates.list.{$key}");
                $this->assertNotSame('', $template['name'] ?? '', "templates.list.{$key}.name");
            }
            $this->assertNotSame('', $strings['governance']['descriptions']['suppliers'] ?? '');
        }

        $this->assertSame(
            ['Leverandøroppfølging', 'Leverandører', 'Leverandører'],
            [$no['supplier_management']['module_name'], $no['supplier_management']['index_heading'], $no['navigation']['modules']['suppliers']],
        );
    }

    /**
     * The register's PageHelp is a guide: «Hva brukes siden til?», then «Slik gjør du det» with seven
     * numbered steps from finding a supplier to its tasks in Oppfølging, the same in both languages,
     * naming the buttons and tabs the pages use.
     */
    public function test_the_register_help_opens_with_numbered_steps_in_both_languages(): void
    {
        $no = (require dirname(__DIR__, 2).'/lang/no/procynia.php')['supplier_management'];
        $en = (require dirname(__DIR__, 2).'/lang/en/procynia.php')['supplier_management'];

        foreach ([$no, $en] as $strings) {
            $help = $strings['help']['index'];
            $this->assertNotSame('', $help['intro_title'] ?? '');
            $this->assertSame(
                ['1.', '2.', '3.', '4.', '5.', '6.', '7.'],
                array_map(fn (array $item): string => strtok($item['title'], ' '), $help['sections'][0]['items']),
            );
        }

        $this->assertSame(['Hva brukes siden til?', 'Slik gjør du det'], [$no['help']['index']['intro_title'], $no['help']['index']['sections'][0]['title']]);

        $steps = implode(' ', array_column($no['help']['index']['sections'][0]['items'], 'text'));
        foreach ([
            $no['search'], $no['reset'], $no['create'], $no['import']['button'], $no['status_filter_all'],
            $no['criticality']['assess'], $no['profile']['fill'], $no['assessment']['assess'], $no['control']['evaluate'],
            $no['page']['tabs']['requirements'], $no['page']['tabs']['assessments'], $no['page']['tabs']['documents'],
            $no['attention']['heading'], $no['attention']['filter'],
        ] as $label) {
            $this->assertStringContainsString("«{$label}»", $steps);
        }
    }

    /**
     * The supplier page's PageHelp is a guide too — and since the kontrollbeslutning was retired
     * (2026-10-12) no help text in Leverandøroppfølging asks anyone to register a decision or says a
     * supplier requires one.
     */
    public function test_the_supplier_page_help_is_a_guide_and_no_help_asks_for_a_decision(): void
    {
        $no = (require dirname(__DIR__, 2).'/lang/no/procynia.php')['supplier_management'];
        $en = (require dirname(__DIR__, 2).'/lang/en/procynia.php')['supplier_management'];

        foreach ([$no, $en] as $strings) {
            $help = $strings['help']['supplier'];
            $this->assertNotSame('', $help['intro_title'] ?? '');
            $this->assertSame(
                ['1.', '2.', '3.', '4.', '5.', '6.'],
                array_map(fn (array $item): string => strtok($item['title'], ' '), $help['sections'][0]['items']),
            );
        }

        $this->assertSame(['Hva brukes siden til?', 'Slik gjør du det'], [$no['help']['supplier']['intro_title'], $no['help']['supplier']['sections'][0]['title']]);

        $steps = implode(' ', array_column($no['help']['supplier']['sections'][0]['items'], 'text'));
        foreach ([
            $no['page']['tabs']['overview'], $no['page']['tabs']['requirements'], $no['page']['tabs']['documents'], $no['page']['tabs']['assessments'],
            $no['attention']['heading'], $no['attention']['go_to_control'], $no['criticality']['assess'], $no['profile']['fill'],
            $no['documents']['add'], $no['control']['evaluate'], $no['assessment']['assess'],
            $no['activate'], $no['end'], $no['reopen'], $no['assurance']['activate_anyway'],
            // The redesigned Oversikt (2026-10-12): its areas and the Detaljer sections by their headings.
            $no['details_heading'], $no['page']['overview']['details_heading'], $no['page']['overview']['other_actions_heading'],
            $no['page']['overview']['info_heading'], $no['criticality']['heading'], $no['profile']['heading'],
            $no['page']['overview']['risks_heading'], $no['page']['overview']['cases_heading'], $no['page']['overview']['next_control'],
        ] as $label) {
            $this->assertStringContainsString("«{$label}»", $steps);
        }

        foreach (['no' => $no, 'en' => $en] as $locale => $strings) {
            $text = json_encode($strings['help'], JSON_UNESCAPED_UNICODE);
            foreach (['Registrer beslutning', 'Register decision', 'Krever beslutning', 'Requires decision', 'Decision required', 'krever beslutning', 'requires a decision'] as $retired) {
                $this->assertStringNotContainsString($retired, $text, "{$locale}: {$retired}");
            }
        }
    }

    /** @return list<string> */
    private function keys(array $strings, string $prefix = ''): array
    {
        $keys = [];

        foreach ($strings as $key => $value) {
            $path = $prefix.$key;
            $keys = [...$keys, ...(is_array($value) ? $this->keys($value, $path.'.') : [$path])];
        }

        sort($keys);

        return $keys;
    }
}
