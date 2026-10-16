<?php

namespace App\Support;

/**
 * A decimal number typed the way the reader writes it, read back as the plain value the server
 * stores: digits, an optional leading minus and a point before the decimals («1250», «12.5»).
 *
 * The same reading rules as the KPI fields (resources/js/Pages/App/Objectives/kpiNumber.js), so a
 * form that sends what the person typed is understood exactly as the field's own check understood
 * it, and the server stays the one that decides:
 *  - A plain value is taken as it is. That is what the forms send after reading the field, and
 *    what was stored and accepted before, so «12.5» keeps meaning twelve and a half.
 *  - Norwegian: a comma is the decimal separator and a space separates thousands —
 *    «12,5», «1 250», «1 250,75».
 *  - English: a comma separates thousands and a point is the decimal separator — «1,250.75».
 * Thousands come in groups of three after a first group of one to three digits. Anything else —
 * «1.250,5», «1,2,3», «12,5» in English — is returned unchanged, so the field's own rule refuses it
 * instead of the server guessing what was meant.
 */
final class LocalizedNumber
{
    // Every kind of space a number may carry: typed, pasted, or a non-breaking one from formatting.
    private const SPACES = '/[\s\x{00A0}\x{202F}]+/u';

    public static function normalize(mixed $value, ?string $locale = null): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $text = trim((string) preg_replace(self::SPACES, ' ', $value));

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        $english = str_starts_with(strtolower($locale ?? app()->getLocale()), 'en');
        [$group, $decimal] = $english ? [',', '.'] : [' ', ','];
        $groups = $english ? '[, ]' : ' ';

        // One kind of thousands separator per number, never mixed («1,250 000» is refused).
        $grouped = '/^(-?)(\d{1,3}(?:('.$groups.')\d{3})?(?:\3\d{3})*)(?:'.preg_quote($decimal, '/').'(\d+))?$/';
        $ungrouped = '/^(-?)(\d+)(?:'.preg_quote($decimal, '/').'(\d+))?$/';

        if (preg_match($ungrouped, $text, $match) === 1) {
            return $match[1].$match[2].(isset($match[3]) && $match[3] !== '' ? '.'.$match[3] : '');
        }

        if (preg_match($grouped, $text, $match) === 1) {
            $whole = str_replace([$group, ' '], '', $match[2]);

            return $match[1].$whole.(isset($match[4]) && $match[4] !== '' ? '.'.$match[4] : '');
        }

        return $value;
    }
}
