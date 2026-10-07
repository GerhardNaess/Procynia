<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: every `procynia.compliance.*` key the backend of Etterlevelse og revisjon asks for exists
 * in both languages — a missing one is shown to the user as the raw key. Five Kvalitet link
 * messages once pointed at `compliance.quality.validation.*` instead of
 * `compliance.validation.quality.*`, and nothing caught it.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the source and language files only.
 */
class ComplianceTranslationKeysTest extends TestCase
{
    public function test_every_compliance_key_the_backend_uses_exists_in_both_languages(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root.'/app/Services/Compliance/*.php'),
            glob($root.'/app/Http/Controllers/App/Compliance*.php'),
            glob($root.'/app/Support/Compliance/*.php'),
            [$root.'/app/Services/Improvements/ImprovementCaseCreator.php'],
        );
        $keys = [];

        foreach ($files as $file) {
            preg_match_all("/(?:__|trans_choice)\\('procynia\\.((?:compliance|improvements)\\.[a-z0-9_.]+)'/", (string) file_get_contents($file), $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        $keys = array_values(array_unique($keys));
        $this->assertGreaterThan(50, count($keys), 'The scan must find the module\'s keys.');

        foreach (['no', 'en'] as $locale) {
            $strings = require $root."/lang/{$locale}/procynia.php";

            foreach ($keys as $key) {
                $value = $strings;
                foreach (explode('.', $key) as $segment) {
                    $value = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : null;
                }

                $this->assertNotNull($value, "lang/{$locale}: procynia.{$key} does not exist.");
            }
        }
    }
}
