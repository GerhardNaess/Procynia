<?php

namespace Tests\Unit;

use App\Models\ImprovementAction;
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

    public function test_the_case_help_explains_cause_and_tiltak_by_their_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->improvements($locale);
            $actions = $strings['actions'];
            $sectionTitles = array_column($strings['help']['case']['sections'], 'title');
            $caseTitles = $this->itemTitles($strings['help']['case']);

            $this->assertContains($strings['cause']['heading'], $sectionTitles, "The case help in lang/{$locale} has no «{$strings['cause']['heading']}» section.");
            $this->assertContains($actions['heading'], $sectionTitles, "The case help in lang/{$locale} has no «{$actions['heading']}» section.");

            foreach (['start', 'complete', 'cancel_action', 'reopen'] as $key) {
                $this->assertContains($actions[$key], $caseTitles, "The case help in lang/{$locale} does not explain «{$actions[$key]}».");
            }

            $tiltak = collect($strings['help']['case']['sections'])->firstWhere('title', $actions['heading']);
            $text = implode(' ', array_column($tiltak['items'], 'text'));
            $this->assertStringContainsString($actions['completion_label'], $text, "The tiltak help in lang/{$locale} does not name «{$actions['completion_label']}».");

            $this->assertSame(ImprovementAction::STATUSES, array_keys($actions['statuses']));
            $this->assertSame(['in_progress', 'completed', 'cancelled', 'planned'], array_keys($actions['history']));
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

        $actions = $no['actions'];
        $this->assertSame('Årsak og bakgrunn', $no['cause']['heading']);
        $this->assertSame('Beskriv hvorfor avviket oppstod, dersom årsaken er kjent.', $no['cause']['hint_deviation']);
        $this->assertSame('Beskriv bakgrunnen for forbedringen og hva som bør endres.', $no['cause']['hint_improvement']);
        $this->assertSame(['planned' => 'Planlagt', 'in_progress' => 'Under arbeid', 'completed' => 'Fullført', 'cancelled' => 'Avbrutt'], $actions['statuses']);
        $this->assertSame(
            ['Tiltak', 'Nytt tiltak', 'Ansvarlig', 'Frist', 'Frist passert', 'Mangler ansvarlig', 'Start tiltak', 'Fullfør tiltak', 'Avbryt tiltak', 'Gjenåpne tiltak', 'Hva ble gjort?', 'Historikk'],
            [$actions['heading'], $actions['create'], $actions['field_owner'], $actions['field_due_date'], $actions['overdue'], $actions['no_owner'], $actions['start'], $actions['complete'], $actions['cancel_action'], $actions['reopen'], $actions['completion_label'], $actions['history_heading']],
        );
        $this->assertSame(
            'Saken har tiltak som ikke er ferdig behandlet. Fullfør eller avbryt tiltakene før saken avsluttes.',
            $no['validation']['actions_not_finished'],
        );

        $this->assertSame(
            ['Effektverifisering', 'Venter på effektverifisering', 'Verifiser effekt', 'Resultat', 'Kommentar'],
            [$actions['verification_heading'], $actions['verification_awaiting'], $actions['verify'], $actions['verification_result_label'], $actions['verification_note_label']],
        );
        $this->assertSame(['effective' => 'Effekt bekreftet', 'not_effective' => 'Ikke effektivt'], $actions['verification_results']);
        $this->assertSame('Effekten er ikke bekreftet. Gjenåpne tiltaket dersom det må arbeides videre med.', $actions['verification_not_effective_hint']);
        $this->assertSame('Ett eller flere fullførte tiltak er ikke effektverifisert. Verifiser effekten før saken lukkes.', $no['validation']['actions_not_verified']);
        $this->assertSame('Ett eller flere tiltak er vurdert som ikke effektive. Følg opp tiltakene før saken lukkes.', $no['validation']['actions_not_effective']);
    }

    public function test_the_case_help_explains_effektverifisering_by_its_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $actions = $this->improvements($locale)['actions'];
            $section = collect($this->improvements($locale)['help']['case']['sections'])->firstWhere('title', $actions['verification_heading']);
            $this->assertNotNull($section, "The case help in lang/{$locale} has no «{$actions['verification_heading']}» section.");

            $titles = array_column($section['items'], 'title');
            foreach ($actions['verification_results'] as $label) {
                $this->assertContains($label, $titles, "The effektverifisering help in lang/{$locale} does not explain «{$label}».");
            }

            $this->assertStringContainsString($actions['verification_awaiting'], implode(' ', array_column($section['items'], 'text')));
        }
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
