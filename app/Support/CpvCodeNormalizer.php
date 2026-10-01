<?php

namespace App\Support;

/**
 * What a CPV code is, in the one place that decides it.
 *
 * A CPV code is eight digits. It is commonly written with a ninth — "90910000-9" — which is a
 * check digit, not part of the classification: it exists so a typo in the eight can be caught, and
 * the EU's own catalogue, Procynia's catalogue table and every watch rule stored here hold the bare
 * eight.
 *
 * Six places in the codebase decided this for themselves, and they did not agree. Most reduced a
 * code to its digits, which is right about punctuation — "90.910.000" and "90 910 000" are the same
 * classification — and wrong about the check digit: it turned "90910000-9" into the nine-digit
 * string "909100009", which equals no code that exists. Two more compared trimmed strings as they
 * came, so punctuation broke them as well. The effect was a silent miss rather than an error: a
 * register that returned codes in the written form would have matched nothing at all, and the watch
 * inbox it fed would simply have looked empty.
 *
 * The rule is therefore stated once, here, and it is deliberately narrow:
 *
 *   - punctuation and whitespace are not part of the code, anywhere in it
 *   - eight digits is a code; nine digits is a code with its check digit, and the ninth is dropped
 *   - anything else — too short, too long, empty, non-scalar — is not a CPV code and is rejected
 *
 * The rejection is the point of the last line. Returning a partial value such as "909" would make
 * it a prefix that matches by accident; returning null makes a caller say what it wants to do about
 * input that is not a code. Nothing here guesses.
 *
 * Note what this is not: it does not verify the check digit. A wrong ninth digit is dropped like a
 * right one. Validating it would turn a mistyped catalogue code into a silent non-match, which is
 * the failure this class exists to remove.
 */
final class CpvCodeNormalizer
{
    /** The classification itself. The ninth digit, when written, is a check digit. */
    public const CODE_LENGTH = 8;

    /**
     * The eight digits of a CPV code, or null when the value is not one.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        $length = strlen($digits);

        if ($length !== self::CODE_LENGTH && $length !== self::CODE_LENGTH + 1) {
            return null;
        }

        return substr($digits, 0, self::CODE_LENGTH);
    }

    /**
     * The same rule over a list: normalised, deduplicated, and with the non-codes dropped.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    public static function normalizeMany(iterable $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): ?string => self::normalize($value))
            ->filter(fn (?string $code): bool => $code !== null)
            ->unique()
            ->values()
            ->all();
    }
}
