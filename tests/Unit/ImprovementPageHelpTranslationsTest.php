<?php

namespace Tests\Unit;

use App\Models\ImprovementCase;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: Avvik og forbedringer must not ship without PageHelp. The register and the case page each
 * have help with sections in both languages, built the same way, and the help names what the pages
 * actually show: both types and every status under the labels the badges use. A type or status
 * renamed without the help following it fails here instead of leaving the help describing something
 * the user cannot find. Both languages also carry exactly the same keys.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class ImprovementPageHelpTranslationsTest extends TestCase
{
    private const PAGES = ['index', 'case'];

    public function test_every_page_has_help_with_sections_in_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->improvements($locale)['help'] ?? null;
            $this->assertIsArray($help, "Missing improvements.help in lang/{$locale}/procynia.php.");
            $this->assertNotSame('', trim((string) ($help['button'] ?? '')));

            foreach (self::PAGES as $page) {
                $content = $help[$page] ?? null;
                $this->assertIsArray($content, "Missing improvements.help.{$page} in lang/{$locale}.");
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

        $this->assertSame($shape($this->improvements('no')['help']), $shape($this->improvements('en')['help']));
    }

    public function test_both_languages_expose_the_same_keys(): void
    {
        $this->assertSame($this->keys($this->improvements('no')), $this->keys($this->improvements('en')));
    }

    public function test_the_help_explains_both_types_and_every_status_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->improvements($locale);
            $indexTitles = $this->itemTitles($strings['help']['index']);
            $caseTitles = $this->itemTitles($strings['help']['case']);

            foreach (ImprovementCase::TYPES as $type) {
                $this->assertContains($strings['types'][$type], $indexTitles, "The register help in lang/{$locale} does not explain «{$strings['types'][$type]}».");
            }

            foreach (ImprovementCase::STATUSES as $status) {
                $this->assertContains($strings['statuses'][$status], $caseTitles, "The case help in lang/{$locale} does not explain «{$strings['statuses'][$status]}».");
            }

            foreach (['field_area', 'field_owner', 'field_due_date'] as $field) {
                $this->assertContains($strings[$field], $indexTitles, "The register help in lang/{$locale} does not explain «{$strings[$field]}».");
            }
        }
    }

    public function test_the_norwegian_ui_uses_the_agreed_domain_terms(): void
    {
        $no = $this->improvements('no');

        $this->assertSame(['deviation' => 'Avvik', 'improvement' => 'Forbedring'], $no['types']);
        $this->assertSame(['open' => 'Åpen', 'in_progress' => 'Under arbeid', 'closed' => 'Lukket', 'cancelled' => 'Avbrutt'], $no['statuses']);
        $this->assertSame('Avvik og forbedringer', $no['index_title']);
        $this->assertSame(['Ansvarlig', 'Fagområde', 'Frist', 'Hendelsesdato'], [$no['field_owner'], $no['field_area'], $no['field_due_date'], $no['field_occurred_at']]);
        $this->assertSame(['Start behandling', 'Gjenåpne', 'Historikk'], [$no['start'], $no['reopen'], $no['history_heading']]);
        $this->assertStringStartsWith('Lukk', $no['close']);
        $this->assertStringStartsWith('Avbryt', $no['cancel_case']);
    }

    /** @return array<string, mixed> */
    private function improvements(string $locale): array
    {
        $strings = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $strings['improvements'];
    }

    /** @return list<string> */
    private function itemTitles(array $content): array
    {
        return array_merge(...array_map(fn (array $section): array => array_column($section['items'], 'title'), $content['sections']));
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
