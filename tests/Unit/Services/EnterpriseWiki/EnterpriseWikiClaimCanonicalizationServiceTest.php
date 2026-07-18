<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiClaimAnchorTextNormalizer;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimCanonicalizationService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use PHPUnit\Framework\TestCase;

/**
 * Cross-page overgeneration fix: areEquivalentTexts() is the Tier-2 deterministic equivalence
 * check (Del 3) — never the sole identity signal on its own (callers always combine it with the
 * Tier-1 hard key: customer + content_origin + document/source identity + cited source
 * elements), but the decision of whether two claim occurrences that share a Tier-1 key express
 * the same underlying fact or two different ones.
 */
class EnterpriseWikiClaimCanonicalizationServiceTest extends TestCase
{
    private function service(): EnterpriseWikiClaimCanonicalizationService
    {
        return new EnterpriseWikiClaimCanonicalizationService(new EnterpriseWikiClaimAnchorTextNormalizer(new EnterpriseWikiLinkParser));
    }

    public function test_identical_text_is_equivalent(): void
    {
        $text = 'Servicedesk Bravo er tilgjengelig mandag til fredag fra klokken 09.00 til 15.00.';

        $this->assertTrue($this->service()->areEquivalentTexts($text, $text));
    }

    public function test_task_example_paraphrases_are_equivalent(): void
    {
        $this->assertTrue($this->service()->areEquivalentTexts(
            'Servicedesk Bravo er åpen fra 09.00 til 15.00 på hverdager.',
            'Åpningstiden for Servicedesk Bravo er mandag–fredag kl. 09–15.',
        ));
    }

    public function test_task_example_distinct_facts_are_not_equivalent(): void
    {
        // Same source element, similar words, but a different assertion (opening hours vs a
        // response-time commitment) — must not be merged into one fact.
        $this->assertFalse($this->service()->areEquivalentTexts(
            'Servicedesk Bravo er åpen fra 09.00 til 15.00.',
            'Servicedesk Bravo skal besvare alle saker innen kl. 15.00.',
        ));
    }

    public function test_run_35_hours_paraphrases_are_equivalent(): void
    {
        $this->assertTrue($this->service()->areEquivalentTexts(
            'Servicedesk Nord er tilgjengelig på hverdager fra klokken 08.00 til 16.00.',
            'Servicedesk Nord håndterer henvendelser i ordinær arbeidstid, mandag til fredag klokken 08.00–16.00.',
        ));
    }

    public function test_run_35_hours_claim_is_not_equivalent_to_unrelated_role_claim(): void
    {
        // Same source element and entity ("Servicedesk Nord"), but one states the opening hours
        // and the other describes its role — different facts.
        $this->assertFalse($this->service()->areEquivalentTexts(
            'Servicedesk Nord er tilgjengelig på hverdager fra klokken 08.00 til 16.00.',
            'Servicedesk Nord fungerer som den primære kanalen for brukere som trenger driftsstøtte knyttet til tjenestene beskrevet i rutinen i arbeidstiden.',
        ));
    }

    public function test_run_35_document_description_paraphrases_are_equivalent(): void
    {
        $this->assertTrue($this->service()->areEquivalentTexts(
            'Denne siden dokumenterer gjeldende rutinebeskrivelse for driftsstøtte ved Procynia, versjon 2026-07-19.',
            'Procynia er omtalt gjennom en rutinebeskrivelse for driftsstøtte med versjonsdato 2026-07-19.',
        ));
    }

    public function test_run_35_incident_paraphrases_are_equivalent(): void
    {
        $this->assertTrue($this->service()->areEquivalentTexts(
            'Kritiske hendelser skal registreres av driftsvakt og prioriteres før eskalering.',
            'Kritiske hendelser skal registreres av vakthavende og gis høyeste prioritet før eventuell eskalering.',
        ));
    }

    public function test_different_recurrence_period_is_not_equivalent(): void
    {
        // "hver måned" vs "hvert kvartal" — every other word matches, but the period differs.
        $this->assertFalse($this->service()->areEquivalentTexts(
            'Tilgangsrettigheter gjennomgås av tjenesteeier hver måned.',
            'Tilgangsrettigheter gjennomgås av tjenesteeier hvert kvartal.',
        ));
    }

    public function test_different_time_value_is_not_equivalent(): void
    {
        $this->assertFalse($this->service()->areEquivalentTexts(
            'Servicedesk Bravo er tilgjengelig fra klokken 09.00 til 15.00.',
            'Servicedesk Bravo er tilgjengelig fra klokken 09.00 til 17.00.',
        ));
    }

    public function test_compound_claim_combining_two_facts_is_not_equivalent_to_one_of_them(): void
    {
        // A sentence asserting two independent facts must not be treated as a wording variant
        // of just one of them (Del 4: this is primarily prevented at extraction time — this is
        // the canonicalization-side backstop).
        $this->assertFalse($this->service()->areEquivalentTexts(
            'Servicedesk er åpen 09-15 og kritiske hendelser prioriteres av vaktansvarlig.',
            'Kritiske hendelser prioriteres av vaktansvarlig.',
        ));
    }

    public function test_empty_text_is_never_equivalent(): void
    {
        $this->assertFalse($this->service()->areEquivalentTexts('', 'Servicedesk er åpen.'));
    }
}
