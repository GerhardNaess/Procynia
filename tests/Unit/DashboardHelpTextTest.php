<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: A dashboard tooltip must describe what is on the screen, not what the data layer could
 * in principle support. The pipeline tooltip claimed to show "gjennomsnittlig tempo" long after the
 * UI stopped showing any time at all, which is the failure mode these guard against: promised
 * percentages, speed, trends or actions that the rendered dashboard does not have.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class DashboardHelpTextTest extends TestCase
{
    /** The info affordances the dashboard actually renders. */
    private const LIVE_INFO_KEYS = [
        'attention',
        'deadlines',
        'management',
        'pipeline_stages',
        'outcomes',
    ];

    private const SIGNAL_DESCRIPTION_KEYS = [
        'signal_go_no_go_description',
        'signal_missing_bid_manager_description',
        'signal_deadline_soon_description',
        'signal_inactive_description',
    ];

    public function test_both_languages_define_exactly_the_tooltips_that_are_rendered(): void
    {
        foreach (['no', 'en'] as $locale) {
            $infoTexts = $this->infoTexts($locale);

            $this->assertSame(
                self::LIVE_INFO_KEYS,
                array_keys($infoTexts),
                "lang/{$locale}/procynia.php must carry a tooltip for each rendered info button, and none for anything else.",
            );

            foreach ($infoTexts as $key => $text) {
                $this->assertNotSame('', trim((string) $text), "Empty tooltip for '{$key}'.");
            }
        }
    }

    public function test_both_languages_expose_the_same_dashboard_keys(): void
    {
        $norwegian = array_keys($this->redesign('no'));
        $english = array_keys($this->redesign('en'));

        sort($norwegian);
        sort($english);

        $this->assertSame($norwegian, $english);
    }

    public function test_the_pipeline_tooltip_does_not_promise_time_or_speed(): void
    {
        // The pipeline shows a count and a proportional bar. Nothing else.
        $forbidden = [
            'no' => ['tempo', 'gjennomsnitt', 'tid', 'hastighet', 'trend'],
            'en' => ['pace', 'average', 'speed', 'time', 'trend', 'throughput'],
        ];

        foreach ($forbidden as $locale => $terms) {
            $text = mb_strtolower($this->infoTexts($locale)['pipeline_stages']);

            foreach ($terms as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $text,
                    "The pipeline tooltip must not mention '{$term}' — the UI shows counts only.",
                );
            }
        }
    }

    public function test_the_pipeline_tooltip_describes_the_counts_it_shows(): void
    {
        $this->assertStringContainsString('aktive saker i hver fase', $this->infoTexts('no')['pipeline_stages']);
        $this->assertStringContainsString('active cases are in each phase', $this->infoTexts('en')['pipeline_stages']);
    }

    public function test_no_tooltip_promises_a_percentage_the_dashboard_does_not_show(): void
    {
        // Coverage renders as "3 / 12", never as a percentage. Win rate is the only percentage,
        // and it explains itself inline on the row that carries it.
        foreach (['no', 'en'] as $locale) {
            foreach ($this->infoTexts($locale) as $key => $text) {
                $this->assertStringNotContainsString('%', (string) $text, "Tooltip '{$key}' promises a percentage.");
                $this->assertStringNotContainsString('prosent', mb_strtolower((string) $text));
                $this->assertStringNotContainsString('percent', mb_strtolower((string) $text));
            }
        }
    }

    public function test_the_signal_descriptions_state_the_thresholds_that_are_implemented(): void
    {
        $norwegian = $this->redesign('no');
        $this->assertStringContainsString('i dag eller de neste 5 dagene', $norwegian['signal_deadline_soon_description']);
        $this->assertStringContainsString('siste 7 dagene', $norwegian['signal_inactive_description']);
        $this->assertStringContainsString('beslutning', $norwegian['signal_go_no_go_description']);
        $this->assertStringContainsString('Bid Manager', $norwegian['signal_missing_bid_manager_description']);

        $english = $this->redesign('en');
        $this->assertStringContainsString('next 5 days', $english['signal_deadline_soon_description']);
        $this->assertStringContainsString('last 7 days', $english['signal_inactive_description']);
        $this->assertStringContainsString('decision', $english['signal_go_no_go_description']);
        $this->assertStringContainsString('bid manager', $english['signal_missing_bid_manager_description']);
    }

    public function test_every_signal_has_a_description_that_is_not_just_its_label(): void
    {
        foreach (['no', 'en'] as $locale) {
            $redesign = $this->redesign($locale);

            foreach (self::SIGNAL_DESCRIPTION_KEYS as $key) {
                $this->assertArrayHasKey($key, $redesign, "Missing '{$key}' in lang/{$locale}.");

                $label = $redesign[str_replace('_description', '', $key)] ?? '';
                $this->assertNotSame($label, $redesign[$key], "'{$key}' just repeats its label.");
                $this->assertGreaterThan(20, mb_strlen($redesign[$key]), "'{$key}' is too thin to explain anything.");
            }
        }
    }

    public function test_the_results_tooltip_names_the_four_outcomes_and_not_the_archive(): void
    {
        $norwegian = $this->infoTexts('no')['outcomes'];
        foreach (['vunnet', 'tapt', 'No-Go', 'trukket'] as $outcome) {
            $this->assertStringContainsString($outcome, $norwegian);
        }
        $this->assertStringNotContainsString('arkiv', mb_strtolower($norwegian));
        $this->assertStringNotContainsString('historikk', mb_strtolower($norwegian));

        $english = $this->infoTexts('en')['outcomes'];
        foreach (['won', 'lost', 'No-Go', 'withdrawn'] as $outcome) {
            $this->assertStringContainsString($outcome, $english);
        }
        $this->assertStringNotContainsString('archive', mb_strtolower($english));
        $this->assertStringNotContainsString('history', mb_strtolower($english));
    }

    public function test_no_tooltip_explains_the_win_rate_that_may_not_be_rendered(): void
    {
        // The win rate row appears only when cases have closed, and carries its own basis line.
        foreach (['no', 'en'] as $locale) {
            foreach ($this->infoTexts($locale) as $key => $text) {
                $this->assertStringNotContainsString('win rate', mb_strtolower((string) $text), "Tooltip '{$key}' explains a row that may be hidden.");
            }
        }
    }

    public function test_no_tooltip_documents_another_page(): void
    {
        $offPage = [
            'no' => ['arbeidsliste', 'watch list', 'bevakningsprofil', 'saksvisning', 'kunngjøringer'],
            'en' => ['work list', 'watch list', 'case view', 'notices page'],
        ];

        foreach ($offPage as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->infoTexts($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $strings, "A dashboard tooltip documents '{$term}'.");
            }
        }
    }

    public function test_no_tooltip_leaks_internal_code_terms(): void
    {
        $forbidden = [
            'bid_manager_user_id',
            'opportunity_owner_user_id',
            'history_type',
            'bid_status',
            'cockpit-skopet',
            'saved_notice',
        ];

        foreach (['no', 'en'] as $locale) {
            $strings = mb_strtolower(implode(' || ', $this->infoTexts($locale)));

            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString(mb_strtolower($term), $strings, "A dashboard tooltip leaks '{$term}'.");
            }
        }
    }

    public function test_tooltips_stay_short_enough_to_be_tooltips(): void
    {
        foreach (['no', 'en'] as $locale) {
            foreach ($this->infoTexts($locale) as $key => $text) {
                $this->assertLessThanOrEqual(
                    170,
                    mb_strlen((string) $text),
                    "Tooltip '{$key}' reads as documentation rather than help.",
                );
            }
        }
    }

    public function test_the_page_help_uses_the_current_section_names(): void
    {
        $norwegian = $this->cockpit('no');
        foreach (['Krever oppfølging' => 'follow_up', 'Pipeline' => 'pipeline', 'Styring' => 'management', 'Resultater' => 'results', 'Bidkalender' => 'calendar'] as $name => $key) {
            $this->assertSame($name, $norwegian["page_help_item_{$key}_title"]);
        }

        $english = $this->cockpit('en');
        foreach (['Needs follow-up' => 'follow_up', 'Pipeline' => 'pipeline', 'Management' => 'management', 'Results' => 'results', 'Bid calendar' => 'calendar'] as $name => $key) {
            $this->assertSame($name, $english["page_help_item_{$key}_title"]);
        }
    }

    public function test_the_page_help_no_longer_names_the_old_dashboard(): void
    {
        $retired = [
            'no' => ['pipeline-stadier', 'oppmerksomhet nå', 'porteføljeoversikt', 'bid-kvalitet', 'ansvar & aktivitet', 'watch list'],
            'en' => ['pipeline stages', 'attention now', 'portfolio overview', 'bid quality', 'watch list'],
        ];

        foreach ($retired as $locale => $terms) {
            $text = mb_strtolower(implode(' || ', $this->pageHelp($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $text, "Page help still names the retired concept '{$term}'.");
            }
        }
    }

    public function test_the_page_help_pipeline_item_does_not_promise_time_or_speed(): void
    {
        $forbidden = [
            'no' => ['tempo', 'gjennomsnitt', 'hastighet', 'throughput'],
            'en' => ['pace', 'average', 'speed', 'throughput'],
        ];

        foreach ($forbidden as $locale => $terms) {
            $text = mb_strtolower($this->cockpit($locale)['page_help_item_pipeline_text']);

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $text, "The pipeline page help must not mention '{$term}'.");
            }
        }
    }

    public function test_the_page_help_results_item_names_the_four_outcomes_and_not_the_archive(): void
    {
        $norwegian = $this->cockpit('no')['page_help_item_results_text'];
        foreach (['vunnet', 'tapt', 'No-Go', 'trukket'] as $outcome) {
            $this->assertStringContainsString($outcome, $norwegian);
        }
        $this->assertStringNotContainsString('arkiv', mb_strtolower($norwegian));
        $this->assertStringNotContainsString('historikk', mb_strtolower($norwegian));

        $english = $this->cockpit('en')['page_help_item_results_text'];
        foreach (['won', 'lost', 'No-Go', 'withdrawn'] as $outcome) {
            $this->assertStringContainsString($outcome, $english);
        }
        $this->assertStringNotContainsString('archive', mb_strtolower($english));
    }

    public function test_the_page_help_management_item_describes_only_what_is_shown(): void
    {
        $norwegian = mb_strtolower($this->cockpit('no')['page_help_item_management_text']);
        $this->assertStringContainsString('kommersiell eier', $norwegian);
        $this->assertStringContainsString('bid manager', $norwegian);
        $this->assertStringContainsString('siste 14 dagene', $norwegian);
        // The section renders ratios, never percentages.
        $this->assertStringNotContainsString('%', $norwegian);
        $this->assertStringNotContainsString('andel', $norwegian);

        $english = mb_strtolower($this->cockpit('en')['page_help_item_management_text']);
        $this->assertStringContainsString('commercial owner', $english);
        $this->assertStringContainsString('bid manager', $english);
        $this->assertStringContainsString('last 14 days', $english);
        $this->assertStringNotContainsString('%', $english);
        $this->assertStringNotContainsString('share of', $english);
    }

    public function test_the_page_help_documents_only_the_dashboard(): void
    {
        $offPage = [
            'no' => ['arbeidsliste', 'saksvisning', 'kunngjøringer', 'bevakningsprofil', 'rediger frister', 'historikk'],
            'en' => ['work list', 'case view', 'notices page', 'watch profile', 'edit deadlines'],
        ];

        foreach ($offPage as $locale => $terms) {
            $text = mb_strtolower(implode(' || ', $this->pageHelp($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $text, "Dashboard page help documents '{$term}'.");
            }
        }
    }

    public function test_the_page_help_leaks_no_internal_code_terms(): void
    {
        foreach (['no', 'en'] as $locale) {
            $text = mb_strtolower(implode(' || ', $this->pageHelp($locale)));

            foreach (['bid_status', 'history_type', 'saved_notice', 'cockpit-skopet', 'attention_items', 'payload'] as $term) {
                $this->assertStringNotContainsString($term, $text, "Dashboard page help leaks '{$term}'.");
            }
        }
    }

    public function test_the_page_help_stays_short(): void
    {
        foreach (['no', 'en'] as $locale) {
            $items = $this->pageHelp($locale);

            // One intro, five items, one section title, one button label.
            $this->assertLessThanOrEqual(14, count($items));

            foreach ($items as $key => $text) {
                $this->assertLessThanOrEqual(200, mb_strlen((string) $text), "Page help '{$key}' reads as documentation.");
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function pageHelp(string $locale): array
    {
        return array_filter(
            $this->cockpit($locale),
            static fn (string $key): bool => str_starts_with($key, 'page_help_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, string>
     */
    private function cockpit(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['dashboard']['cockpit'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function infoTexts(string $locale): array
    {
        return $this->cockpit($locale)['info_texts'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function redesign(string $locale): array
    {
        return $this->cockpit($locale)['redesign'] ?? [];
    }
}
