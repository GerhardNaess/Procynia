<?php

namespace App\Services\Doffin;

use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunityNoticeIdentity;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asking Doffin who a notice is, out loud.
 *
 * Doffin publishes the eForms UUIDs that name a procurement and a document — procedureId and
 * eFormId, the same values TED publishes as procedure-identifier and notice-identifier — but only
 * on the notices-api detail endpoint. The search endpoint Procynia reads for discovery returns
 * neither. There is no way to learn them except one HTTP request per notice.
 *
 * That cost is why this is a service and not two more lines in DoffinSourceAdapter. A mapper that
 * quietly fetched a detail per hit would turn a page of search results into a page of requests,
 * and nothing at the call site would say so. Here it is a thing a caller decides to do, for the
 * notices it decides to do it for. Discovery does not call it; the deduplication work that needs
 * identity will.
 *
 * WHAT THIS DOES NOT CONSULT: sentToTed.
 *
 * The flag is genuinely useful for narrowing a candidate set — a notice Doffin never forwarded has
 * no TED twin — but it is a statement about one notice, not about the procurement behind it. A
 * change notice can be false while the contract notice that opened the same procedure was true and
 * sits in TED under the same procedureId. Reading it here would mean a caller asking a direct
 * question got silence back for a reason it never asked about, so this service answers what it was
 * asked and leaves the narrowing to whoever is choosing which notices to enrich.
 */
class DoffinNoticeIdentityEnrichmentService
{
    /**
     * What Doffin's detail endpoint said, per notice id, for the life of this instance.
     *
     * @var array<string, OpportunityNoticeIdentity>
     */
    private array $lookedUp = [];

    public function __construct(
        private readonly DoffinPublicClient $client,
    ) {}

    /**
     * The identity Doffin holds for one of its own notice ids.
     *
     * Asked twice for the same id, this asks Doffin once — including when the answer was empty,
     * because a lookup that found nothing is still a lookup and repeating it would buy the same
     * nothing at the same price. The cache lives on the instance, so a run is however long one
     * caller holds one of these; nothing here outlives the process or crosses between runs.
     */
    public function identityFor(string $externalId): OpportunityNoticeIdentity
    {
        $noticeId = trim($externalId);

        if ($noticeId === '') {
            return new OpportunityNoticeIdentity;
        }

        if (array_key_exists($noticeId, $this->lookedUp)) {
            return $this->lookedUp[$noticeId];
        }

        return $this->lookedUp[$noticeId] = $this->fetchIdentity($noticeId);
    }

    /**
     * The same notice, now knowing what Doffin knows about it.
     *
     * A notice from another register is returned untouched: this service reads Doffin's endpoint
     * and has nothing to say about anyone else's ids, so a caller can hand it a mixed list without
     * sorting it first. A notice whose identity is already complete is returned untouched too,
     * which is the one case that costs no request.
     *
     * Only silence is filled. Whatever the notice arrived carrying stays, because the register that
     * sent it said it first-hand and this lookup is second-hand by construction.
     */
    public function enrich(NormalizedNotice $notice): NormalizedNotice
    {
        if ($notice->sourceKey !== DoffinSourceAdapter::SOURCE_KEY) {
            return $notice;
        }

        $known = $notice->identity;

        if ($known->procedureIdentifier !== null && $known->noticeIdentifier !== null) {
            return $notice;
        }

        $found = $this->identityFor($notice->externalId);

        if (! $found->isKnown()) {
            return $notice;
        }

        return $notice->withIdentity(new OpportunityNoticeIdentity(
            $known->procedureIdentifier ?? $found->procedureIdentifier,
            $known->noticeIdentifier ?? $found->noticeIdentifier,
        ));
    }

    /**
     * @param  iterable<int, NormalizedNotice>  $notices
     * @return array<int, NormalizedNotice>
     */
    public function enrichAll(iterable $notices): array
    {
        $enriched = [];

        foreach ($notices as $notice) {
            $enriched[] = $this->enrich($notice);
        }

        return $enriched;
    }

    /**
     * One detail request, and an empty identity for every way it can fail.
     *
     * A notice that has been withdrawn answers 404, a slow endpoint throws, and a response that is
     * not the shape Doffin documents has no identifiers in it. All three mean Procynia does not
     * know who this notice is, which is what an empty identity says. None of them may stop a
     * discovery run that was working fine before anyone asked this question, and none of them may
     * produce an identifier — a fabricated one would later match something, and be wrong.
     */
    private function fetchIdentity(string $noticeId): OpportunityNoticeIdentity
    {
        try {
            $detail = $this->client->noticeDetail($noticeId);
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][DOFFIN][IDENTITY] Could not read notice identity from Doffin.', [
                'notice_id' => $noticeId,
                'message' => $throwable->getMessage(),
            ]);

            return new OpportunityNoticeIdentity;
        }

        $identity = OpportunityNoticeIdentity::fromSource(
            $detail['procedureId'] ?? null,
            $detail['eFormId'] ?? null,
        );

        if (! $identity->isKnown()) {
            Log::info('[PROCYNIA][DOFFIN][IDENTITY] Doffin notice detail carried no eForms identifiers.', [
                'notice_id' => $noticeId,
            ]);
        }

        return $identity;
    }
}
