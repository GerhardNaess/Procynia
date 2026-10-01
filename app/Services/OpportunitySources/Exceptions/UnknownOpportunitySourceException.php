<?php

namespace App\Services\OpportunitySources\Exceptions;

use RuntimeException;

/**
 * Asked for a source Procynia does not have an adapter for.
 *
 * Thrown rather than answered with a default, because the only plausible default is whichever
 * adapter happens to be registered — and silently handing back Doffin for an unknown key is how a
 * TED notice would end up being read, linked and opened as a Doffin one.
 */
class UnknownOpportunitySourceException extends RuntimeException
{
    /** @param  list<string>  $knownKeys */
    public static function forKey(string $sourceKey, array $knownKeys): self
    {
        $known = $knownKeys === [] ? 'none are registered' : implode(', ', $knownKeys);

        return new self("No opportunity source adapter is registered for \"{$sourceKey}\" ({$known}).");
    }
}
