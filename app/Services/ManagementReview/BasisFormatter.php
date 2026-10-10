<?php

namespace App\Services\ManagementReview;

use Carbon\CarbonImmutable;

/**
 * A presented basis (SectionPresenter) in words, in the reader's language — for the page, the print
 * view and the PDF alike, so all three say the same. Snapshots keep keys and raw values; the words
 * are chosen here, at read time, so a frozen review reads in whatever language its reader uses.
 */
final class BasisFormatter
{
    private const T = 'procynia.management_review.';

    /**
     * @param  array<string, mixed>|null  $basis
     * @return array<string, mixed>|null
     */
    public function format(string $section, ?array $basis, bool $finalized = false): ?array
    {
        if ($basis === null) {
            return null;
        }

        return [
            'empty' => (bool) $basis['empty'],
            'area_scoped' => (bool) $basis['area_scoped'],
            'areas' => $basis['areas'],
            'groups' => array_map(fn (array $group): array => [
                'key' => $group['key'],
                'label' => $this->t('groups.'.($finalized && $group['key'] === 'status' ? 'status_finalized' : $group['key'])),
                'metrics' => array_map(fn (array $metric): array => [
                    'key' => $metric['key'],
                    'label' => $this->t("metrics.{$section}.{$metric['key']}"),
                    'value' => (int) $metric['value'],
                ], $group['metrics']),
            ], $basis['groups']),
            'lists' => array_map(fn (array $list): array => $this->list($section, $list), $basis['lists']),
            'notes' => array_map(fn (string $note): string => $this->t('limitations.'.$note), $basis['notes']),
        ];
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function list(string $section, array $list): array
    {
        $columns = [];

        foreach ($list['items'] as $item) {
            foreach ($item['fields'] ?? [] as $field) {
                $columns[$field['key']] = true;
            }
        }

        return [
            'key' => $list['key'],
            'label' => $this->t("lists.{$section}.{$list['key']}"),
            'total' => (int) $list['total'],
            'shown' => count($list['items']),
            'columns' => array_map(fn (string $key): array => ['key' => $key, 'label' => $this->t('columns.'.$key)], array_keys($columns)),
            'rows' => array_map(fn (array $item): array => $this->row($item), $list['items']),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function row(array $item): array
    {
        $row = [
            'id' => (int) ($item['id'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'url' => $item['url'] ?? null,
            'cells' => $this->cells($item['fields'] ?? []),
        ];

        if (isset($item['case'])) {
            $row['case'] = [
                'id' => (int) $item['case']['id'],
                'title' => (string) $item['case']['title'],
                'url' => $item['case']['url'] ?? null,
                'cells' => $this->cells($item['case']['fields'] ?? []),
            ];
        }

        if (! empty($item['case_hidden'])) {
            $row['case_hidden'] = true;
        }

        return $row;
    }

    /**
     * @param  list<array{key: string, kind: string, value: mixed}>  $fields
     * @return array<string, ?string>
     */
    public function cells(array $fields): array
    {
        $cells = [];

        foreach ($fields as $field) {
            $cells[$field['key']] = $this->value($field['kind'], $field['value']);
        }

        return $cells;
    }

    public function value(string $kind, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($kind) {
            'date' => $this->date((string) $value),
            'enum' => $this->t('values.'.$value),
            'enum_list' => implode(', ', array_map(fn (string $item): string => $this->t('values.'.$item), (array) $value)),
            default => (string) $value,
        };
    }

    public function date(?string $value): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value)->format('d.m.Y');
    }

    private function t(string $key): string
    {
        $text = __(self::T.$key);

        // An unknown key reads as itself, never as a lang path.
        return is_string($text) && ! str_starts_with($text, self::T) ? $text : str_replace('_', ' ', (string) last(explode('.', $key)));
    }
}
