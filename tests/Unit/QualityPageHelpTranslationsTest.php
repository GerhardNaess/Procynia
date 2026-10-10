<?php

namespace Tests\Unit;

use App\Services\Quality\QualityAttentionService;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: Kvalitet must not ship without PageHelp. Each of its views — the four tabs and one
 * document's page by kind — has help with sections in both languages, built the same way, and the
 * overview help names every «Trenger oppmerksomhet» finding under the label the panel uses. A
 * finding added or renamed without the help following it fails here instead of leaving the help
 * describing something the user cannot find.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class QualityPageHelpTranslationsTest extends TestCase
{
    /** Mirrors QUALITY_HELP_PAGES in resources/js/Pages/App/Quality/qualityHelp.js. */
    private const PAGES = ['overview', 'processes', 'controls', 'tools', 'process', 'control', 'document'];

    private const FINDINGS = [
        QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE,
        QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY,
        QualityAttentionService::PROCESSES_WITHOUT_GOVERNING_POLICY,
        QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW,
    ];

    public function test_every_page_has_help_with_sections_in_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->quality($locale)['help'] ?? null;
            $this->assertIsArray($help, "Missing quality.help in lang/{$locale}/procynia.php.");
            $this->assertNotSame('', trim((string) ($help['button'] ?? '')));

            foreach (self::PAGES as $page) {
                $content = $help[$page] ?? null;
                $this->assertIsArray($content, "Missing quality.help.{$page} in lang/{$locale}.");
                $this->assertNotSame('', trim((string) ($content['title'] ?? '')));
                $this->assertNotSame('', trim((string) ($content['intro'] ?? '')));
                $this->assertNotEmpty($content['sections'] ?? [], "quality.help.{$page} has no sections in lang/{$locale}.");

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

        $this->assertSame($shape($this->quality('no')['help']), $shape($this->quality('en')['help']));
    }

    public function test_the_overview_help_explains_every_attention_finding_by_its_panel_label(): void
    {
        foreach (['no', 'en'] as $locale) {
            $quality = $this->quality($locale);
            $titles = array_merge(...array_map(
                fn (array $section): array => array_column($section['items'], 'title'),
                $quality['help']['overview']['sections'],
            ));

            foreach (self::FINDINGS as $finding) {
                $label = $quality['attention'][$finding]['title'];
                $this->assertContains($label, $titles, "The overview help in lang/{$locale} does not explain «{$label}».");
            }
        }
    }

    public function test_the_overview_help_says_findings_are_not_registered_nonconformities(): void
    {
        $this->assertStringContainsString('ikke registrerte avvik', json_encode($this->quality('no')['help']['overview'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('not registered nonconformities', json_encode($this->quality('en')['help']['overview']));
    }

    /** @return array<string, mixed> */
    private function quality(string $locale): array
    {
        $strings = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $strings['quality'];
    }
}
