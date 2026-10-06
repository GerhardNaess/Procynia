<?php

namespace Tests\Unit;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: Etterlevelse og revisjon must not ship without PageHelp. The Krav register and the
 * requirement page each have help with sections in both languages, built the same way, and the
 * help names what the pages actually show — the statuses, the source, the owner, the review
 * interval and the status history — under the labels the pages use. A label renamed without the
 * help following it fails here. Both languages also carry exactly the same keys.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class CompliancePageHelpTranslationsTest extends TestCase
{
    private const PAGES = ['index', 'requirement'];

    public function test_every_page_has_help_with_sections_in_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->compliance($locale)['help'] ?? null;
            $this->assertIsArray($help, "Missing compliance.help in lang/{$locale}/procynia.php.");
            $this->assertNotSame('', trim((string) ($help['button'] ?? '')));

            foreach (self::PAGES as $page) {
                $content = $help[$page] ?? null;
                $this->assertIsArray($content, "Missing compliance.help.{$page} in lang/{$locale}.");
                $this->assertNotSame('', trim((string) ($content['title'] ?? '')));
                $this->assertNotSame('', trim((string) ($content['intro'] ?? '')));
                $this->assertNotEmpty($content['sections'] ?? []);

                foreach ($content['sections'] as $section) {
                    $this->assertNotSame('', trim((string) ($section['title'] ?? '')));
                    $this->assertNotEmpty($section['items'] ?? []);

                    foreach ($section['items'] as $item) {
                        $this->assertNotSame('', trim((string) ($item['title'] ?? '')));
                        $this->assertNotSame('', trim((string) ($item['text'] ?? '')));
                    }
                }
            }
        }
    }

    public function test_both_languages_have_the_same_help_structure(): void
    {
        $shape = fn (array $help): array => array_map(
            fn (array $content): array => array_map(fn (array $section): int => count($section['items']), $content['sections']),
            array_intersect_key($help, array_flip(self::PAGES)),
        );

        $this->assertSame($shape($this->compliance('no')['help']), $shape($this->compliance('en')['help']));
    }

    public function test_both_languages_expose_the_same_keys(): void
    {
        $this->assertSame($this->keys($this->compliance('no')), $this->keys($this->compliance('en')));
    }

    public function test_the_register_help_explains_requirement_source_compliance_and_audit(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $titles = $this->itemTitles($strings['help']['index']);
            $text = $this->allText($strings['help']['index']);

            $this->assertContains($strings['field_reference'], $titles, "The register help in lang/{$locale} does not explain «{$strings['field_reference']}».");

            foreach (ComplianceRequirement::STATUSES as $status) {
                $this->assertContains($strings['statuses'][$status], $titles, "The register help in lang/{$locale} does not explain «{$strings['statuses'][$status]}».");
            }

            // What a requirement is, what a source is, that compliance is assessed separately, and
            // how a requirement differs from an audit — one item each.
            $this->assertCount(2, array_filter($titles, fn (string $title): bool => in_array($title, $locale === 'no' ? ['Krav', 'Kravkilde'] : ['Requirement', 'Requirement source'], true)));
            $this->assertCount(2, array_filter($titles, fn (string $title): bool => in_array($title, $locale === 'no' ? ['Krav og etterlevelse', 'Krav og revisjon'] : ['Requirements and compliance', 'Requirements and audits'], true)));
            $this->assertStringContainsString($strings['sources']['heading'], $text, "The register help in lang/{$locale} does not point to «{$strings['sources']['heading']}».");
        }
    }

    public function test_the_requirement_help_explains_owner_interval_status_and_history_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);
            $titles = $this->itemTitles($strings['help']['requirement']);
            $sectionTitles = array_column($strings['help']['requirement']['sections'], 'title');

            foreach (['field_owner', 'field_review', 'no_owner', 'reopen'] as $key) {
                $this->assertContains($strings[$key], $titles, "The requirement help in lang/{$locale} does not explain «{$strings[$key]}».");
            }

            foreach (ComplianceRequirement::STATUSES as $status) {
                $this->assertContains($strings['statuses'][$status], $titles, "The requirement help in lang/{$locale} does not explain «{$strings['statuses'][$status]}».");
            }

            $this->assertContains($strings['history_heading'], $sectionTitles, "The requirement help in lang/{$locale} has no «{$strings['history_heading']}» section.");
        }
    }

    public function test_the_value_labels_cover_exactly_the_values_that_exist(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->compliance($locale);

            $this->assertSame(ComplianceRequirement::STATUSES, array_keys($strings['statuses']));
            $this->assertSame(ComplianceSource::KINDS, array_keys($strings['kinds']));
            $this->assertSame(['none', ...ComplianceRequirement::REVIEW_INTERVALS], array_keys($strings['review_intervals']));
            $this->assertSame(['retired', 'active'], array_keys($strings['history']));
        }
    }

    public function test_the_norwegian_ui_uses_the_agreed_domain_terms(): void
    {
        $no = $this->compliance('no');

        $this->assertSame('Etterlevelse og revisjon', $no['module_name']);
        $this->assertSame('Krav', $no['index_heading']);
        $this->assertSame(['active' => 'Aktiv', 'retired' => 'Utgått'], $no['statuses']);
        $this->assertSame(
            ['standard' => 'Standard', 'law' => 'Lov/forskrift', 'contract' => 'Kontrakt', 'internal' => 'Internt krav', 'other' => 'Annet'],
            $no['kinds'],
        );
        $this->assertSame(
            ['none' => 'Ingen fast intervall', 1 => 'Månedlig', 3 => 'Kvartalsvis', 6 => 'Halvårlig', 12 => 'Årlig'],
            $no['review_intervals'],
        );
        $this->assertSame(
            ['Referanse', 'Krav', 'Kravkilde', 'Ansvarlig', 'Revurdering', 'Status'],
            [$no['col_reference'], $no['col_requirement'], $no['col_source'], $no['col_owner'], $no['col_review'], $no['col_status']],
        );
        $this->assertSame(
            ['Sett som utgått', 'Gjenåpne', 'Statushistorikk', 'Mangler ansvarlig', 'Kravkilder'],
            [$no['retire'], $no['reopen'], $no['history_heading'], $no['no_owner'], $no['sources']['heading']],
        );
    }

    /** @return array<string, mixed> */
    private function compliance(string $locale): array
    {
        $strings = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $strings['compliance'];
    }

    /** @return list<string> */
    private function itemTitles(array $content): array
    {
        return array_merge(...array_map(fn (array $section): array => array_column($section['items'], 'title'), $content['sections']));
    }

    private function allText(array $content): string
    {
        return implode(' ', array_merge(...array_map(fn (array $section): array => array_column($section['items'], 'text'), $content['sections'])));
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
