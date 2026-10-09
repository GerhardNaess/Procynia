<?php

namespace App\Services\Suppliers\Import;

/**
 * Reads a cell's text as one of a fixed list of codes: the code itself, or its label in Norwegian or
 * English, ignoring case, spacing and punctuation — «Ikke avklart», «ikke-avklart» and «unknown» are
 * the same answer. Nothing outside the list is ever accepted or created: an unknown text is
 * reported, never stored as free text.
 */
final class SupplierImportValues
{
    /** @var array<string, array<string, string>> */
    private array $maps = [];

    /**
     * The code the text names, or null when it names none of them.
     *
     * @param  list<string>  $codes
     * @param  string  $labelKey  translation key of an array code => label
     */
    public function choice(string $text, array $codes, string $labelKey): ?string
    {
        return $this->map($codes, $labelKey)[SupplierImportColumns::normalize($text)] ?? null;
    }

    /** Ja/Nei, Yes/No, true/false, 1/0 — or null when the text is none of these. */
    public function yesNo(string $text): ?bool
    {
        return match (SupplierImportColumns::normalize($text)) {
            'ja', 'j', 'yes', 'y', 'true', 'sann', '1', 'x' => true,
            'nei', 'n', 'no', 'false', 'usann', '0' => false,
            default => null,
        };
    }

    /**
     * The labels a person may write for the codes, in the given language — for the template and
     * for saying what is allowed.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function labels(array $codes, string $labelKey, ?string $locale = null): array
    {
        $labels = (array) __($labelKey, [], $locale);

        return array_values(array_map(fn (string $code): string => (string) ($labels[$code] ?? $code), $codes));
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function map(array $codes, string $labelKey): array
    {
        $cacheKey = $labelKey.'|'.implode(',', $codes);

        if (isset($this->maps[$cacheKey])) {
            return $this->maps[$cacheKey];
        }

        $map = [];

        foreach ($codes as $code) {
            $map[SupplierImportColumns::normalize($code)] = $code;
        }

        foreach (['no', 'en'] as $locale) {
            $labels = (array) __($labelKey, [], $locale);

            foreach ($codes as $code) {
                if (isset($labels[$code]) && is_string($labels[$code])) {
                    $map[SupplierImportColumns::normalize($labels[$code])] ??= $code;
                }
            }
        }

        return $this->maps[$cacheKey] = $map;
    }
}
