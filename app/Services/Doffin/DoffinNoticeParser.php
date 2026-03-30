<?php

namespace App\Services\Doffin;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DoffinNoticeParser
{
    public function parse(array $detail): array
    {
        $awardedNames = $this->stringValues($detail['awardedNames'] ?? []);
        $buyers = collect($detail['buyer'] ?? [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer))
            ->values();

        return [
            'notice_id' => $this->stringOrNull($detail['id'] ?? null),
            'notice_type' => $this->stringOrNull($detail['noticeType'] ?? $detail['type'] ?? null),
            'heading' => $this->stringOrNull($detail['heading'] ?? null),
            'publication_date' => $this->stringOrNull($detail['publicationDate'] ?? null),
            'issue_date' => $this->stringOrNull($detail['issueDate'] ?? null),
            'buyer_names' => $buyers
                ->map(fn (array $buyer): ?string => $this->stringOrNull($buyer['name'] ?? null))
                ->filter()
                ->values()
                ->all(),
            'buyer_ids' => $buyers
                ->map(fn (array $buyer): ?string => $this->stringOrNull($buyer['id'] ?? $buyer['organizationId'] ?? null))
                ->filter()
                ->values()
                ->all(),
            'cpv_codes' => $this->stringValues($detail['allCpvCodes'] ?? []),
            'place_of_performance' => $this->stringValues($detail['placeOfPerformance'] ?? []),
            'estimated_value' => $this->normalizeEstimatedValue($detail),
            'awarded_names' => $awardedNames,
            'suppliers' => $this->suppliers($detail, $awardedNames),
        ];
    }

    public function supplierRecords(array $parsedNotice): array
    {
        return collect($parsedNotice['suppliers'] ?? [])
            ->filter(fn (mixed $supplier): bool => is_array($supplier))
            ->map(function (array $supplier) use ($parsedNotice): array {
                return [
                    'supplier_name' => $supplier['supplier_name'] ?? null,
                    'organization_number' => $supplier['organization_number'] ?? null,
                    'winner_lots' => $supplier['winner_lots'] ?? [],
                    'source' => $supplier['source'] ?? null,
                    'notice_id' => $parsedNotice['notice_id'] ?? null,
                    'notice_type' => $parsedNotice['notice_type'] ?? null,
                    'heading' => $parsedNotice['heading'] ?? null,
                    'publication_date' => $parsedNotice['publication_date'] ?? null,
                    'issue_date' => $parsedNotice['issue_date'] ?? null,
                    'buyer_name' => $parsedNotice['buyer_names'][0] ?? null,
                    'buyer_org_id' => $parsedNotice['buyer_ids'][0] ?? null,
                    'cpv_codes' => $parsedNotice['cpv_codes'] ?? [],
                    'place_of_performance' => $parsedNotice['place_of_performance'] ?? [],
                    'estimated_value' => $parsedNotice['estimated_value'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function suppliers(array $detail, array $awardedNames): array
    {
        $eformSuppliers = $this->eformSuppliers($detail['eform'] ?? [], $awardedNames);

        if ($awardedNames === []) {
            return array_values($eformSuppliers);
        }

        $suppliers = $eformSuppliers;
        $matchedNames = collect($suppliers)
            ->map(fn (array $supplier): string => $this->normalizeName($supplier['supplier_name'] ?? null))
            ->filter()
            ->all();

        foreach ($awardedNames as $awardedName) {
            $normalizedAwardedName = $this->normalizeName($awardedName);

            if ($normalizedAwardedName === '' || in_array($normalizedAwardedName, $matchedNames, true)) {
                continue;
            }

            $suppliers[$normalizedAwardedName] = [
                'supplier_name' => $awardedName,
                'organization_number' => null,
                'winner_lots' => [],
                'source' => 'awardedNames fallback',
            ];
        }

        return array_values($suppliers);
    }

    private function eformSuppliers(mixed $nodes, array $awardedNames): array
    {
        if (! is_array($nodes)) {
            return [];
        }

        $awardedLookup = collect($awardedNames)
            ->mapWithKeys(fn (string $name): array => [$this->normalizeName($name) => true])
            ->all();
        $suppliers = [];

        foreach ($this->walkSections($nodes) as $node) {
            if (! $this->isOrganizationNode($node)) {
                continue;
            }

            $candidate = $this->organizationSupplier($node, $awardedLookup);

            if ($candidate === null) {
                continue;
            }

            $supplierKey = $candidate['organization_number'] ?: $this->normalizeName($candidate['supplier_name']);

            if ($supplierKey === '') {
                continue;
            }

            if (! array_key_exists($supplierKey, $suppliers)) {
                $suppliers[$supplierKey] = $candidate;

                continue;
            }

            $suppliers[$supplierKey]['winner_lots'] = collect([
                ...($suppliers[$supplierKey]['winner_lots'] ?? []),
                ...($candidate['winner_lots'] ?? []),
            ])->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $suppliers;
    }

    private function organizationSupplier(array $node, array $awardedLookup): ?array
    {
        $fields = collect($this->walkSections($node['sections'] ?? []));
        $supplierName = $fields
            ->first(fn (array $field): bool => $this->sameLabel($field['label'] ?? null, 'Offisielt navn'));
        $organizationNumber = $fields
            ->first(fn (array $field): bool => $this->sameLabel($field['label'] ?? null, 'Organisasjonsnummer'));
        $winnerLots = $fields
            ->filter(fn (array $field): bool => $this->sameLabel($field['label'] ?? null, 'Vinner av disse delkontraktene'))
            ->map(fn (array $field): ?string => $this->stringOrNull($field['value'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $supplierNameValue = $this->stringOrNull($supplierName['value'] ?? null);
        $normalizedName = $this->normalizeName($supplierNameValue);
        $isAwardedName = $normalizedName !== '' && array_key_exists($normalizedName, $awardedLookup);

        if ($supplierNameValue === null) {
            return null;
        }

        if ($winnerLots === [] && ! $isAwardedName) {
            return null;
        }

        return [
            'supplier_name' => $supplierNameValue,
            'organization_number' => $this->normalizeOrganizationNumber($organizationNumber['value'] ?? null),
            'winner_lots' => $winnerLots,
            'source' => 'eform',
        ];
    }

    private function isOrganizationNode(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        $value = $this->stringOrNull($node['value'] ?? null);

        return $value !== null && Str::startsWith($value, 'ORG-');
    }

    private function walkSections(array $nodes): Collection
    {
        $walked = collect();

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $walked->push($node);

            if (is_array($node['sections'] ?? null)) {
                $walked = $walked->merge($this->walkSections($node['sections']));
            }
        }

        return $walked;
    }

    private function normalizeEstimatedValue(array $detail): ?array
    {
        $estimatedValue = $detail['estimatedValue'] ?? data_get($detail, 'core.estimatedValue');

        if (! is_array($estimatedValue)) {
            return null;
        }

        $amount = $estimatedValue['amount'] ?? null;

        return [
            'amount' => is_numeric($amount) ? (float) $amount : null,
            'currency_code' => $this->stringOrNull($estimatedValue['currencyCode'] ?? $estimatedValue['code'] ?? null),
            'display' => $this->stringOrNull($estimatedValue['fullLocalizedText'] ?? null),
        ];
    }

    private function stringValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn (mixed $value): ?string => $this->stringOrNull($value))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeOrganizationNumber(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function normalizeName(mixed $value): string
    {
        return Str::lower(Str::squish((string) $value));
    }

    private function sameLabel(mixed $value, string $expected): bool
    {
        return $this->normalizeName($value) === $this->normalizeName($expected);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
