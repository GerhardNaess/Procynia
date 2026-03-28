<?php

namespace App\Support;

use InvalidArgumentException;
use ResourceBundle;
use RuntimeException;

class LocaleReferenceCatalog
{
    public static function nationalities(): array
    {
        $countriesEn = self::countriesBundle('en');
        $countriesNo = self::countriesBundle('no');
        $now = now();
        $rows = [];

        foreach ($countriesEn as $code => $nameEn) {
            $code = (string) $code;

            if (! preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }

            $descriptionEn = self::normalizeName($nameEn);
            $descriptionNo = self::normalizeName($countriesNo[$code] ?? null);

            if ($descriptionEn === null || $descriptionNo === null) {
                throw new RuntimeException("Missing nationality translation for {$code}.");
            }

            $rows[] = [
                'code' => $code,
                'name_en' => $descriptionEn,
                'name_no' => $descriptionNo,
                'flag_emoji' => self::flagEmoji($code),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));

        return $rows;
    }

    public static function languages(): array
    {
        $codes = [];

        foreach (ResourceBundle::getLocales('') as $locale) {
            $code = \Locale::getPrimaryLanguage((string) $locale);

            if (preg_match('/^[a-z]{2}$/', $code)) {
                $codes[$code] = true;
            }
        }

        ksort($codes);

        $now = now();
        $rows = [];

        foreach (array_keys($codes) as $code) {
            $nameEn = self::normalizeName(\Locale::getDisplayLanguage($code, 'en'));
            $nameNo = self::normalizeName(\Locale::getDisplayLanguage($code, 'no'), capitalizeFirst: true);

            if ($nameEn === null || $nameNo === null) {
                throw new RuntimeException("Missing language translation for {$code}.");
            }

            $rows[] = [
                'code' => $code,
                'name_en' => $nameEn,
                'name_no' => $nameNo,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private static function countriesBundle(string $locale): ResourceBundle
    {
        $bundle = ResourceBundle::create($locale, 'ICUDATA-region');

        if (! $bundle instanceof ResourceBundle) {
            throw new RuntimeException("Unable to load ICU region bundle for locale {$locale}.");
        }

        $countries = $bundle['Countries'] ?? null;

        if (! $countries instanceof ResourceBundle) {
            throw new RuntimeException("Unable to load ICU country list for locale {$locale}.");
        }

        return $countries;
    }

    private static function flagEmoji(string $countryCode): string
    {
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new InvalidArgumentException("Invalid ISO country code [{$countryCode}] for flag generation.");
        }

        $flag = '';

        foreach (str_split($countryCode) as $letter) {
            $flag .= mb_chr(0x1F1E6 + ord($letter) - ord('A'), 'UTF-8');
        }

        return $flag;
    }

    private static function normalizeName(mixed $value, bool $capitalizeFirst = false): ?string
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        if (! $capitalizeFirst) {
            return $name;
        }

        $first = mb_substr($name, 0, 1, 'UTF-8');
        $rest = mb_substr($name, 1, null, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8').$rest;
    }
}
