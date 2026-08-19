<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "3 påstander krever behandling" is only useful if the three things are named, the reason for each
 * is in plain language, and the two decisions are spelled out. All of that copy must exist in both
 * languages — and it must not fall back to the internal vocabulary (funn, claim, defect) the
 * cleanup was meant to remove from the reader's path.
 */
class WikiOpenClaimWorklistTranslationsTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'verification_worklist_intro',
        'verification_worklist_position',
        'verification_worklist_shared_decision',
        'verification_worklist_open_entry',
        'verification_worklist_reason_best_practice',
        'verification_worklist_reason_defect',
        'verification_worklist_reason_conflict',
        'verification_worklist_reason_missing_source',
        'verification_worklist_reason_missing_excerpt',
        'verification_worklist_reason_other',
        'verification_worklist_untitled',
        'claim_card_why_label',
        'claim_card_what_label',
        'claim_card_reference_label',
        'claim_card_shared_decision',
        'claim_card_best_practice_explanation',
        'claim_card_best_practice_edit_hint',
        'claim_card_keep_text',
        'claim_card_remove_text',
        'wiki_block_edit_label',
    ];

    public function test_both_languages_define_every_work_list_string(): void
    {
        foreach (['no', 'en'] as $locale) {
            $wiki = $this->wikiStrings($locale);

            foreach (self::REQUIRED_KEYS as $key) {
                $this->assertArrayHasKey($key, $wiki, "Missing '{$key}' in lang/{$locale}/procynia.php.");
                $this->assertNotSame('', trim((string) $wiki[$key]));
            }
        }
    }

    public function test_the_position_and_shared_decision_strings_carry_their_placeholders(): void
    {
        foreach (['no', 'en'] as $locale) {
            $wiki = $this->wikiStrings($locale);

            $this->assertStringContainsString(':position', $wiki['verification_worklist_position']);
            $this->assertStringContainsString(':total', $wiki['verification_worklist_position']);
            $this->assertStringContainsString(':count', $wiki['verification_worklist_shared_decision']);
            $this->assertStringContainsString(':count', $wiki['claim_card_shared_decision']);
            $this->assertStringContainsString(':id', $wiki['claim_card_reference_label']);
        }
    }

    public function test_the_norwegian_decision_actions_say_what_happens_to_the_text(): void
    {
        $wiki = $this->wikiStrings('no');

        $this->assertSame('Behold teksten', $wiki['claim_card_keep_text']);
        $this->assertSame('Fjern teksten', $wiki['claim_card_remove_text']);
        $this->assertSame('Hvorfor må du vurdere dette?', $wiki['claim_card_why_label']);
        $this->assertSame('Hva skal du gjøre?', $wiki['claim_card_what_label']);
    }

    /**
     * The two badges that used to sit next to each other and say the same thing. "Samlet vurdering"
     * gave the reader nothing to act on, and the addition badge repeated the explanation below it.
     */
    public function test_the_cryptic_badge_wording_was_replaced(): void
    {
        $no = $this->wikiStrings('no');
        $en = $this->wikiStrings('en');

        $this->assertStringNotContainsString('Samlet vurdering', $no['verification_basis_block_claim_count']);
        $this->assertStringNotContainsString('Combined review', $en['verification_basis_block_claim_count']);
        $this->assertSame('Forslag basert på beste praksis', $no['verification_basis_best_practice_badge']);
        $this->assertStringNotContainsString('Tillegg', $no['verification_basis_best_practice_badge']);
    }

    public function test_the_best_practice_explanation_states_both_the_origin_and_the_decision(): void
    {
        $no = $this->wikiStrings('no')['claim_card_best_practice_explanation'];

        $this->assertStringContainsString('beste praksis', mb_strtolower($no));
        $this->assertStringContainsString('kilde', mb_strtolower($no));
        $this->assertStringContainsString('beholdes', mb_strtolower($no));
    }

    public function test_the_empty_state_is_kept(): void
    {
        foreach (['no', 'en'] as $locale) {
            $this->assertNotSame('', trim((string) $this->wikiStrings($locale)['verification_basis_no_open_claims']));
        }
    }

    /**
     * @return array<string, string>
     */
    private function wikiStrings(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['wiki'] ?? [];
    }
}
