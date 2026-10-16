<?php

namespace App\Filament\Support;

/**
 * Admin-panel text with a Norwegian fallback — the Blade counterpart of the customer frontend's
 * `translations.a11y.key ?? 'Norsk tekst'` pattern (WCAG Fase 6, A11Y-105).
 *
 * `__()` returns the key itself when a translation is missing, which would put a dotted key on screen
 * (and in front of a screen reader). Until a key exists in lang/<locale>/procynia.php, the given
 * fallback is shown instead. Presentation only: never used for data, validation or authorization.
 */
final class AdminText
{
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, string $fallback, array $replace = []): string
    {
        $fullKey = 'procynia.'.$key;

        if (trans()->has($fullKey)) {
            return (string) __($fullKey, $replace);
        }

        foreach ($replace as $name => $value) {
            $fallback = str_replace(':'.$name, (string) $value, $fallback);
        }

        return $fallback;
    }
}
