<?php

namespace App\Services\OpportunitySources;

/**
 * Who a notice actually is, said in terms no register owns.
 *
 * Procynia already had a way to name a notice — (sourceKey, externalId) — and it answers a
 * different question than this one does. That pair names a *record in a register*: Doffin's
 * 2026-113736 and TED's 599740-2026 are two of them, and they are the same procurement. Asking
 * whether two records are the same thing by comparing register ids can only ever answer no.
 *
 * The registers do carry an answer, and it is exact rather than approximate. Every eForms notice
 * has two UUIDs, and Doffin and TED publish the identical values for the identical procurement:
 *
 *   procedure   Doffin procedureId   =  TED procedure-identifier
 *   notice      Doffin eFormId       =  TED notice-identifier
 *
 * The two are not interchangeable, and which one a question wants is the whole reason both are
 * here. A procurement runs from its contract notice through any changes to its award; all of those
 * are one procedure and several notices. So "is this the same opportunity the bid manager already
 * saw" is a procedure question, and "is this the same document" is a notice question.
 *
 * DELIBERATELY ABSENT: the TED publication number.
 *
 * Doffin stores it as tedId and it looks like the obvious key — one register naming a row in the
 * other, explicitly. It is not reliable as identity. TED republishes: publication 597334-2026 and
 * publication 600314-2026 are both the eForms notice fa2dd320-2938-42e1-a648-c05844ac5ad2, and
 * Doffin's tedId names only the first of them. Keying on it would leave the second looking like a
 * procurement Procynia had never seen. The notice UUID names both, which is why it is the one
 * recorded here.
 *
 * Nothing compares these values yet. This phase only gives them somewhere true to live.
 */
final class OpportunityNoticeIdentity
{
    public function __construct(
        /** One procurement, from its first announcement to its award. Doffin and TED agree on it. */
        public readonly ?string $procedureIdentifier = null,
        /** One eForms notice within that procurement — the call, a change to it, or the award. */
        public readonly ?string $noticeIdentifier = null,
    ) {}

    /**
     * What a register said, or nothing, rather than something that looks like an answer.
     *
     * A blank, a whitespace string and an absent key all mean the same thing — this register did
     * not tell us — and they all arrive as null, so no later comparison can match two notices on
     * the strength of both having said nothing.
     *
     * Case is folded because these are UUIDs, where case is presentation. Both registers emit
     * lowercase today, so this changes no observed value; it is here so that the day one of them
     * changes its mind, the answer is still the same identity rather than a silent non-match. That
     * is the same failure CpvCodeNormalizer exists to prevent, and the same reason to settle it in
     * one place instead of at each comparison.
     */
    public static function fromSource(mixed $procedureIdentifier, mixed $noticeIdentifier): self
    {
        return new self(
            self::identifier($procedureIdentifier),
            self::identifier($noticeIdentifier),
        );
    }

    /** True when this notice can be recognised again — by either question. */
    public function isKnown(): bool
    {
        return $this->procedureIdentifier !== null || $this->noticeIdentifier !== null;
    }

    private static function identifier(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }
}
