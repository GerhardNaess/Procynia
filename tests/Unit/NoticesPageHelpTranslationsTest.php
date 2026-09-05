<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: The Notices page help must describe the actions the work list actually offers —
 * live search, private requests, opening a case, editing deadlines, Business Review, and moving
 * a case to history — in both supported languages. Business Review lives under "Edit deadlines"
 * on this page, supports several dates, and is a planned checkpoint rather than a status change,
 * so the help must say exactly that and must not conflate history placement with the No-Go decision.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class NoticesPageHelpTranslationsTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'page_help_section_find',
        'page_help_item_live_title',
        'page_help_item_live_text',
        'page_help_item_alerts_title',
        'page_help_item_alerts_text',
        'page_help_item_private_request_title',
        'page_help_item_private_request_text',
        'page_help_item_save_title',
        'page_help_item_save_text',
        'page_help_section_worklist',
        'page_help_item_saved_title',
        'page_help_item_saved_text',
        'page_help_item_open_case_title',
        'page_help_item_open_case_text',
        'page_help_section_deadlines',
        'page_help_item_deadlines_title',
        'page_help_item_deadlines_text',
        'page_help_item_business_review_title',
        'page_help_item_business_review_text',
        'page_help_section_history',
        'page_help_item_history_move_title',
        'page_help_item_history_move_text',
        'page_help_item_history_readable_title',
        'page_help_item_history_readable_text',
        'page_help_item_history_resave_title',
        'page_help_item_history_resave_text',
        'page_help_item_history_scope_title',
        'page_help_item_history_scope_text',
        'page_help_section_doffin',
        'page_help_item_doffin_title',
        'page_help_item_doffin_text',
    ];

    public function test_both_languages_define_the_notices_page_help_strings(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->noticeStrings($locale);

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

    public function test_the_business_review_help_points_to_the_edit_deadlines_action(): void
    {
        $this->assertStringContainsString(
            'Rediger frister',
            $this->noticeStrings('no')['page_help_item_business_review_text'],
        );

        $this->assertStringContainsString(
            'Edit deadlines',
            $this->noticeStrings('en')['page_help_item_business_review_text'],
        );
    }

    public function test_the_business_review_help_allows_several_dates_between_rfi_and_rfp(): void
    {
        $norwegian = $this->noticeStrings('no')['page_help_item_business_review_text'];
        $this->assertStringContainsString('ett eller flere', $norwegian);
        $this->assertStringContainsString('mellom RFI og RFP', $norwegian);

        $english = $this->noticeStrings('en')['page_help_item_business_review_text'];
        $this->assertStringContainsString('one or more', $english);
        $this->assertStringContainsString('between RFI and RFP', $english);
    }

    public function test_the_business_review_help_does_not_present_it_as_a_status_change(): void
    {
        $this->assertStringContainsString(
            'endrer ikke statusen',
            $this->noticeStrings('no')['page_help_item_business_review_text'],
        );

        $this->assertStringContainsString(
            'do not change the status',
            $this->noticeStrings('en')['page_help_item_business_review_text'],
        );
    }

    public function test_the_history_help_keeps_placement_apart_from_the_no_go_decision(): void
    {
        $norwegian = $this->noticeStrings('no')['page_help_item_history_scope_text'];
        $this->assertStringContainsString('hvor saken ligger', $norwegian);
        $this->assertStringContainsString('No-Go', $norwegian);

        $english = $this->noticeStrings('en')['page_help_item_history_scope_text'];
        $this->assertStringContainsString('where a case sits', $english);
        $this->assertStringContainsString('No-Go', $english);
    }

    public function test_the_page_help_covers_the_actions_the_work_list_offers(): void
    {
        $norwegian = $this->noticeStrings('no');
        $this->assertStringContainsString('Åpne sak', $norwegian['page_help_item_open_case_title']);
        $this->assertStringContainsString('Rediger frister', $norwegian['page_help_item_deadlines_title']);
        $this->assertStringContainsString('Flytt til historikk', $norwegian['page_help_item_history_move_title']);
        $this->assertStringContainsString('Åpne i Doffin', $norwegian['page_help_item_doffin_title']);

        $english = $this->noticeStrings('en');
        $this->assertStringContainsString('Open case', $english['page_help_item_open_case_title']);
        $this->assertStringContainsString('Edit deadlines', $english['page_help_item_deadlines_title']);
        $this->assertStringContainsString('Move to history', $english['page_help_item_history_move_title']);
        $this->assertStringContainsString('Open in Doffin', $english['page_help_item_doffin_title']);
    }

    public function test_the_history_help_states_that_moving_a_case_there_is_final(): void
    {
        $norwegian = $this->noticeStrings('no');
        $this->assertStringContainsString('endelig i den vanlige arbeidsflyten', $norwegian['page_help_item_history_move_text']);
        $this->assertStringContainsString('kan fortsatt åpne og lese saken i Historikk', $norwegian['page_help_item_history_readable_text']);
        $this->assertStringContainsString('kan ikke flyttes tilbake til den aktive arbeidslisten', $norwegian['page_help_item_history_readable_text']);

        $english = $this->noticeStrings('en');
        $this->assertStringContainsString('final in the ordinary workflow', $english['page_help_item_history_move_text']);
        $this->assertStringContainsString('can still open and read the case in History', $english['page_help_item_history_readable_text']);
        $this->assertStringContainsString('cannot be moved back to the active work list', $english['page_help_item_history_readable_text']);
    }

    public function test_the_history_help_covers_public_notices_and_private_requests_alike(): void
    {
        $norwegian = $this->noticeStrings('no')['page_help_item_history_readable_text'];
        $this->assertStringContainsString('offentlige kunngjøringer', $norwegian);
        $this->assertStringContainsString('private forespørsler', $norwegian);

        $english = $this->noticeStrings('en')['page_help_item_history_readable_text'];
        $this->assertStringContainsString('public notices', $english);
        $this->assertStringContainsString('private requests', $english);
    }

    public function test_the_history_help_explains_the_in_history_state_in_live_search_and_alerts(): void
    {
        $norwegian = $this->noticeStrings('no')['page_help_item_history_resave_text'];
        $this->assertStringContainsString('I historikk', $norwegian);
        $this->assertStringContainsString('Live-søk og Varsler', $norwegian);
        $this->assertStringContainsString('blir liggende i Historikk', $norwegian);

        $english = $this->noticeStrings('en')['page_help_item_history_resave_text'];
        $this->assertStringContainsString('In history', $english);
        $this->assertStringContainsString('live search and alerts', $english);
        $this->assertStringContainsString('stays in History', $english);
    }

    public function test_the_history_help_never_offers_to_restore_an_archived_case(): void
    {
        $keys = [
            'page_help_item_history_move_text',
            'page_help_item_history_readable_text',
            'page_help_item_history_resave_text',
            'page_help_item_history_scope_text',
        ];

        $norwegian = mb_strtolower(implode(' ', array_map(
            fn (string $key): string => $this->noticeStrings('no')[$key],
            $keys,
        )));

        foreach (['hentes tilbake', 'reaktiver', 'gjenopprett'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $norwegian);
        }

        $english = mb_strtolower(implode(' ', array_map(
            fn (string $key): string => $this->noticeStrings('en')[$key],
            $keys,
        )));

        foreach (['restore', 'reactivate', 'brought back'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $english);
        }
    }

    public function test_the_open_case_item_does_not_document_the_case_view(): void
    {
        // "Åpne sak" leads to another page. The help may say where the button goes,
        // but must not explain what the case view itself offers.
        $norwegian = mb_strtolower($this->noticeStrings('no')['page_help_item_open_case_text']);
        foreach (['no-go', 'fasekommentar', 'beslutningshistorikk', 'statushandling'] as $term) {
            $this->assertStringNotContainsString($term, $norwegian);
        }

        $english = mb_strtolower($this->noticeStrings('en')['page_help_item_open_case_text']);
        foreach (['no-go', 'phase comment', 'decision history', 'status action'] as $term) {
            $this->assertStringNotContainsString($term, $english);
        }
    }

    public function test_the_page_help_does_not_document_the_case_view_workflow(): void
    {
        $offPageTerms = [
            'no' => ['gjenåpne sak', 'fasekommentar', 'beslutningshistorikk', 'svarutkast'],
            'en' => ['reopen case', 'phase comment', 'answer draft'],
        ];

        foreach ($offPageTerms as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->pageHelpStrings($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $strings,
                    "The notices page help must not document '{$term}', which belongs to the case view.",
                );
            }
        }
    }

    public function test_the_page_help_avoids_internal_code_terms(): void
    {
        $forbidden = [
            'archived_at',
            'history_type',
            'bid_status',
            'saved_notices',
            'controller',
            'service',
        ];

        foreach (['no', 'en'] as $locale) {
            $strings = mb_strtolower(implode(' || ', $this->pageHelpStrings($locale)));

            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $strings,
                    "lang/{$locale}/procynia.php page help must not use the internal term '{$term}'.",
                );
            }
        }
    }

    public function test_the_archive_dialog_states_that_the_move_is_irreversible(): void
    {
        $norwegian = $this->noticeStrings('no');
        $this->assertSame('Flytt saken til historikk?', $norwegian['archive_dialog_title']);
        $this->assertStringContainsString('kan ikke flyttes tilbake', $norwegian['archive_dialog_description']);
        $this->assertStringContainsString('åpne og lese saken i Historikk', $norwegian['archive_dialog_description']);
        $this->assertSame('Flytt til historikk', $norwegian['archive_dialog_confirm']);

        $english = $this->noticeStrings('en');
        $this->assertSame('Move the case to history?', $english['archive_dialog_title']);
        $this->assertStringContainsString('cannot be moved back', $english['archive_dialog_description']);
        $this->assertStringContainsString('open and read the case in History', $english['archive_dialog_description']);
        $this->assertSame('Move to history', $english['archive_dialog_confirm']);
    }

    public function test_the_archive_dialog_does_not_claim_a_no_go_case_can_never_be_reopened(): void
    {
        $norwegian = $this->noticeStrings('no')['archive_dialog_no_go_note'];
        $this->assertStringContainsString('Gjenåpne sak', $norwegian);
        $this->assertStringNotContainsString('aldri', mb_strtolower($norwegian));

        $english = $this->noticeStrings('en')['archive_dialog_no_go_note'];
        $this->assertStringContainsString('Reopen case', $english);
        $this->assertStringNotContainsString('never', mb_strtolower($english));
    }

    public function test_the_already_in_history_message_says_the_case_cannot_come_back(): void
    {
        $this->assertStringContainsString(
            'kan ikke flyttes tilbake',
            $this->noticeStrings('no')['save_already_in_history'],
        );

        $this->assertStringContainsString(
            'cannot be moved back',
            $this->noticeStrings('en')['save_already_in_history'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function noticeStrings(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['notices'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function pageHelpStrings(string $locale): array
    {
        return array_filter(
            $this->noticeStrings($locale),
            static fn (string $key): bool => str_starts_with($key, 'page_help_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
