<?php

namespace App\Services\OpportunitySources;

use App\Models\Opportunity;
use App\Models\OpportunityNotice;
use App\Models\OpportunitySourceRecord;
use App\Models\SavedNotice;
use App\Services\Doffin\DoffinNoticeIdentityEnrichmentService;
use App\Services\Doffin\DoffinSourceAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writing down that two register records are one procurement.
 *
 * Everything this class does rests on identifiers the registers publish themselves — the eForms
 * procedure and notice UUIDs, which Doffin and TED emit with identical values for the identical
 * procurement. Nothing is compared, guessed or scored. A title, a buyer, a CPV code and a deadline
 * are all things two different procurements routinely share and one procurement's two records
 * routinely differ on, so none of them appears anywhere below.
 *
 * TWO WAYS IN, AND THE DIFFERENCE MATTERS.
 *
 * register() writes down what a notice already says about itself and never asks anyone anything.
 * It is safe wherever notices arrive in bulk — the nightly watch sweep calls it for every hit —
 * because a TED search result carries both identifiers for free and a Doffin one carries neither,
 * so the Doffin sweep simply registers nothing and costs nothing.
 *
 * resolveWithLookup() is allowed to go and ask, which for Doffin means one detail request. It is
 * called from the one place a single notice matters enough to pay for: the moment a bid manager
 * saves a case. Afterwards the source record exists, so the same record never costs a second
 * request. Keeping these two apart is the whole reason Doffin enrichment never became a line in
 * DoffinSourceAdapter — a page of search results must not become a page of requests.
 *
 * WHAT HAPPENS WHEN THERE IS NO IDENTITY.
 *
 * Nothing, and that is the design. Without a procedure identifier this returns null and the caller
 * keeps doing exactly what it did before: finding a case by (customer_id, source, external_id).
 * Two notices that both lack identity are not thereby the same notice — that would merge unrelated
 * procurements on the strength of a shared silence, which is the worst failure available here and
 * the reason an empty identity can never reach a lookup.
 */
class OpportunityRegistrar
{
    public function __construct(
        private readonly DoffinNoticeIdentityEnrichmentService $doffinIdentities,
        private readonly OpportunitySourceRegistry $sources,
    ) {}

    /**
     * Record what this notice says about which procurement it belongs to.
     *
     * Makes no external call, ever. A notice whose register did not say is not registered, and
     * returns null rather than an opportunity built out of nothing.
     */
    public function register(NormalizedNotice $notice): ?Opportunity
    {
        return $this->store(
            $notice->sourceKey,
            $notice->externalId,
            $notice->sourceUrl,
            $notice->identity,
        );
    }

    /**
     * The procurement behind a register record, asking the register if Procynia has to.
     *
     * Three steps, cheapest first: a record already registered answers immediately; otherwise the
     * register is asked, which only Doffin needs — TED puts both identifiers in its search results,
     * so a TED record that is not registered yet was simply never discovered through a path that
     * registers, and there is nothing to look up. A register with no lookup, or a lookup that comes
     * back empty, gives null and the caller falls back on what it did before.
     */
    public function resolveWithLookup(string $source, string $externalId): ?Opportunity
    {
        $sourceKey = trim($source);
        $recordId = trim($externalId);

        if ($sourceKey === '' || $recordId === '') {
            return null;
        }

        $existing = OpportunitySourceRecord::query()
            ->where('source', $sourceKey)
            ->where('external_id', $recordId)
            ->first();

        if ($existing instanceof OpportunitySourceRecord) {
            return $existing->opportunity;
        }

        if ($sourceKey !== DoffinSourceAdapter::SOURCE_KEY) {
            return null;
        }

        return $this->store(
            $sourceKey,
            $recordId,
            $this->sources->find($sourceKey)?->sourceUrl($recordId),
            $this->doffinIdentities->identityFor($recordId),
        );
    }

    /**
     * This customer's case for this procurement, if they have one.
     *
     * Scoped to the customer because a procurement is public and a case is not: two customers
     * bidding on the same tender have two entirely separate cases, and always will.
     */
    public function caseFor(int $customerId, Opportunity $opportunity): ?SavedNotice
    {
        return SavedNotice::query()
            ->where('customer_id', $customerId)
            ->where('opportunity_id', $opportunity->id)
            ->first();
    }

