<?php

namespace Tests\Unit;

use App\Models\Risk;
use App\Services\Risk\RiskAttentionService;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: the Risiko register must not ship without PageHelp. The help has sections in both
 * languages, built the same way, and names what the page actually shows under the labels the page
 * uses: the fields, every status and exactly the Trenger oppmerksomhet categories that exist. A label
 * renamed without the help following it fails here instead of leaving the help describing something
 * the user cannot find.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class RiskPageHelpTranslationsTest extends TestCase
{
    private const PAGES = ['index'];

    public function test_every_page_has_help_with_sections_in_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->risk($locale)['help'] ?? null;
            $this->assertIsArray($help, "Missing risk.help in lang/{$locale}/procynia.php.");
            $this->assertNotSame('', trim((string) ($help['button'] ?? '')));

            foreach (self::PAGES as $page) {
                $content = $help[$page] ?? null;
                $this->assertIsArray($content, "Missing risk.help.{$page} in lang/{$locale}.");
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

        $this->assertSame($shape($this->risk('no')['help']), $shape($this->risk('en')['help']));
    }

    public function test_the_register_help_explains_the_fields_and_every_status_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->risk($locale);
            $titles = $this->itemTitles($strings['help']['index']);

            foreach (['field_area', 'field_owner', 'field_status'] as $field) {
                $this->assertContains($strings[$field], $titles, "The register help in lang/{$locale} does not explain «{$strings[$field]}».");
            }

            $status = collect($strings['help']['index']['sections'])->flatMap(fn (array $section): array => $section['items'])
                ->firstWhere('title', $strings['field_status']);

            foreach (Risk::STATUSES as $key) {
                $this->assertStringContainsString($strings['statuses'][$key], $status['text'], "The status help in lang/{$locale} does not name «{$strings['statuses'][$key]}».");
            }
        }
    }

    public function test_the_register_help_explains_exactly_the_attention_categories_that_exist(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->risk($locale);
            $this->assertSame(RiskAttentionService::CATEGORIES, array_keys($strings['attention']['categories']));

            $section = collect($strings['help']['index']['sections'])->firstWhere('title', $strings['attention']['heading']);
            $this->assertNotNull($section, "The register help in lang/{$locale} has no «{$strings['attention']['heading']}» section.");

            $titles = array_column($section['items'], 'title');
            foreach ($strings['attention']['categories'] as $label) {
                $this->assertContains($label, $titles, "The attention help in lang/{$locale} does not explain «{$label}».");
            }

            $this->assertStringContainsString($strings['attention']['show'], implode(' ', array_column($section['items'], 'text')));
        }
    }

    /** @return array<string, mixed> */
    private function risk(string $locale): array
    {
        $strings = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $strings['risk'];
    }

    /** @return list<string> */
    private function itemTitles(array $content): array
    {
        return array_merge(...array_map(fn (array $section): array => array_column($section['items'], 'title'), $content['sections']));
    }
}
