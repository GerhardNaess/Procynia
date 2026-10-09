<?php

namespace App\Services\Ai\Commercial;

/**
 * The AI capacity tiers a customer can hold (config/ai_customer_capacity.php `tiers`).
 *
 * A tier is a multiplier on the customer's base capacity (AiBaseCapacityCalculator), never a fixed
 * amount of units: the same tier gives a larger customer more capacity. The customer chooses it;
 * nothing in the system ever changes it on the customer's behalf. None of the tiers carries a price.
 */
class AiCapacityTierCatalog
{
    /**
     * Every tier, ordered by sort_order.
     *
     * @return array<string, array{key: string, name: string, multiplier: float, active: bool, sort_order: int}>
     */
    public function all(): array
    {
        $tiers = [];

        foreach ((array) config('ai_customer_capacity.tiers', []) as $key => $tier) {
            $tiers[(string) $key] = [
                'key' => (string) $key,
                'name' => (string) ($tier['name'] ?? $key),
                'multiplier' => max(0.0, (float) ($tier['multiplier'] ?? 0)),
                'active' => (bool) ($tier['active'] ?? true),
                'sort_order' => (int) ($tier['sort_order'] ?? 0),
            ];
        }

        uasort($tiers, fn (array $a, array $b): int => [$a['sort_order'], $a['key']] <=> [$b['sort_order'], $b['key']]);

        return $tiers;
    }

    /** @return array{key: string, name: string, multiplier: float, active: bool, sort_order: int}|null */
    public function find(?string $key): ?array
    {
        return $key === null || $key === '' ? null : ($this->all()[$key] ?? null);
    }

    /** The tier a customer has until it chooses one, or null when none is configured. */
    public function default(): ?array
    {
        return $this->find((string) config('ai_customer_capacity.default_tier', ''));
    }

    /**
     * The tier that applies to a customer holding $key: that tier when it is listed (active or
     * not), otherwise the default.
     */
    public function effective(?string $key): ?array
    {
        return $this->find($key) ?? $this->default();
    }

    /**
     * The tiers a customer may choose: the active ones, plus $current when it holds an inactive one
     * (so it is never shown as something it does not have).
     *
     * @return array<string, array{key: string, name: string, multiplier: float, active: bool, sort_order: int}>
     */
    public function choosable(?string $current = null): array
    {
        return array_filter($this->all(), fn (array $tier): bool => $tier['active'] || $tier['key'] === $current);
    }

    /**
     * The admin select: key => label with the multiplier (internal staff may see it; customers never do).
     *
     * @return array<string, string>
     */
    public function selectable(?string $current = null): array
    {
        return array_map(
            fn (array $tier): string => __('procynia.ai_admin.capacity.tier_option', [
                'name' => $tier['name'],
                'multiplier' => number_format($tier['multiplier'], 2, ',', ' '),
            ]),
            $this->choosable($current),
        );
    }
}
