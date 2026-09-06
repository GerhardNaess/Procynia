<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: The watch profile form's help must describe what the matcher actually does. Keywords are
 * substring-matched against a notice's title, description and buyer; CPV codes match on code and
 * contribute their own weight to the score; the profile's own description is never consulted. A
 * tooltip that implies otherwise teaches people to fill in the wrong field.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class WatchProfileHelpTextTest extends TestCase
{
    private const HINT_KEYS = [
        'hint_owner_scope',
        'hint_status',
        'hint_description',
        'hint_keywords',
        'hint_cpv',
        'hint_weight',
    ];

    private const PAGE_HELP_KEYS = [
        'form_page_help_title',
        'form_page_help_intro',
        'form_page_help_section_about',
        'form_page_help_item_what_title',
        'form_page_help_item_what_text',
        'form_page_help_item_criteria_title',
        'form_page_help_item_criteria_text',
        'form_page_help_item_cpv_title',
        'form_page_help_item_cpv_text',
        'form_page_help_item_status_title',
        'form_page_help_item_status_text',
    ];

    public function test_both_languages_define_every_hint_and_page_help_string(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->watchProfilePage($locale);

            foreach ([...self::HINT_KEYS, ...self::PAGE_HELP_KEYS, 'keywords_help'] as $key) {
                $this->assertArrayHasKey($key, $strings, "Missing '{$key}' in lang/{$locale}/procynia.php.");
                $this->assertNotSame('', trim((string) $strings[$key]));
            }
        }
    }

    public function test_both_languages_expose_the_same_keys(): void
    {
        $norwegian = array_keys($this->watchProfilePage('no'));
        $english = array_keys($this->watchProfilePage('en'));

        sort($norwegian);
        sort($english);

        $this->assertSame($norwegian, $english);
    }

    public function test_the_keyword_hint_names_the_fields_that_are_actually_searched(): void
    {
        // DoffinWatchProfileMatchService::noticeSearchText() concatenates title, description and
        // buyer_name, then str_contains each keyword against it.
        $norwegian = mb_strtolower($this->watchProfilePage('no')['hint_keywords']);
        foreach (['tittel', 'beskrivelse', 'oppdragsgiver'] as $field) {
            $this->assertStringContainsString($field, $norwegian);
        }

        $english = mb_strtolower($this->watchProfilePage('en')['hint_keywords']);
        foreach (['title', 'description', 'buyer'] as $field) {
            $this->assertStringContainsString($field, $english);
        }
    }

    public function test_the_description_hint_says_it_does_not_affect_matching(): void
    {
        // The profile's own description is never read by the matcher — only the notice's is.
        $this->assertStringContainsString(
            'påvirker ikke',
            $this->watchProfilePage('no')['hint_description'],
        );
        $this->assertStringContainsString(
            'does not affect',
            $this->watchProfilePage('en')['hint_description'],
        );
    }

    public function test_the_status_hint_matches_the_active_gate(): void
    {
        // Both the matcher and inbox discovery filter on is_active.
        $this->assertStringContainsString('aktive profiler', $this->watchProfilePage('no')['hint_status']);
        $this->assertStringContainsString('active profiles', $this->watchProfilePage('en')['hint_status']);
    }

    public function test_the_weight_hint_describes_scoring_because_weight_is_scored(): void
    {
        // DoffinWatchProfileMatchService adds the rule's own weight per matched code, so the hint
        // may legitimately describe relative importance.
        $norwegian = mb_strtolower($this->watchProfilePage('no')['hint_weight']);
        $this->assertStringContainsString('teller', $norwegian);
        $this->assertStringContainsString('høyere vekt', $norwegian);

        $english = mb_strtolower($this->watchProfilePage('en')['hint_weight']);
        $this->assertStringContainsString('counts', $english);
        $this->assertStringContainsString('higher weight', $english);
    }

    public function test_the_owner_hint_describes_the_access_rule_that_exists(): void
    {
        // WatchProfile::scopeAccessibleTo grants department profiles to department members.
        $this->assertStringContainsString('avdeling', mb_strtolower($this->watchProfilePage('no')['hint_owner_scope']));
        $this->assertStringContainsString('department', mb_strtolower($this->watchProfilePage('en')['hint_owner_scope']));
    }

    public function test_the_cpv_help_matches_the_search_first_flow(): void
    {
        $norwegian = mb_strtolower($this->watchProfilePage('no')['form_page_help_item_cpv_text']);
        $this->assertStringContainsString('søk', $norwegian);
        $this->assertStringContainsString('fjern', $norwegian);
        // The old row-based model is gone from the UI and must not be described.
        $this->assertStringNotContainsString('rad', $norwegian);

        $english = mb_strtolower($this->watchProfilePage('en')['form_page_help_item_cpv_text']);
        $this->assertStringContainsString('search', $english);
        $this->assertStringContainsString('remove', $english);
        $this->assertStringNotContainsString(' row', $english);
    }

    public function test_no_help_string_leaks_internal_implementation(): void
    {
        $forbidden = [
            'no' => ['modell forventer', 'samme format', 'watch profile-modell', 'is_active', 'cpv_codes', 'user_id', 'department_id'],
            'en' => ['model expects', 'same format', 'is_active', 'cpv_codes', 'user_id', 'department_id'],
        ];

        foreach ($forbidden as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->watchProfilePage($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $strings, "Watch profile help leaks '{$term}'.");
            }
        }
    }

    public function test_the_help_documents_only_this_page(): void
    {
        $offPage = [
            'no' => ['dashboard', 'arbeidsliste', 'sakslisten', 'saksvisning', 'infosenter', 'historikk'],
            'en' => ['dashboard', 'work list', 'case view', 'info centre', 'history'],
        ];

        foreach ($offPage as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->pageHelpOnly($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $strings, "Watch profile page help documents '{$term}'.");
            }
        }
    }

    public function test_the_hints_stay_short_enough_to_be_hints(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->watchProfilePage($locale);

            foreach (self::HINT_KEYS as $key) {
                $this->assertLessThanOrEqual(
                    180,
                    mb_strlen((string) $strings[$key]),
                    "Hint '{$key}' reads as documentation rather than a tooltip.",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function watchProfilePage(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['watch_profile_page'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function pageHelpOnly(string $locale): array
    {
        return array_filter(
            $this->watchProfilePage($locale),
            static fn (string $key): bool => str_starts_with($key, 'form_page_help_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
