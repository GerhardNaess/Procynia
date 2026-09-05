<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: The case page help must describe the current Go / No-Go workflow in both supported
 * languages: No-Go closes the case through a dialog with a required reason, ordinary work stops,
 * and the decision can still be undone through the explicit reopen action. It must not describe
 * No-Go as permanent, and it must keep No-Go and archiving apart as two different concepts.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class SavedNoticePageHelpTranslationsTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'page_help_section_go_no_go',
        'page_help_item_go_no_go_phase_title',
        'page_help_item_go_no_go_phase_text',
        'page_help_item_go_no_go_set_title',
        'page_help_item_go_no_go_set_text',
        'page_help_item_go_no_go_meaning_title',
        'page_help_item_go_no_go_meaning_text',
        'page_help_section_no_go_decision',
        'page_help_item_no_go_decision_title',
        'page_help_item_no_go_decision_text',
        'page_help_item_reopen_when_title',
        'page_help_item_reopen_when_text',
        'page_help_item_reopen_history_title',
        'page_help_item_reopen_history_text',
        'page_help_item_reopen_access_title',
        'page_help_item_reopen_access_text',
        'page_help_item_comments_title',
        'page_help_item_comments_text',
        'page_help_section_archiving',
        'page_help_item_archiving_move_title',
        'page_help_item_archiving_move_text',
        'page_help_item_archiving_difference_title',
        'page_help_item_archiving_difference_text',
        'page_help_item_archiving_no_go_title',
        'page_help_item_archiving_no_go_text',
    ];

    public function test_both_languages_define_the_go_no_go_page_help_strings(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->savedNoticeStrings($locale);

            foreach (self::REQUIRED_KEYS as $key) {
                $this->assertArrayHasKey($key, $strings, "Missing '{$key}' in lang/{$locale}/procynia.php.");
                $this->assertNotSame('', trim((string) $strings[$key]));
            }
        }
    }

    public function test_both_languages_expose_the_same_page_help_keys(): void
    {
        $norwegian = array_keys($this->pageHelpStrings('no'));
        $english = array_keys($this->pageHelpStrings('en'));

        sort($norwegian);
        sort($english);

        $this->assertSame($norwegian, $english);
    }

    public function test_the_no_go_dialog_help_states_that_a_reason_is_required_and_the_note_optional(): void
    {
        $norwegian = $this->savedNoticeStrings('no')['page_help_item_go_no_go_set_text'];
        $this->assertStringContainsString('Årsak må velges', $norwegian);
        $this->assertStringContainsString('valgfritt', $norwegian);

        $english = $this->savedNoticeStrings('en')['page_help_item_go_no_go_set_text'];
        $this->assertStringContainsString('reason is required', $english);
        $this->assertStringContainsString('optional', $english);
    }

    public function test_the_page_help_does_not_describe_no_go_as_permanent(): void
    {
        $norwegian = implode(' || ', $this->pageHelpStrings('no'));
        $this->assertStringNotContainsString('permanent', mb_strtolower($norwegian));
        $this->assertStringNotContainsString('kan ikke gjenåpnes', mb_strtolower($norwegian));

        $english = implode(' || ', $this->pageHelpStrings('en'));
        $this->assertStringNotContainsString('permanent', mb_strtolower($english));
        $this->assertStringNotContainsString('cannot be reopened', mb_strtolower($english));
    }

    public function test_the_page_help_explains_that_reopening_returns_the_case_to_go_no_go(): void
    {
        $this->assertStringContainsString(
            'tilbake til Go / No-Go',
            $this->savedNoticeStrings('no')['page_help_item_reopen_when_text'],
        );

        $this->assertStringContainsString(
            'returns to Go / No-Go',
            $this->savedNoticeStrings('en')['page_help_item_reopen_when_text'],
        );
    }

    public function test_the_page_help_keeps_the_earlier_no_go_decision_in_the_history(): void
    {
        $this->assertStringContainsString(
            'slettes ikke',
            $this->savedNoticeStrings('no')['page_help_item_reopen_history_text'],
        );

        $this->assertStringContainsString(
            'is not deleted',
            $this->savedNoticeStrings('en')['page_help_item_reopen_history_text'],
        );
    }

    public function test_the_page_help_separates_no_go_from_history(): void
    {
        $norwegian = $this->savedNoticeStrings('no')['page_help_item_archiving_difference_text'];
        $this->assertStringContainsString('tilbudsfaglig beslutning', $norwegian);
        $this->assertStringContainsString('endelig arkivering', $norwegian);

        $english = $this->savedNoticeStrings('en')['page_help_item_archiving_difference_text'];
        $this->assertStringContainsString('bid decision', $english);
        $this->assertStringContainsString('filed for good', $english);
    }

    public function test_the_page_help_describes_moving_to_history_as_irreversible_but_still_readable(): void
    {
        $norwegian = $this->savedNoticeStrings('no')['page_help_item_archiving_move_text'];
        $this->assertStringContainsString('kan fortsatt åpnes og leses i Historikk', $norwegian);
        $this->assertStringContainsString('kan ikke flyttes tilbake til den aktive arbeidslisten', $norwegian);

        $english = $this->savedNoticeStrings('en')['page_help_item_archiving_move_text'];
        $this->assertStringContainsString('can still be opened and read in History', $english);
        $this->assertStringContainsString('cannot be moved back to the active work list', $english);
    }

    public function test_the_page_help_never_offers_to_restore_an_ordinary_archived_case(): void
    {
        $norwegian = mb_strtolower($this->savedNoticeStrings('no')['page_help_item_archiving_move_text']
            .' '.$this->savedNoticeStrings('no')['page_help_item_archiving_difference_text']);

        foreach (['hentes tilbake', 'reaktiver', 'gjenopprett'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $norwegian);
        }

        $english = mb_strtolower($this->savedNoticeStrings('en')['page_help_item_archiving_move_text']
            .' '.$this->savedNoticeStrings('en')['page_help_item_archiving_difference_text']);

        foreach (['restore', 'reactivate', 'brought back'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $english);
        }
    }

    public function test_the_page_help_presents_an_archived_no_go_case_as_the_exception(): void
    {
        $norwegian = $this->savedNoticeStrings('no')['page_help_item_archiving_no_go_text'];
        $this->assertStringContainsString('unntaket', $norwegian);
        $this->assertStringContainsString('Gjenåpne sak', $norwegian);
        $this->assertStringContainsString('sporbar', $norwegian);
        $this->assertStringContainsString('ikke vanlig gjenoppretting fra Historikk', $norwegian);

        $english = $this->savedNoticeStrings('en')['page_help_item_archiving_no_go_text'];
        $this->assertStringContainsString('the exception', $english);
        $this->assertStringContainsString('Reopen case', $english);
        $this->assertStringContainsString('audited', $english);
        $this->assertStringContainsString('not an ordinary restore from History', $english);
    }

    public function test_the_page_help_does_not_document_the_notices_page(): void
    {
        // Page help describes the page the user is on. Business Review is registered under
        // "Rediger frister" in the work list, and documents are uploaded in the AI workspace —
        // neither is an action on this page, so neither may be explained here.
        $offPageTerms = [
            'no' => ['business review', 'rediger frister', 'live-søk', 'arbeidslisten viser', 'flytt til historikk-knappen'],
            'en' => ['business review', 'edit deadlines', 'live search', 'the work list shows'],
        ];

        foreach ($offPageTerms as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->pageHelpStrings($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $strings,
                    "The case page help must not document '{$term}', which belongs to another page.",
                );
            }
        }
    }

    public function test_the_page_help_only_names_actions_that_exist_on_the_case_page(): void
    {
        $norwegian = $this->savedNoticeStrings('no');

        $this->assertStringContainsString('Statuspanelet', $norwegian['page_help_item_status_text']);
        $this->assertStringContainsString('nedlasting', $norwegian['page_help_item_documents_text']);
        $this->assertStringContainsString('Åpne saksdokumenter og AI', $norwegian['page_help_item_documents_text']);
        $this->assertStringContainsString('Fasekommentarer', $norwegian['page_help_item_comments_title']);
        $this->assertStringContainsString('Gjenåpne sak', $norwegian['page_help_item_archiving_no_go_text']);

        $english = $this->savedNoticeStrings('en');

        $this->assertStringContainsString('status panel', $english['page_help_item_status_text']);
        $this->assertStringContainsString('download', $english['page_help_item_documents_text']);
        $this->assertStringContainsString('Open case documents and AI', $english['page_help_item_documents_text']);
        $this->assertStringContainsString('Phase comments', $english['page_help_item_comments_title']);
        $this->assertStringContainsString('Reopen case', $english['page_help_item_archiving_no_go_text']);
    }

    public function test_the_page_help_avoids_internal_code_terms(): void
    {
        $forbidden = [
            'bid_status',
            'transitionBidStatus',
            'saved_notice_no_go_decisions',
            'reopen_after_no_go_url',
            'controller',
            'service',
        ];

        foreach (['no', 'en'] as $locale) {
            $strings = mb_strtolower(implode(' || ', $this->pageHelpStrings($locale)));

            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString(
                    mb_strtolower($term),
                    $strings,
                    "lang/{$locale}/procynia.php page help must not use the internal term '{$term}'.",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function savedNoticeStrings(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['saved_notice'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function pageHelpStrings(string $locale): array
    {
        return array_filter(
            $this->savedNoticeStrings($locale),
            static fn (string $key): bool => str_starts_with($key, 'page_help_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
