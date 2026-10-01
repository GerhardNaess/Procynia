<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunityNoticeIdentity;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedSourceAdapter;
use Mockery;
use Tests\TestCase;

/**
 * Telling a procurement apart from the records that describe it.
 *
 * Procynia has always named a notice by where it read it — a source key and that register's own
 * id. That is a true answer to a different question. Doffin's 2026-113736 and TED's 599740-2026
 * are two records of one procurement, and no comparison of register ids can discover it.
 *
 * Both registers publish the eForms UUIDs, and they publish the same values. The fixtures here are
 * real: every UUID below was read from the live Doffin and TED APIs on 30 September 2026 and
 * checked against the other register's record of the same procurement. Nothing here touches the
 * network, and nothing here compares two notices — this phase only records who they are.
 */
class OpportunityNoticeIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $hit */
    private function fromTed(array $hit): NormalizedNotice
    {
        $adapter = new TedSourceAdapter(Mockery::mock(TedSearchClient::class));

        return $adapter->normalizeLiveSearchHit($hit);
    }

    /** @param array<string, mixed> $hit */
    private function fromDoffin(array $hit): NormalizedNotice
    {
        $adapter = new DoffinSourceAdapter(Mockery::mock(DoffinLiveSearchService::class));

        return $adapter->normalizeLiveSearchHit($hit);
    }

    // ------------------------------------------------------------------ what a register says

    /**
     * Sykehusinnkjøp's annual inspection contract for St. Olavs, as TED published it.
     *
     * Doffin's record of the same procurement is 2026-113736, and carries these same two UUIDs
     * under its own names — procedureId and eFormId.
     */
    public function test_ted_reports_the_procurement_and_the_document_it_was_given(): void
    {
        $notice = $this->fromTed([
            'publication-number' => '599740-2026',
            'notice-type' => 'cn-standard',
            'procedure-identifier' => '19379be5-7821-4761-b6bf-30876e2f678e',
            'notice-identifier' => '50859edc-926f-405a-a1ad-6590a08a5ba9',
        ]);

        $this->assertSame('19379be5-7821-4761-b6bf-30876e2f678e', $notice->identity->procedureIdentifier);
        $this->assertSame('50859edc-926f-405a-a1ad-6590a08a5ba9', $notice->identity->noticeIdentifier);
        // The publication number stays what it has always been: TED's own row, not an identity.
        $this->assertSame('599740-2026', $notice->externalId);
    }

    /**
     * Hamarøy kommune's winter road maintenance: one procurement, announced in May and awarded in
     * September. Two documents, two TED publications, one procedure.
     *
     * This is why both identifiers exist. Asking "has the bid manager seen this opportunity" is a
     * question about the procedure; asking "is this the same document" is not.
     */
    public function test_one_procurement_carries_several_notices(): void
    {
        $call = $this->fromTed([
            'publication-number' => '333255-2026',
            'notice-type' => 'cn-standard',
            'procedure-identifier' => '7d02de59-3686-415d-a2a1-2fc26652e0d0',
            'notice-identifier' => 'ceaeb487-d3bb-42a4-9fcc-2eb4a3df55d8',
        ]);
        $award = $this->fromTed([
            'publication-number' => '600064-2026',
            'notice-type' => 'can-standard',
            'procedure-identifier' => '7d02de59-3686-415d-a2a1-2fc26652e0d0',
            'notice-identifier' => '6835ebe3-ce0f-43a2-b3eb-5d49e61baea7',
        ]);

        $this->assertSame($call->identity->procedureIdentifier, $award->identity->procedureIdentifier);
        $this->assertNotSame($call->identity->noticeIdentifier, $award->identity->noticeIdentifier);
        $this->assertNotSame($call->externalId, $award->externalId);
    }

    // ------------------------------------------------------------------ one notice, several records

    /**
     * TED republishes. Publications 597334-2026 and 600314-2026 are the same eForms award notice
     * for Sykehusinnkjøp's breast implant framework, published a day apart.
     *
     * Doffin's tedId names only the first of them, which is precisely why the publication number
     * is not the identity: keyed on it, the second publication would look like a procurement
     * Procynia had never seen.
     */
    public function test_one_notice_can_be_two_records_in_the_same_register(): void
    {
        $first = $this->fromTed([
            'publication-number' => '597334-2026',
            'notice-type' => 'can-standard',
            'procedure-identifier' => 'f3e5cec6-4de2-4373-ba3e-32d01051364f',
            'notice-identifier' => 'fa2dd320-2938-42e1-a648-c05844ac5ad2',
        ]);
        $republished = $this->fromTed([
            'publication-number' => '600314-2026',
            'notice-type' => 'can-standard',
            'procedure-identifier' => 'f3e5cec6-4de2-4373-ba3e-32d01051364f',
            'notice-identifier' => 'fa2dd320-2938-42e1-a648-c05844ac5ad2',
        ]);

        $this->assertSame($first->identity->noticeIdentifier, $republished->identity->noticeIdentifier);
        $this->assertNotSame($first->externalId, $republished->externalId);
        $this->assertSame($first->sourceKey, $republished->sourceKey);
    }

    /**
     * And across registers, which is the case the whole model is for.
     *
     * The Doffin payload here carries the identifiers under Doffin's own names, because the
     * notices-api record does. The live search endpoint does not return them — see the test below
     * — so this proves the mapping is right before the phase that goes and fetches one.
     */
    public function test_one_notice_can_be_a_record_in_two_registers(): void
    {
        $fromTed = $this->fromTed([
            'publication-number' => '599740-2026',
            'notice-type' => 'cn-standard',
            'procedure-identifier' => '19379be5-7821-4761-b6bf-30876e2f678e',
            'notice-identifier' => '50859edc-926f-405a-a1ad-6590a08a5ba9',
        ]);
        $fromDoffin = $this->fromDoffin([
            'id' => '2026-113736',
            'heading' => 'Årlig kontroll av sikkerhetsventilasjonskap/avtrekkskap og sikkerhetsbenker til St. Olavs hospital',
            'procedureId' => '19379be5-7821-4761-b6bf-30876e2f678e',
            'eFormId' => '50859edc-926f-405a-a1ad-6590a08a5ba9',
        ]);

        $this->assertNotSame($fromTed->sourceKey, $fromDoffin->sourceKey);
        $this->assertNotSame($fromTed->externalId, $fromDoffin->externalId);
        $this->assertSame($fromTed->identity->procedureIdentifier, $fromDoffin->identity->procedureIdentifier);
        $this->assertSame($fromTed->identity->noticeIdentifier, $fromDoffin->identity->noticeIdentifier);
    }

    // ------------------------------------------------------------------ saying nothing

    /**
     * A Doffin live search hit, exactly as the register sends one.
     *
     * The search endpoint returns no identifiers at all — they live on the notices-api detail
     * record, one request per notice — so this is what every Doffin result looks like today. The
     * identity is empty, which says Procynia does not know. It does not say the notice has none,
     * and it invents nothing from the id it does have.
     */
    public function test_a_doffin_search_hit_carries_no_identity_and_none_is_invented(): void
    {
        $notice = $this->fromDoffin([
            'id' => '2026-113736',
            'heading' => 'Årlig kontroll av sikkerhetsventilasjonskap/avtrekkskap',
            'status' => 'ACTIVE',
            'sentToTed' => true,
        ]);

        $this->assertNull($notice->identity->procedureIdentifier);
        $this->assertNull($notice->identity->noticeIdentifier);
        $this->assertFalse($notice->identity->isKnown());
        $this->assertSame('2026-113736', $notice->externalId, 'the register record is unaffected');
        // Raw data stays raw: nothing was derived from sentToTed in this phase.
        $this->assertTrue($notice->rawPayload['sentToTed']);
    }

    public function test_a_ted_hit_without_the_fields_reports_no_identity(): void
    {
        $notice = $this->fromTed([
            'publication-number' => '337011-2026',
            'notice-type' => 'cn-standard',
        ]);

        $this->assertNull($notice->identity->procedureIdentifier);
        $this->assertNull($notice->identity->noticeIdentifier);
    }

    /** Absent, blank, whitespace and not-a-string all mean the register did not say. */
    public function test_an_identity_is_never_made_out_of_nothing(): void
    {
        foreach ([null, '', '   ', [], ['a'], new \stdClass] as $index => $value) {
            $identity = OpportunityNoticeIdentity::fromSource($value, $value);

            $this->assertNull($identity->procedureIdentifier, "case {$index}");
            $this->assertNull($identity->noticeIdentifier, "case {$index}");
            $this->assertFalse($identity->isKnown(), "case {$index}");
        }
    }

    /** Two registers writing one UUID differently are still naming one procurement. */
    public function test_how_a_uuid_is_written_is_not_part_of_the_identity(): void
    {
        $identity = OpportunityNoticeIdentity::fromSource(
            '  19379BE5-7821-4761-B6BF-30876E2F678E  ',
            '50859EDC-926F-405A-A1AD-6590A08A5BA9',
        );

        $this->assertSame('19379be5-7821-4761-b6bf-30876e2f678e', $identity->procedureIdentifier);
        $this->assertSame('50859edc-926f-405a-a1ad-6590a08a5ba9', $identity->noticeIdentifier);
        $this->assertTrue($identity->isKnown());
    }

    /** One identifier is enough to be recognisable; neither is not. */
    public function test_knowing_only_one_of_the_two_still_counts_as_knowing_something(): void
    {
        $this->assertTrue(OpportunityNoticeIdentity::fromSource('19379be5', null)->isKnown());
        $this->assertTrue(OpportunityNoticeIdentity::fromSource(null, '50859edc')->isKnown());
        $this->assertFalse((new OpportunityNoticeIdentity)->isKnown());
    }

    // ------------------------------------------------------------------ what the frontend sees

    /**
     * Identity is not a UI change.
     *
     * The discovery card's payload is a contract with the frontend, and this phase adds nothing to
     * it — a notice that now knows which procurement it belongs to serialises exactly as one that
     * does not.
     */
    public function test_the_discovery_payload_is_unchanged_by_identity(): void
    {
        $hit = [
            'publication-number' => '599740-2026',
            'notice-type' => 'cn-standard',
            'notice-title' => ['eng' => 'Inspection of ventilation system'],
        ];

        $withoutIdentity = $this->fromTed($hit)->toDiscoveryPayload();
        $withIdentity = $this->fromTed($hit + [
            'procedure-identifier' => '19379be5-7821-4761-b6bf-30876e2f678e',
            'notice-identifier' => '50859edc-926f-405a-a1ad-6590a08a5ba9',
        ])->toDiscoveryPayload();

        $this->assertSame($withoutIdentity, $withIdentity);
        $this->assertArrayNotHasKey('identity', $withIdentity);
        $this->assertArrayNotHasKey('procedure_identifier', $withIdentity);
    }
}
