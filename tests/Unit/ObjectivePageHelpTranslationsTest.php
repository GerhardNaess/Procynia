<?php

namespace Tests\Unit;

use App\Services\Objectives\ObjectiveAttentionService;
use PHPUnit\Framework\TestCase;

/**
 * Purpose: Mål og KPI must not ship without PageHelp. Each of its pages — the overview, an objective
 * and a KPI — has help with sections in both languages, built the same way, and the help names the
 * things the pages actually show: every «Trenger oppmerksomhet» category under the label the panel
 * uses, and «Dagens status» and «Status da» under the labels on the KPI page. A category or label
 * renamed without the help following it fails here instead of leaving the help describing something
 * the user cannot find.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class ObjectivePageHelpTranslationsTest extends TestCase
{
    private const PAGES = ['index', 'objective', 'kpi'];

    public function test_every_page_has_help_with_sections_in_both_languages(): void
    {
        foreach (['no', 'en'] as $locale) {
            $help = $this->objectives($locale)['help'] ?? null;
            $this->assertIsArray($help, "Missing objectives.help in lang/{$locale}/procynia.php.");
            $this->assertNotSame('', trim((string) ($help['button'] ?? '')));

            foreach (self::PAGES as $page) {
                $content = $help[$page] ?? null;
                $this->assertIsArray($content, "Missing objectives.help.{$page} in lang/{$locale}.");
                $this->assertNotSame('', trim((string) ($content['title'] ?? '')));
                $this->assertNotSame('', trim((string) ($content['intro'] ?? '')));
                $this->assertNotEmpty($content['sections'] ?? [], "objectives.help.{$page} has no sections in lang/{$locale}.");

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

        $this->assertSame($shape($this->objectives('no')['help']), $shape($this->objectives('en')['help']));
    }

    public function test_both_languages_expose_the_same_objective_keys(): void
    {
        $this->assertSame($this->keys($this->objectives('no')), $this->keys($this->objectives('en')));
    }

    public function test_the_overview_help_explains_every_attention_category_by_its_panel_label(): void
    {
        foreach (['no', 'en'] as $locale) {
            $objectives = $this->objectives($locale);
            $titles = $this->itemTitles($objectives['help']['index']);

            foreach (array_keys(ObjectiveAttentionService::CATEGORIES) as $category) {
                $label = $objectives['attention']['categories'][$category];
                $this->assertContains($label, $titles, "The overview help in lang/{$locale} does not explain «{$label}».");
            }
        }
    }

    public function test_the_kpi_help_explains_current_status_and_status_then_by_their_page_labels(): void
    {
        foreach (['no', 'en'] as $locale) {
            $objectives = $this->objectives($locale);
            $titles = $this->itemTitles($objectives['help']['kpi']);

            $this->assertContains($objectives['measurement']['result_now'], $titles);
            $this->assertContains($objectives['measurement']['col_result'], $titles);
        }
    }

    public function test_the_indicator_is_explained_as_a_count_and_not_a_score(): void
    {
        $this->assertStringContainsString('ikke en samlet score', $this->allText($this->objectives('no')['help']['objective']));
        $this->assertStringContainsString('not an overall score', $this->allText($this->objectives('en')['help']['objective']));
    }

    /** @return array<string, mixed> */
    private function objectives(string $locale): array
    {
        $strings = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $strings['objectives'];
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
