<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: The Wiki page view's help must describe the workflow that now exists — page owner sends
 * the page to a named reviewer, document owners vouch for their own sources, the reviewer approves
 * and publishes, and either can send it back with a reason. It previously described a System
 * Owner-only flow with no recipient and no notification, which stopped being true in steps 5, 7 and
 * 9, and it claimed a new version becomes live immediately, which step 3 reversed.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class WikiShowPageHelpTest extends TestCase
{
    private const SECTIONS = [
        'show_page_help_section_about',
        'show_page_help_section_versions',
        'show_page_help_section_responsibility',
        'show_page_help_section_changes',
        'show_page_help_section_best_practice',
        'show_page_help_section_blocking',
    ];

    private const ITEMS = [
        'show_page_help_item_about',
        'show_page_help_item_ai',
        'show_page_help_item_type',
        'show_page_help_item_visibility',
        'show_page_help_item_published',
        'show_page_help_item_working',
        'show_page_help_item_owner',
        'show_page_help_item_document_owner',
        'show_page_help_item_reviewer',
        'show_page_help_item_submit',
        'show_page_help_item_publish',
        'show_page_help_item_changes_who',
        'show_page_help_item_changes_reason',
        'show_page_help_item_changes_resubmit',
    ];

    public function test_both_languages_define_every_section_item_and_hint(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->wiki($locale);

            foreach (self::SECTIONS as $key) {
                $this->assertArrayHasKey($key, $strings, "Missing '{$key}' in lang/{$locale}/procynia.php.");
            }

            foreach (self::ITEMS as $item) {
                foreach (['_title', '_text'] as $suffix) {
                    $this->assertArrayHasKey($item.$suffix, $strings, "Missing '{$item}{$suffix}' in lang/{$locale}.");
                    $this->assertNotSame('', trim((string) $strings[$item.$suffix]));
                }
            }

            $this->assertArrayHasKey('show_page_help_submit_hint', $strings);
            $this->assertArrayHasKey('show_page_help_submit_hint_label', $strings);
        }
    }

    // M. the two languages stay in step
    public function test_both_languages_expose_the_same_show_help_keys(): void
    {
        $keys = static function (array $strings): array {
            $found = array_keys(array_filter(
                $strings,
                static fn (string $key): bool => str_starts_with($key, 'show_page_help_'),
                ARRAY_FILTER_USE_KEY,
            ));
            sort($found);

            return $found;
        };

        $this->assertSame($keys($this->wiki('no')), $keys($this->wiki('en')));
    }

    // A + B. the distinction the page exists to make
    public function test_the_help_explains_that_a_page_can_have_both_a_published_and_a_working_version(): void
    {
        $norwegian = mb_strtolower($this->wiki('no')['show_page_help_item_working_text']);
        $this->assertStringContainsString('arbeidsversjon', $norwegian);
        $this->assertStringContainsString('publiserte versjon', $norwegian);

        $english = mb_strtolower($this->wiki('en')['show_page_help_item_working_text']);
        $this->assertStringContainsString('working version', $english);
        $this->assertStringContainsString('published version', $english);
    }

    public function test_the_help_says_the_working_version_changes_nothing_until_it_is_approved(): void
    {
        $this->assertStringContainsString('godkjennes', $this->wiki('no')['show_page_help_item_working_title']);
        $this->assertStringContainsString('approved', $this->wiki('en')['show_page_help_item_working_title']);
    }

    // C, D, E. three responsibilities, kept apart
    public function test_the_help_describes_the_page_owner_as_responsible_for_the_page(): void
    {
        $this->assertStringContainsString('Sideeier', $this->wiki('no')['show_page_help_item_owner_title']);
        $this->assertStringContainsString('Page owner', $this->wiki('en')['show_page_help_item_owner_title']);
    }

    public function test_the_help_describes_document_owner_as_a_source_check_not_publication(): void
    {
        // The distinction people got wrong: signing off your own document is not publishing a page.
        $norwegian = mb_strtolower($this->wiki('no')['show_page_help_item_document_owner_text']);
        $this->assertStringContainsString('kildedokument', $norwegian);
        $this->assertStringContainsString('ikke en publisering', $norwegian);

        $english = mb_strtolower($this->wiki('en')['show_page_help_item_document_owner_text']);
        $this->assertStringContainsString('source document', $english);
        $this->assertStringContainsString('does not publish', $english);
    }

    public function test_the_help_describes_the_reviewer_as_the_final_decision(): void
    {
        $norwegian = mb_strtolower($this->wiki('no')['show_page_help_item_reviewer_text']);
        $this->assertStringContainsString('endelige', $norwegian);
        $this->assertStringContainsString('publiseres', $norwegian);

        $english = mb_strtolower($this->wiki('en')['show_page_help_item_reviewer_text']);
        $this->assertStringContainsString('final review', $english);
        $this->assertStringContainsString('published', $english);
    }

    // F. submitting names somebody
    public function test_the_help_says_a_reviewer_is_chosen_when_submitting(): void
    {
        $norwegian = mb_strtolower($this->wiki('no')['show_page_help_item_submit_text']);
        $this->assertStringContainsString('velger', $norwegian);
        $this->assertStringContainsString('kontrollør', $norwegian);

        $english = mb_strtolower($this->wiki('en')['show_page_help_item_submit_text']);
        $this->assertStringContainsString('picks', $english);
        $this->assertStringContainsString('reviewer', $english);
    }

    // I. the action is named as what it does
    public function test_the_help_uses_the_approve_and_publish_wording_from_the_screen(): void
    {
        $this->assertStringContainsString('Godkjenn og publiser', $this->wiki('no')['show_page_help_item_publish_title']);
        $this->assertStringContainsString('Approve and publish', $this->wiki('en')['show_page_help_item_publish_title']);
    }

    // G + H. a return is not a refusal, and it carries a reason
    public function test_the_help_uses_changes_requested_rather_than_a_terminal_rejection(): void
    {
        $this->assertSame('Endringer kreves', $this->wiki('no')['show_page_help_section_changes']);
        $this->assertSame('Changes requested', $this->wiki('en')['show_page_help_section_changes']);

        foreach (['no', 'en'] as $locale) {
            $strings = mb_strtolower(implode(' || ', $this->showHelp($locale)));

            foreach (['avvist', 'rejected'] as $terminal) {
                $this->assertStringNotContainsString(
                    $terminal,
                    $strings,
                    "Wiki page help still calls a returned page '{$terminal}'.",
                );
            }
        }
    }

    public function test_the_help_says_a_reason_accompanies_a_return(): void
    {
        $norwegian = mb_strtolower($this->wiki('no')['show_page_help_item_changes_reason_text']);
        $this->assertStringContainsString('må skrive', $norwegian);

        $english = mb_strtolower($this->wiki('en')['show_page_help_item_changes_reason_text']);
        $this->assertStringContainsString('must write', $english);
    }

    public function test_the_help_says_a_return_leaves_the_published_version_alone(): void
    {
        $this->assertStringContainsString('berøres ikke', $this->wiki('no')['show_page_help_item_changes_who_text']);
        $this->assertStringContainsString('untouched', $this->wiki('en')['show_page_help_item_changes_who_text']);
    }

    // Notification is mentioned as a consequence, not documented as a system
    public function test_the_help_mentions_that_responsibility_is_notified(): void
    {
        $this->assertStringContainsString('varslet', mb_strtolower($this->wiki('no')['show_page_help_item_submit_text']));
        $this->assertStringContainsString('notified', mb_strtolower($this->wiki('en')['show_page_help_item_submit_text']));
    }

    // The two axes that are still worth separating
    public function test_the_help_keeps_ai_generated_and_page_type_apart_from_status(): void
    {
        $this->assertStringContainsString('ikke en status', $this->wiki('no')['show_page_help_item_ai_title']);
        $this->assertStringContainsString('not a status', $this->wiki('en')['show_page_help_item_ai_title']);
        $this->assertStringContainsString('sidetype', $this->wiki('no')['show_page_help_item_type_title']);
        $this->assertStringContainsString('page type', $this->wiki('en')['show_page_help_item_type_title']);
    }

    // J. every claim the old help made that is now false
    public function test_the_help_no_longer_makes_the_old_false_claims(): void
    {
        $forbidden = [
            'no' => [
                'ingen mottaker',
                'ingen varsling',
                'bare system owner',
                'kun system owner',
                'system owner kan deretter',
                'blir gjeldende med én gang',
                'ingen tidligere godkjent versjon',
                'endrer status, ingenting annet',
            ],
            'en' => [
                'no particular person',
                'no notification',
                'only a system owner',
                'system owners only',
                'becomes current immediately',
                'no previously approved version',
                'changes the status, nothing else',
            ],
        ];

        foreach ($forbidden as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->showHelp($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $strings, "Wiki page help still claims '{$term}'.");
            }
        }
    }

    public function test_the_help_does_not_present_system_owner_as_the_normal_flow(): void
    {
        foreach (['no', 'en'] as $locale) {
            $this->assertStringNotContainsString(
                'system owner',
                mb_strtolower(implode(' || ', $this->showHelp($locale))),
                'System Owner is an administrative exception, not the workflow this page describes.',
            );
        }
    }

    // L. this page only
    public function test_the_help_documents_only_the_wiki_page_view(): void
    {
        $offPage = [
            'no' => ['dashboard', 'kunngjøring', 'watch profile', 'abonnement', 'kundemiljø', 'varselsenter'],
            'en' => ['dashboard', 'doffin', 'tender notice', 'watch profile', 'subscription', 'notification centre'],
        ];

        foreach ($offPage as $locale => $terms) {
            $strings = mb_strtolower(implode(' || ', $this->showHelp($locale)));

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $strings, "Wiki page help documents '{$term}'.");
            }
        }
    }

    public function test_the_help_does_not_leak_implementation_detail(): void
    {
        $forbidden = ['published_version_id', 'is_current', 'reviewer_user_id', 'submitted_by', 'approve_wiki_pages', 'catalogbuilder', 'migrasjon'];

        foreach (['no', 'en'] as $locale) {
            $strings = mb_strtolower(implode(' || ', $this->showHelp($locale)));

            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString($term, $strings, "Wiki page help leaks '{$term}'.");
            }
        }
    }

    // K. the tooltip on the button says the same thing, shorter
    public function test_the_submit_hint_matches_the_flow(): void
    {
        $norwegian = mb_strtolower($this->wiki('no')['show_page_help_submit_hint']);
        $this->assertStringContainsString('kontrollør', $norwegian);
        $this->assertStringContainsString('dokumenteiere', $norwegian);
        $this->assertStringNotContainsString('ingen varsles', $norwegian);

        $english = mb_strtolower($this->wiki('en')['show_page_help_submit_hint']);
        $this->assertStringContainsString('reviewer', $english);
        $this->assertStringContainsString('document owners', $english);
        $this->assertStringNotContainsString('nobody is notified', $english);
    }

    public function test_the_help_stays_short_enough_to_read(): void
    {
        // Six sections is already the upper bound for something opened mid-task.
        foreach (['no', 'en'] as $locale) {
            $strings = $this->showHelp($locale);

            foreach ($strings as $key => $value) {
                if (! str_ends_with($key, '_text')) {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    320,
                    mb_strlen((string) $value),
                    "Help item '{$key}' reads as documentation rather than help.",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function wiki(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['wiki'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function showHelp(string $locale): array
    {
        return array_filter(
            $this->wiki($locale),
            static fn (string $key): bool => str_starts_with($key, 'show_page_help_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
