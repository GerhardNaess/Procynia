<?php

namespace App\Services\OpportunitySources;

/**
 * Where an opportunity stands in its own lifecycle, in words no register owns.
 *
 * Doffin calls these ACTIVE, EXPIRED, AWARDED and CANCELLED. Those are Doffin's spellings of a
 * lifecycle every procurement register has, so the concept travels and the spelling does not —
 * translating it is the adapter's job, which is exactly what keeps the vocabulary out of the
 * controller and the watch profile.
 *
 * Null is not a case here: "any status" is the absence of a filter, and the criteria object says
 * that by leaving the field null rather than by naming a fifth state.
 */
enum OpportunityStatus: string
{
    /** Still open for offers. */
    case Open = 'open';

    /** The deadline has passed without an award being recorded. */
    case Expired = 'expired';

    /** A supplier has been chosen. */
    case Awarded = 'awarded';

    /** Withdrawn by the buyer. */
    case Cancelled = 'cancelled';

    /**
     * The value a UI or a stored search may carry, which is still the register's own spelling.
     *
     * Accepted here rather than in the controller so that one place knows the legacy words. The
     * frontend keeps sending "ACTIVE" — changing that is a UI change, and this phase has none —
     * while everything behind this method speaks the neutral lifecycle.
     */
    public static function fromRequestValue(?string $value): ?self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '' => null,
            'active', 'open' => self::Open,
            'expired' => self::Expired,
            'awarded' => self::Awarded,
            'cancelled', 'canceled' => self::Cancelled,
            default => null,
        };
    }
}
