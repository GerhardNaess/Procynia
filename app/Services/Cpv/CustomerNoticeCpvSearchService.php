<?php

namespace App\Services\Cpv;

use Illuminate\Support\Str;

class CustomerNoticeCpvSearchService
{
    private const POPULAR_CODES = [
        '72222300',
        '48000000',
        '45000000',
        '90910000',
        '79100000',
    ];

    private const SYNONYMS = [
        'it' => ['72222300', '72000000', '72200000', '72500000', '72600000'],
        'it drift' => ['72500000', '72600000', '72510000', '72222300'],
        'programvare' => ['48000000', '48100000', '72260000', '72262000', '72268000'],
        'bygg' => ['45000000', '45200000', '45400000', '71530000', '71240000'],
        'bygg og anlegg' => ['45000000', '45200000', '45400000'],
        'renhold' => ['90910000', '90911000', '90919200', '90900000'],
        'juridisk' => ['79100000', '79110000', '79111000'],
        'juridisk bistand' => ['79110000', '79111000', '79112000'],
        'konsulent' => ['79410000', '79411000', '72220000', '72224000', '71315200'],
        'konsulenttjenester' => ['79410000', '79411000', '72220000', '72224000', '71315200'],
    ];

    /**
     * @var array<int, array{code:string,label:string,label_en:?string,label_normalized:string,label_en_normalized:?string}>
     */
    private ?array $entries = null;

    /**
     * @var array<string, array{code:string,label:string,label_en:?string,label_normalized:string,label_en_normalized:?string}>
     */
    private ?array $entriesByCode = null;

