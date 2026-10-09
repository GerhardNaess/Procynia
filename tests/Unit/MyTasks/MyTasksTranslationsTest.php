<?php

namespace Tests\Unit\MyTasks;

use PHPUnit\Framework\TestCase;

/**
 * Purpose: the strings «Mine oppgaver» and the supplier notification use exist in both languages with
 * the same keys, and every key the new backend code and the task cards ask for is there — a missing
 * one would reach the user as a raw key or a Norwegian fallback in English.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads the source and language files only.
 */
class MyTasksTranslationsTest extends TestCase
{
    public function test_both_languages_have_the_same_keys(): void
    {
        [$no, $en] = $this->languages();

        foreach (['info_center_page', 'supplier_management.notifications'] as $section) {
            $this->assertSame($this->keys($this->at($no, $section)), $this->keys($this->at($en, $section)), $section);
        }
    }

    public function test_every_key_the_code_asks_for_exists(): void
    {
        [$no, $en] = $this->languages();
        $root = dirname(__DIR__, 3);
        $backend = implode("\n", array_map('file_get_contents', [
            $root.'/app/Http/Controllers/App/InfoCenterController.php',
            $root.'/app/Services/Suppliers/SupplierNotificationService.php',
        ]));
        preg_match_all("/__\\('procynia\\.((?:info_center_page|supplier_management\\.notifications)\\.[a-z0-9_.]+)'/", $backend, $matches);
        $keys = array_unique($matches[1]);

        // The card reads `t.<key>` (MyTasks.jsx) and `mt.<key>` (Index.jsx) from info_center_page.my_tasks.
        $frontend = file_get_contents($root.'/resources/js/Pages/App/InfoCenter/MyTasks.jsx')
            .file_get_contents($root.'/resources/js/Pages/App/InfoCenter/myTaskLabels.js');
        preg_match_all('/\b(?:t|mt)(?:\?)?\.([a-z_]+)\b/', $frontend, $cardMatches);

        foreach (array_unique($cardMatches[1]) as $key) {
            $keys[] = 'info_center_page.my_tasks.'.$key;
        }

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertNotNull($this->at($no, $key), "no: {$key}");
            $this->assertNotNull($this->at($en, $key), "en: {$key}");
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function languages(): array
    {
        $root = dirname(__DIR__, 3);

        return [require $root.'/lang/no/procynia.php', require $root.'/lang/en/procynia.php'];
    }

    private function at(array $strings, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($strings) || ! array_key_exists($segment, $strings)) {
                return null;
            }

            $strings = $strings[$segment];
        }

        return $strings;
    }

    /** @return list<string> */
    private function keys(mixed $strings, string $prefix = ''): array
    {
        if (! is_array($strings)) {
            return [rtrim($prefix, '.')];
        }

        $keys = [];

        foreach ($strings as $key => $value) {
            array_push($keys, ...$this->keys($value, $prefix.$key.'.'));
        }

        sort($keys);

        return $keys;
    }
}