    /**
     * Put one register record under the procurement it belongs to.
     *
     * The order is procurement, then document, then record, because each is the parent of the
     * next and a source record without an opportunity would be a row that cannot answer the
     * question the table exists for. All of it in one transaction: a half-written identity is
     * worse than none, because the missing half looks like a fact.
     */
    private function store(
        string $source,
        string $externalId,
        ?string $sourceUrl,
        OpportunityNoticeIdentity $identity,
    ): ?Opportunity {
        $sourceKey = trim($source);
        $recordId = trim($externalId);
        $procedureIdentifier = $identity->procedureIdentifier;

        if ($sourceKey === '' || $recordId === '' || $procedureIdentifier === null) {
            return null;
        }

        return DB::transaction(function () use ($sourceKey, $recordId, $sourceUrl, $identity, $procedureIdentifier): Opportunity {
            $now = now();

            $opportunity = Opportunity::query()->firstOrCreate(
                ['procedure_identifier' => $procedureIdentifier],
                ['first_seen_at' => $now, 'last_seen_at' => $now],
            );

            if ($opportunity->wasRecentlyCreated === false) {
                $opportunity->forceFill(['last_seen_at' => $now])->save();
            }

            $notice = $this->storeNotice($opportunity, $identity->noticeIdentifier, $now);

            if ($notice instanceof OpportunityNotice && $notice->opportunity_id !== $opportunity->id) {
                // The registers agree about this in every case observed, so a disagreement is a
                // defect somewhere rather than a fact about procurement. The document keeps the
                // procurement it was first filed under — moving it would silently re-file every
                // record already hanging from it, and possibly a customer's case with them — and
                // the record joins it there, so the tree stays one tree.
                Log::warning('[PROCYNIA][OPPORTUNITY] A notice identifier arrived under a second procedure.', [
                    'notice_identifier' => $identity->noticeIdentifier,
                    'stored_opportunity_id' => $notice->opportunity_id,
                    'incoming_procedure_identifier' => $procedureIdentifier,
                    'source' => $sourceKey,
                    'external_id' => $recordId,
                ]);

                $opportunity = $notice->opportunity;
            }

            $this->storeSourceRecord($opportunity, $notice, $sourceKey, $recordId, $sourceUrl, $now);

            return $opportunity;
        });
    }

    /** The document, when the register named it. Null when it did not, which is not an error. */
    private function storeNotice(Opportunity $opportunity, ?string $noticeIdentifier, mixed $now): ?OpportunityNotice
    {
        if ($noticeIdentifier === null) {
            return null;
        }

        $notice = OpportunityNotice::query()->firstOrCreate(
            ['notice_identifier' => $noticeIdentifier],
            ['opportunity_id' => $opportunity->id, 'first_seen_at' => $now, 'last_seen_at' => $now],
        );

        if (! $notice->wasRecentlyCreated) {
            $notice->forceFill(['last_seen_at' => $now])->save();
        }

        return $notice;
    }

    /**
     * The register's own record of it.
     *
     * An existing record is updated rather than duplicated, and its procurement is never changed:
     * a record that has been filed under one opportunity stays there, because moving it would
     * quietly orphan whatever was built on the old answer. Its document link, on the other hand,
     * is allowed to go from unknown to known — that is the ordinary path for a Doffin record
     * registered from search and enriched later.
     */
    private function storeSourceRecord(
        Opportunity $opportunity,
        ?OpportunityNotice $notice,
        string $source,
        string $externalId,
        ?string $sourceUrl,
        mixed $now,
    ): OpportunitySourceRecord {
        $record = OpportunitySourceRecord::query()->firstOrNew([
            'source' => $source,
            'external_id' => $externalId,
        ]);

        if (! $record->exists) {
            $record->opportunity_id = $opportunity->id;
            $record->first_seen_at = $now;
        } elseif ($record->opportunity_id !== $opportunity->id) {
            Log::warning('[PROCYNIA][OPPORTUNITY] A register record arrived under a second procedure.', [
                'source' => $source,
                'external_id' => $externalId,
                'stored_opportunity_id' => $record->opportunity_id,
                'incoming_opportunity_id' => $opportunity->id,
            ]);
        }

        if ($notice instanceof OpportunityNotice) {
            $record->opportunity_notice_id = $notice->id;
        }

        if ($sourceUrl !== null) {
            $record->source_url = $sourceUrl;
        }

        $record->last_seen_at = $now;
        $record->save();

        return $record;
    }
}
