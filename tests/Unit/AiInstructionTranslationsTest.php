<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: The AI-instruction page must state its customer-wide scope in both supported languages,
 * and must not describe itself as case-scoped or claim to influence the AI's way of working
 * (retrieval/discovery), which it does not.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class AiInstructionTranslationsTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'instructions_page_scope_notice',
        'instructions_page_subtitle',
        'instructions_page_help_intro',
        'instructions_page_help_item_scope_title',
        'instructions_page_help_item_scope_text',
        'instructions_page_help_item_what_text',
    ];

    public function test_both_languages_define_the_ai_instruction_scope_strings(): void
    {
        foreach (['no', 'en'] as $locale) {
            $strings = $this->aiStrings($locale);

            foreach (self::REQUIRED_KEYS as $key) {
                $this->assertArrayHasKey($key, $strings, "Missing '{$key}' in lang/{$locale}/procynia.php.");
                $this->assertNotSame('', trim((string) $strings[$key]));
            }
        }
    }

    public function test_the_norwegian_scope_text_says_the_instruction_applies_to_all_cases(): void
    {
        $strings = $this->aiStrings('no');

        $this->assertStringContainsString('alle saker', $strings['instructions_page_scope_notice']);
        $this->assertStringContainsString('alle saker', $strings['instructions_page_help_item_scope_text']);
    }

    public function test_the_english_scope_text_says_the_instruction_applies_to_every_case(): void
    {
        $strings = $this->aiStrings('en');

        $this->assertStringContainsString('every case', $strings['instructions_page_scope_notice']);
        $this->assertStringContainsString('every case', $strings['instructions_page_help_item_scope_text']);
    }

    public function test_the_subtitle_no_longer_claims_the_instruction_governs_the_ai_way_of_working(): void
    {
        $this->assertStringNotContainsString('arbeidsmåte', $this->aiStrings('no')['instructions_page_subtitle']);
        $this->assertStringNotContainsString('approach', $this->aiStrings('en')['instructions_page_subtitle']);
    }

    /**
     * @return array<string, string>
     */
    private function aiStrings(string $locale): array
    {
        $translations = require dirname(__DIR__, 2)."/lang/{$locale}/procynia.php";

        return $translations['ai'] ?? [];
    }
}
