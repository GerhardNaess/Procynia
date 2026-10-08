<?php

namespace App\Services\Ai\Commercial;

/**
 * The AI capacity tiers a customer can be given (config/ai_customer_capacity.php `tiers`).
 *
 * AI capacity is a commercial dimension of its own, next to Basis and the options; a tier is the
 * only non-override source of included AI units. The tiers are technical placeholders until
 * production usage decides real levels, and none of them carries a price.
 */
class AiCapacityTierCatalog
{
    /**
     * Every tier, ordered by sort_order.
     *
     * @return array<string, array{key: string, name: string, included_units_per_month: int, active: bool, sort_order: int}>
     */
    public function all(): array
    {
        $tiers = [];

        foreach ((array) config('ai_customer_capacity.tiers', []) as $key => $tier) {
            $tiers[(string) $key] = [
                'key' => (string) $key,
                'name' => (string) ($tier['name'] ?? $key),
                'included_units_per_month' => max(0, (int) ($tier['included_units_per_month'] ?? 0)),
                'active' => (bool) ($tier['active'] ?? true),
                'sort_order' => (int) ($tier['sort_order'] ?? 0),
            ];
        }

        uasort($tiers, fn (array $a, array $b): int => [$a['sort_order'], $a['key']] <=> [$b['sort_order'], $b['key']]);

        return $tiers;
    }

    /** @return array{key: string, name: string, included_units_per_month: int, active: bool, sort_order: int}|null */
    public function find(?string $key): ?array
    {
        return $key === null || $key === '' ? null : ($this->all()[$key] ?? null);
    }

    /**
     * Tiers an admin may select: the active ones, plus $current when the customer already holds an
     * inactive one (so opening the form never drops it).
     *
     * @return array<string, string> key => name
     */
    public function selectable(?string $current = null): array
    {
        $options = [];

        foreach ($this->all() as $key => $tier) {
            if ($tier['active'] || $key === $current) {
                $options[$key] = __('procynia.ai_admin.capacity.tier_option', [
                    'name' => $tier['name'],
                    'units' => number_format($tier['included_units_per_month'], 0, ',', ' '),
                ]);
            }
        }

        return $options;
    }
}