    /**
     * @return array<int, array{code:string,label:string}>
     */
    public function search(string $query, array $selectedCodes = [], int $limit = 8): array
    {
        $normalizedQuery = $this->normalize($query);
        $selected = array_flip($this->normalizeCodes($selectedCodes));

        if ($normalizedQuery === '') {
            return $this->popular(array_keys($selected), $limit);
        }

        $digitsQuery = preg_replace('/\D+/', '', $query) ?? '';
        $synonymPositions = array_flip($this->matchedSynonymCodes($normalizedQuery));
        $matches = [];

        foreach ($this->entries() as $entry) {
            if (isset($selected[$entry['code']])) {
                continue;
            }

            $rank = null;
            $position = null;

            if (array_key_exists($entry['code'], $synonymPositions)) {
                $rank = 4;
                $position = $synonymPositions[$entry['code']];
            } elseif (Str::startsWith($entry['label_normalized'], $normalizedQuery)
                || ($entry['label_en_normalized'] !== null && Str::startsWith($entry['label_en_normalized'], $normalizedQuery))) {
                $rank = 3;
                $position = 0;
            } elseif (str_contains($entry['label_normalized'], $normalizedQuery)
                || ($entry['label_en_normalized'] !== null && str_contains($entry['label_en_normalized'], $normalizedQuery))) {
                $rank = 2;
                $position = 0;
            } elseif ($digitsQuery !== '' && str_contains($entry['code'], $digitsQuery)) {
                $rank = 1;
                $position = 0;
            }

            if ($rank === null) {
                continue;
            }

            $matches[] = [
                'rank' => $rank,
                'position' => $position ?? PHP_INT_MAX,
                'label_length' => mb_strlen($entry['label']),
                'code' => $entry['code'],
                'label' => $entry['label'],
            ];
        }

        usort($matches, function (array $left, array $right): int {
            $rankComparison = $right['rank'] <=> $left['rank'];

            if ($rankComparison !== 0) {
                return $rankComparison;
            }

            $positionComparison = $left['position'] <=> $right['position'];

            if ($positionComparison !== 0) {
                return $positionComparison;
            }

            $lengthComparison = $left['label_length'] <=> $right['label_length'];

            if ($lengthComparison !== 0) {
                return $lengthComparison;
            }

            return $left['code'] <=> $right['code'];
        });

        return collect($matches)
            ->take($limit)
            ->map(fn (array $entry): array => [
                'code' => $entry['code'],
                'label' => $entry['label'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{code:string,label:string}>
     */
    public function popular(array $selectedCodes = [], int $limit = 5): array
    {
        $selected = array_flip($this->normalizeCodes($selectedCodes));

        return collect(self::POPULAR_CODES)
            ->reject(fn (string $code): bool => isset($selected[$code]))
            ->map(fn (string $code): ?array => $this->resolve($code))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{code:string,label:string}>
     */
    public function selectedFromFilter(?string $value): array
    {
        return collect($this->parseCodes($value))
            ->map(fn (string $code): ?array => $this->resolve($code))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function parseCodes(?string $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return $this->normalizeCodes(preg_split('/[\s,;]+/', $value) ?: []);
    }

    /**
     * @return array{code:string,label:string}|null
     */
    public function resolve(string $code): ?array
    {
        $entry = $this->entriesByCode()[$code] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        return [
            'code' => $entry['code'],
            'label' => $entry['label'],
        ];
    }

    /**
     * @return array<int, array{code:string,label:string,label_en:?string,label_normalized:string,label_en_normalized:?string}>
     */
    private function entries(): array
    {
        if (is_array($this->entries)) {
            return $this->entries;
        }

        $norwegian = $this->loadCatalog('cpv_codes_no.php');
        $english = $this->loadCatalog('cpv_codes.php');
        $entries = [];

        foreach ($norwegian as $code => $label) {
            if ((! is_string($code) && ! is_int($code)) || ! is_string($label)) {
                continue;
            }

            $normalizedCode = str_pad((string) $code, 8, '0', STR_PAD_LEFT);
            $trimmedLabel = trim($label);

            if ($trimmedLabel === '') {
                continue;
            }

            $englishLabel = $english[$code] ?? $english[$normalizedCode] ?? null;
            $entries[] = [
                'code' => $normalizedCode,
                'label' => $trimmedLabel,
                'label_en' => is_string($englishLabel) && trim($englishLabel) !== '' ? trim($englishLabel) : null,
                'label_normalized' => $this->normalize($trimmedLabel),
                'label_en_normalized' => is_string($englishLabel) && trim($englishLabel) !== ''
                    ? $this->normalize($englishLabel)
                    : null,
            ];
        }

        return $this->entries = $entries;
    }

    /**
     * @return array<string, array{code:string,label:string,label_en:?string,label_normalized:string,label_en_normalized:?string}>
     */
    private function entriesByCode(): array
    {
        if (is_array($this->entriesByCode)) {
            return $this->entriesByCode;
        }

        $byCode = [];

        foreach ($this->entries() as $entry) {
            $byCode[$entry['code']] = $entry;
        }

        return $this->entriesByCode = $byCode;
    }

    /**
     * @return array<string, string>
     */
    private function loadCatalog(string $filename): array
    {
        $path = resource_path("data/{$filename}");

        if (! is_file($path)) {
            return [];
        }

        $catalog = require $path;

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @return array<int, string>
     */
    private function matchedSynonymCodes(string $query): array
    {
        $matchingPhrases = collect(self::SYNONYMS)
            ->map(fn (array $codes, string $phrase): array => [
                'phrase' => $this->normalize($phrase),
                'codes' => $codes,
            ])
            ->filter(fn (array $entry): bool => $this->synonymMatches($query, $entry['phrase']))
            ->sortByDesc(fn (array $entry): int => mb_strlen($entry['phrase']))
            ->values();
        $matched = [];

        foreach ($matchingPhrases as $entry) {
            foreach ($entry['codes'] as $code) {
                if (! isset($this->entriesByCode()[$code]) || in_array($code, $matched, true)) {
                    continue;
                }

                $matched[] = $code;
            }
        }

        return $matched;
    }

    private function synonymMatches(string $query, string $phrase): bool
    {
        if ($query === $phrase) {
            return true;
        }

        if (mb_strlen($query) >= 2 && Str::startsWith($phrase, $query)) {
            return true;
        }

        return mb_strlen($phrase) >= 2 && Str::startsWith($query, $phrase);
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private function normalizeCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn (string $code): string => preg_replace('/\D+/', '', $code) ?? '')
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }
}
