<?php

namespace App\Services\OpportunitySources;

use App\Services\OpportunitySources\Exceptions\UnknownOpportunitySourceException;
use InvalidArgumentException;

/**
 * Source key in, adapter out.
 *
 * Until now the container bound OpportunitySourceAdapter to DoffinSourceAdapter, which meant every
 * source-neutral consumer was really holding Doffin and calling it "the source". That reads fine
 * while there is one register and becomes wrong the moment there are two: a watch record from TED
 * would have been handed Doffin's URL builder, and the code would have had no way to notice.
 *
 * So this exists to answer one question — "which adapter speaks for this source?" — and nothing
 * else. It does not decide which source the user meant; that comes from the row, the payload or
 * the flow, and is passed in.
 *
 * Keys come from the adapters themselves via sourceKey(), so there is no second list to keep in
 * step with the first.
 */
class OpportunitySourceRegistry
{
    /** @var array<string, OpportunitySourceAdapter> */
    private array $adapters = [];

    /** @param  iterable<OpportunitySourceAdapter>  $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    /**
     * Fail fast on a blank or repeated key.
     *
     * A silent overwrite would be the worst outcome available here: two adapters claiming "doffin"
     * would leave whichever was registered last in charge, and the collision would only surface as
     * results quietly coming from the wrong place.
     */
    public function register(OpportunitySourceAdapter $adapter): void
    {
        $key = trim($adapter->sourceKey());

        if ($key === '') {
            throw new InvalidArgumentException(
                sprintf('%s returned an empty source key; a source cannot be addressed without one.', $adapter::class)
            );
        }

        if (isset($this->adapters[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Two adapters claim the source key "%s": %s and %s.',
                $key,
                $this->adapters[$key]::class,
                $adapter::class,
            ));
        }

        $this->adapters[$key] = $adapter;
    }

    /**
     * The adapter for this source. Throws when there is none.
     *
     * For a caller that must have an adapter to do its job — running a search, naming a source.
     */
    public function get(string $sourceKey): OpportunitySourceAdapter
    {
        return $this->adapters[trim($sourceKey)]
            ?? throw UnknownOpportunitySourceException::forKey($sourceKey, $this->keys());
    }

    /**
     * The adapter for this source, or null.
     *
     * For a caller reading a stored row whose source may predate — or outlive — the adapters this
     * installation has. Those callers want to fall back on what is already recorded rather than
     * fail a page, but they must not fall back on a different source's adapter.
     */
    public function find(?string $sourceKey): ?OpportunitySourceAdapter
    {
        if ($sourceKey === null) {
            return null;
        }

        return $this->adapters[trim($sourceKey)] ?? null;
    }

    /** @return array<string, OpportunitySourceAdapter> keyed by source key */
    public function all(): array
    {
        return $this->adapters;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }
}
