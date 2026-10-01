<?php

namespace App\Services\OpportunitySources;

use App\Models\OpportunitySourceRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answering "has this customer already got this procurement" across two registers.
 *
 * A live search asks one register. The case the answer belongs to may have been opened from the
 * other one — a bid manager who saved Doffin's 2026-113736 in May should not be offered TED's
 * 599740-2026 in June as something new, because it is the same tender and there is one case. The
 * only thing that can say so is the identity layer: the eForms UUIDs both registers publish, and
 * the source records written from them. Nothing here compares a title, a buyer or a deadline.
 *
 * WHAT THIS COSTS, AND WHY IT IS NOT A PAGE OF REQUESTS.
 *
 * TED puts both identifiers in every search result, so a TED page is answered for free and the
 * hits are recorded while they are in hand — register() makes no external call, and after the
 * first search the records are simply there. Doffin publishes neither in search results, so a
 * Doffin hit can only be identified by asking its detail endpoint, one request per notice. That
 * is the cost 5C built a separate service to keep out of normalisation, and it stays out of the
 * common path here too:
 *
 *   - nothing is asked unless the customer has a case whose procurement this register has not
 *     been linked to yet, which is the only situation where an answer could change anything;
 *   - nothing is asked about a record already registered, which after the first time is all of
 *     them;
 *   - and never more than MAX_LOOKUPS_PER_SEARCH notices in one request, so a page of results
 *     cannot turn into an unbounded page of requests however badly the gate is misjudged.
 *
 * A Doffin-only customer therefore pays nothing at all: every case they have is already covered
 * by a Doffin record, so the gate never opens.
 */
class LiveSearchOpportunityLinker
{
    /**
     * The most notices one search may ask a register to identify.
     *
     * A ceiling rather than a tuning knob. The gate below should keep the real number far under
     * it; this is what stops a pathological case — a customer with one unlinked case and a page
     * of unknown hits — from spending a page of requests on a question nobody asked out loud.
     */
    private const MAX_LOOKUPS_PER_SEARCH = 25;

    public function __construct(
        private readonly OpportunityRegistrar $registrar,
    ) {}

    /**
     * Which procurement each of these hits belongs to, as far as anyone can tell.
     *
     * Absent from the result means unknown, which is not the same as "belongs to nothing" and is
     * the ordinary answer for a Doffin hit nobody has had reason to identify.
     *
     * @param  array<int, NormalizedNotice>  $notices  the hits of one search, all from one register
     * @param  array<int, int>  $caseOpportunityIds  procurements this customer already has cases for
     * @return array<string, int> external id => opportunity id
     */
    public function linkHits(string $sourceKey, array $notices, array $caseOpportunityIds): array
    {
        $notices = array_values(array_filter(
            $notices,
            fn (mixed $notice): bool => $notice instanceof NormalizedNotice && trim($notice->externalId) !== '',
        ));

        if ($notices === []) {
            return [];
        }

        $links = $this->alreadyRegistered($sourceKey, $notices);
        $links = $this->registerWhatTheHitsSay($notices, $links);

        if ($caseOpportunityIds === [] || ! $this->worthAsking($sourceKey, $caseOpportunityIds)) {
            return $links;
        }

        return $this->askTheRegister($sourceKey, $notices, $links);
    }

    /**
     * Which of these hits the given cases cover.
     *
     * The query is whichever set of cases the caller is asking about — the customer's open cases
     * or their history — so the same links answer both questions without being rebuilt, and both
     * answers stay inside whatever visibility that query already enforces.
     *
     * @param  array<string, int>  $links
     * @return array<int, string> external ids
     */
    public function coveredExternalIds(array $links, Builder $cases): array
    {
        if ($links === []) {
            return [];
        }

        $held = (clone $cases)
            ->whereIn('opportunity_id', array_values(array_unique($links)))
            ->pluck('opportunity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        return array_values(array_keys(array_filter(
            $links,
            fn (int $opportunityId): bool => $held->has($opportunityId),
        )));
    }

    /**
     * The records this register has already filed, which costs one query and answers most hits.
     *
     * @param  array<int, NormalizedNotice>  $notices
     * @return array<string, int>
     */
    private function alreadyRegistered(string $sourceKey, array $notices): array
    {
        return OpportunitySourceRecord::query()
            ->where('source', $sourceKey)
            ->whereIn('external_id', array_map(
                fn (NormalizedNotice $notice): string => trim($notice->externalId),
                $notices,
            ))
            ->pluck('opportunity_id', 'external_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Write down the identity a hit arrived carrying, while it is in hand.
     *
     * Free — register() never calls anyone — and worth doing here rather than only at save time,
     * because a register that names its procurements in search results has already answered the
     * question this class exists to ask, and the answer would otherwise be thrown away with the
     * response. It is the same thing the nightly watch sweep does with the same hits.
     *
     * @param  array<int, NormalizedNotice>  $notices
     * @param  array<string, int>  $links
     * @return array<string, int>
     */
    private function registerWhatTheHitsSay(array $notices, array $links): array
    {
        foreach ($notices as $notice) {
            $externalId = trim($notice->externalId);

            if (isset($links[$externalId]) || $notice->identity->procedureIdentifier === null) {
                continue;
            }

            $opportunity = $this->registrar->register($notice);

            if ($opportunity !== null) {
                $links[$externalId] = (int) $opportunity->id;
            }
        }

        return $links;
    }

    /**
     * Is there a case here that this register has not been linked to?
     *
     * One query, and the whole reason a Doffin search normally costs nothing extra. A customer
     * whose every case already has a record in this register cannot learn anything from asking:
     * every hit that could match is matched already, and every hit that is not matched belongs to
     * a procurement they have no case for.
     *
     * @param  array<int, int>  $caseOpportunityIds
     */
    private function worthAsking(string $sourceKey, array $caseOpportunityIds): bool
    {
        $wanted = array_values(array_unique($caseOpportunityIds));

        $covered = OpportunitySourceRecord::query()
            ->where('source', $sourceKey)
            ->whereIn('opportunity_id', $wanted)
            ->distinct()
            ->count('opportunity_id');

        return $covered < count($wanted);
    }

    /**
     * Ask, for the hits still unaccounted for, up to the ceiling.
     *
     * resolveWithLookup() decides whether this register can be asked at all; a register that
     * answers its own search results has nothing left to tell, and returns null without a request.
     *
     * @param  array<int, NormalizedNotice>  $notices
     * @param  array<string, int>  $links
     * @return array<string, int>
     */
    private function askTheRegister(string $sourceKey, array $notices, array $links): array
    {
        $budget = self::MAX_LOOKUPS_PER_SEARCH;

        foreach ($notices as $notice) {
            if ($budget <= 0) {
                break;
            }

            $externalId = trim($notice->externalId);

            if (isset($links[$externalId])) {
                continue;
            }

            $budget--;
            $opportunity = $this->registrar->resolveWithLookup($sourceKey, $externalId);

            if ($opportunity !== null) {
                $links[$externalId] = (int) $opportunity->id;
            }
        }

        return $links;
    }
}
