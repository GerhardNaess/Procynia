<?php

namespace App\Services\ManagementReview;

/**
 * The basis of one section as data — never as translated text, so a snapshot reads in the reader's
 * language years later (docs/management-review-v1-plan.md §5.1).
 *
 * Every number is a count, kept per fagområde for an area-scoped section (key = area id) and under
 * '_' otherwise. A reader's total is then the sum over the fagområder they may see today
 * (SectionPresenter) — the same rule for a live draft and a frozen snapshot. Lists keep their items
 * with the fagområde they belong to, at most `limit` per list and fagområde, with the true total.
 *
 * Metric groups:
 *  - period: what happened within the review's period, read from dated history.
 *  - previous: the same for the period of equal length before it, for trends.
 *  - status: the state when the basis was read («Status nå», or at finalization).
 */
final class SectionPayload
{
    public const SCHEMA_VERSION = 1;

    public const GLOBAL = '_';

    /** @var array<string, array<string, array<string, int>>> */
    private array $metrics = [];

    /** @var array<string, array{totals: array<string, int>, items: list<array<string, mixed>>}> */
    private array $lists = [];

    /** @var array<string, string> */
    private array $areas = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, int> */
    private array $perBucket = [];

    public function __construct(
        private readonly bool $areaScoped,
        private readonly int $limit,
    ) {}

    public function area(int $id, string $name): self
    {
        $this->areas[(string) $id] = $name;

        return $this;
    }

    /** Declare metrics so a zero reads as zero, never as missing. */
    public function metrics(string $group, array $keys): self
    {
        foreach ($keys as $key) {
            $this->metrics[$group][$key][self::GLOBAL] ??= 0;
        }

        return $this;
    }

    public function count(string $group, string $metric, int $amount = 1, ?int $areaId = null): self
    {
        $bucket = $this->bucket($areaId);
        $this->metrics[$group][$metric][self::GLOBAL] ??= 0;
        $this->metrics[$group][$metric][$bucket] = ($this->metrics[$group][$metric][$bucket] ?? 0) + $amount;

        return $this;
    }

    /** Declare a list so an empty one reads as empty, never as missing. */
    public function list(string $list): self
    {
        $this->lists[$list] ??= ['totals' => [self::GLOBAL => 0], 'items' => []];

        return $this;
    }

    /**
     * One row of a list. Fields are {key, kind, value}: kind is date, text, number or enum (a value
     * the page translates). Rows must be added in display order.
     *
     * @param  array<string, mixed>  $item
     */
    public function item(string $list, array $item, ?int $areaId = null): self
    {
        $this->list($list);
        $bucket = $this->bucket($areaId);
        $this->lists[$list]['totals'][$bucket] = ($this->lists[$list]['totals'][$bucket] ?? 0) + 1;
        $slot = $list.'|'.$bucket;
        $this->perBucket[$slot] = ($this->perBucket[$slot] ?? 0) + 1;

        if ($this->perBucket[$slot] <= $this->limit) {
            $this->lists[$list]['items'][] = $item + ['area_id' => $this->areaScoped ? $areaId : null];
        }

        return $this;
    }

    /** A known limitation of the source data, shown with the section (lang: limitations.*). */
    public function note(string $key): self
    {
        if (! in_array($key, $this->notes, true)) {
            $this->notes[] = $key;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'area_scoped' => $this->areaScoped,
            'areas' => (object) $this->areas,
            // Lists, never objects: jsonb does not keep the order of an object's keys, and the order
            // of groups, metrics and lists is the order the page shows them in.
            'metrics' => array_map(
                fn (string $group, array $metrics): array => [
                    'group' => $group,
                    'metrics' => array_map(fn (string $key, array $values): array => ['key' => $key, 'values' => (object) $values], array_keys($metrics), $metrics),
                ],
                array_keys($this->metrics),
                $this->metrics,
            ),
            'lists' => array_map(
                fn (string $key, array $list): array => ['key' => $key, 'totals' => (object) $list['totals'], 'items' => $list['items']],
                array_keys($this->lists),
                $this->lists,
            ),
            'notes' => $this->notes,
        ];
    }

    private function bucket(?int $areaId): string
    {
        return $this->areaScoped && $areaId !== null ? (string) $areaId : self::GLOBAL;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array{key: string, kind: string, value: mixed}>
     */
    public static function fields(array $fields): array
    {
        $out = [];

        foreach ($fields as $key => [$kind, $value]) {
            $out[] = ['key' => $key, 'kind' => $kind, 'value' => $value];
        }

        return $out;
    }
}
