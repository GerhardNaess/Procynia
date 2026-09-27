<?php

namespace App\Services\OpportunitySources;

use Illuminate\Support\Str;

/**
 * What Procynia is looking for, said without naming a register.
 *
 * OpportunitySourceAdapter::search() used to take an untyped array, and that array was Doffin's:
 * `publication_period` counted days the way Doffin's API counts them, `keywords_mode` was its
 * spelling of "all or any", `status` carried ACTIVE. So the interface was source-neutral in its
 * type hints and Doffin-shaped in practice — a second adapter would have had to implement Doffin's
 * parameter vocabulary in order to be callable at all.
 *
 * Every field here is a question any procurement register can be asked. Translating them into one
 * register's parameters is the adapter's work, and the only place that knows those parameters.
 *
 * WHAT DELIBERATELY IS NOT HERE.
 *
 * The controller's filter array also carries `watch_list_id`, `relevance`, `bid_status`,
 * `history_type` and `cockpit_scope`. None of them ever reached the register — they filter saved
 * cases inside Procynia — but all five were passed to search() and silently ignored. Leaving them
 * out is not a removal; it is the first time the boundary states what it actually uses.
 */
class OpportunitySearchCriteria
{
    /**
     * @param  list<string>  $keywords  each already trimmed and non-empty
     * @param  list<string>  $cpvCodes  digits only, deduplicated — structured rather than one
     *                                  comma-separated string, because joining them is a wire
     *                                  format and belongs to whoever owns the wire
     * @param  bool  $matchAllKeywords  true when every keyword must appear, false when any will do
     * @param  string|null  $publishedFrom  ISO date, inclusive
     * @param  string|null  $publishedTo  ISO date, inclusive
     * @param  int|null  $publishedWithinDays  a rolling window, when no explicit range is given.
     *                                         The register decides how to express "the last N days"
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $keywords = [],
        public readonly bool $matchAllKeywords = true,
        public readonly ?string $buyerName = null,
        public readonly array $cpvCodes = [],
        public readonly ?OpportunityStatus $status = null,
        public readonly ?string $publishedFrom = null,
        public readonly ?string $publishedTo = null,
        public readonly ?int $publishedWithinDays = null,
    ) {}

    /**
     * Build from loose input, doing the normalising once so no adapter has to repeat it.
     *
     * Empty strings become null throughout: "the user typed nothing" and "the user typed a space"
     * are the same question, and an adapter should never have to decide that for itself.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            query: self::text($values['query'] ?? null),
            keywords: self::stringList($values['keywords'] ?? []),
            matchAllKeywords: (bool) ($values['match_all_keywords'] ?? true),
            buyerName: self::text($values['buyer_name'] ?? null),
            cpvCodes: self::cpvCodes($values['cpv_codes'] ?? []),
            status: $values['status'] ?? null,
            publishedFrom: self::text($values['published_from'] ?? null),
            publishedTo: self::text($values['published_to'] ?? null),
            publishedWithinDays: self::positiveInt($values['published_within_days'] ?? null),
        );
    }

    public function hasKeywords(): bool
    {
        return $this->keywords !== [];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $squished = Str::squish($value);

        return $squished === '' ? null : $squished;
    }

    /**
     * @param  mixed  $value  a list, or a string separated by newlines, commas or semicolons
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : (preg_split('/[\r\n,;]+/', (string) $value) ?: []);

        return collect($items)
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (string|int|float|bool $item): string => Str::squish((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Digits only, because a CPV code is a number however it is written down — "72000000",
     * "72.000.000" and "72000000-5" are the same classification.
     *
     * @return list<string>
     */
    private static function cpvCodes(mixed $value): array
    {
        return collect(self::stringList($value))
            ->map(fn (string $code): string => preg_replace('/\D+/', '', $code) ?? '')
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
