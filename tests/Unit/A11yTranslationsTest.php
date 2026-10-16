<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: the shared accessibility strings (`procynia.a11y.*`) exist with the same keys in Norwegian
 * and English, none empty. They are mostly heard, not seen — an accessible name a screen reader
 * speaks — so a missing English key would read Norwegian to an English user without anyone noticing
 * on screen (WCAG 3.1.2).
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the language files only.
 */
class A11yTranslationsTest extends TestCase
{
    public function test_shared_accessibility_strings_exist_in_both_languages(): void
    {
        $root = dirname(__DIR__, 2);
        $no = (require $root.'/lang/no/procynia.php')['a11y'] ?? null;
        $en = (require $root.'/lang/en/procynia.php')['a11y'] ?? null;

        $this->assertIsArray($no);
        $this->assertIsArray($en);
        $this->assertSame(array_keys($no), array_keys($en));

        foreach ([$no, $en] as $strings) {
            foreach ($strings as $key => $value) {
                $this->assertIsString($value, "a11y.{$key}");
                $this->assertNotSame('', trim($value), "a11y.{$key}");
            }
        }
    }

    public function test_the_strings_are_shared_with_the_frontend(): void
    {
        $middleware = (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/HandleInertiaRequests.php');

        $this->assertStringContainsString("'a11y' => __('procynia.a11y'),", $middleware);
    }
}
