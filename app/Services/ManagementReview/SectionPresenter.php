<?php

namespace App\Services\ManagementReview;

/**
 * A section payload (SectionPayload), narrowed to what one reader may see and shaped for the page.
 *
 * $areaIds is the set of fagområder whose numbers and rows the reader may see in an area-scoped
 * section — null when the section is not area-scoped. Totals are summed over those fagområder only,
 * so a number never includes a fagområde the reader cannot reach. A row of a fagområde outside the
 * set is dropped.
 *
 * $caseAreaIds narrows the Avvik og forbedringer case shown inside a decision row: null means the
 * reader may see every such case, a list the fagområder they may; a case outside it is replaced by
 * `case_hidden` — the decision stays, its follow-up details do not.
 *
 * The same code for a live draft and a frozen snapshot, so both read alike.
 */
final class SectionPresenter
{
    public function __construct(private readonly int $limit) {}

    /**
     * @param  array<string, mixed>|object  $payload
     * @param  list<int>|null  $areaIds
     * @param  list<int>|null  $caseAreaIds
     * @return array<string, mixed>
     */
    public function present(array|object $payload, ?array $areaIds, ?array $caseAreaIds = null): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) json_encode($payload), true) ?: [];
        $scoped = (bool) ($payload['area_scoped'] ?? false);
        $allowed = $scoped ? array_map('strval', $areaIds ?? []) : null;
        $groups = [];
        $any = false;

        foreach (($payload['metrics'] ?? []) as $group) {
            $rows = [];

            foreach ($group['metrics'] as $metric) {
                $value = $this->sum($metric['values'] ?? [], $allowed);
                $any = $any || $value > 0;
                $rows[] = ['key' => (string) $metric['key'], 'value' => $value];
            }

            $groups[] = ['key' => (string) $group['group'], 'metrics' => $rows];
        }

        $lists = [];

        foreach (($payload['lists'] ?? []) as $list) {
            $key = $list['key'];
            $items = array_values(array_filter(
                $list['items'] ?? [],
                fn (array $item): bool => ! $scoped || ($item['area_id'] ?? null) === null || in_array((string) $item['area_id'], $allowed, true),
            ));
            $items = array_map(fn (array $item): array => $this->narrowCase($item, $caseAreaIds), $items);
            $total = $this->sum($list['totals'] ?? [], $allowed);
            $any = $any || $total > 0;

            $lists[] = [
                'key' => (string) $key,
                'total' => $total,
                'items' => array_slice($items, 0, $this->limit),
            ];
        }

        $areas = [];

        foreach (($payload['areas'] ?? []) as $id => $name) {
            if (! $scoped || in_array((string) $id, $allowed, true)) {
                $areas[] = (string) $name;
            }
        }

        sort($areas);

        return [
            'groups' => $groups,
            'lists' => $lists,
            'areas' => $areas,
            'area_scoped' => $scoped,
            'notes' => array_values($payload['notes'] ?? []),
            // «Ingen registrerte forhold»: the section could be read and nothing was there.
            'empty' => ! $any,
        ];
    }

    /**
     * @param  array<string, int>  $values
     * @param  list<string>|null  $allowed
     */
    private function sum(array $values, ?array $allowed): int
    {
        $total = 0;

        foreach ($values as $bucket => $value) {
            if ((string) $bucket === SectionPayload::GLOBAL || $allowed === null || in_array((string) $bucket, $allowed, true)) {
                $total += (int) $value;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<int>|null  $caseAreaIds
     * @return array<string, mixed>
     */
    private function narrowCase(array $item, ?array $caseAreaIds): array
    {
        if (! isset($item['case']) || ! is_array($item['case'])) {
            return $item;
        }

        $areaId = $item['case']['area_id'] ?? null;

        if ($caseAreaIds !== null && ! in_array((int) $areaId, $caseAreaIds, true)) {
            unset($item['case']);
            $item['case_hidden'] = true;
        }

        return $item;
    }
}
