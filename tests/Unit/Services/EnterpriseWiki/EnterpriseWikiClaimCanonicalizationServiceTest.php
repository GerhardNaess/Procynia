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

    // =========================================================================
    // isGenuineBestPracticeText() — Del 2/4 deterministic backend guard, task's own examples
    // =========================================================================

    public function test_recommendation_with_boer_is_genuine_best_practice(): void
    {
        $this->assertTrue($this->service()->isGenuineBestPracticeText('Det anbefales å etablere døgnbemannet vakt.'));
    }

    public function test_current_state_assertion_is_not_genuine_best_practice(): void
    {
        $this->assertFalse($this->service()->isGenuineBestPracticeText('Kunden har døgnbemannet vaktordning.'));
    }

    public function test_boer_review_recommendation_is_genuine_best_practice(): void
    {
        $this->assertTrue($this->service()->isGenuineBestPracticeText('Tilgangsrettigheter bør gjennomgås regelmessig.'));
    }

    public function test_kan_redusere_recommendation_is_genuine_best_practice(): void
    {
        $this->assertTrue($this->service()->isGenuineBestPracticeText('En selvbetjeningsportal kan redusere belastningen på servicedesk.'));
    }

    public function test_hensiktsmessig_recommendation_is_genuine_best_practice(): void
    {
        $this->assertTrue($this->service()->isGenuineBestPracticeText('Det kan være hensiktsmessig å definere tydelige KPI-er.'));
    }

    public function test_customer_uses_tool_assertion_is_not_genuine_best_practice(): void
    {
        $this->assertFalse($this->service()->isGenuineBestPracticeText('Servicedesk bruker ServiceNow.'));
    }

    public function test_supplier_follows_standard_assertion_is_not_genuine_best_practice(): void
    {
        $this->assertFalse($this->service()->isGenuineBestPracticeText('Leverandøren følger ISO 27001.'));
    }

    public function test_plain_factual_claim_without_modal_is_not_genuine_best_practice(): void
    {
        $this->assertFalse($this->service()->isGenuineBestPracticeText('Kritiske saker besvares innen 15 minutter.'));
    }

    public function test_skal_requirement_wording_is_not_treated_as_best_practice_signal(): void
    {
        // "skal"/"må" are requirement/obligation language, not recommendation language — the task
        // explicitly excludes them from best-practice signals so a contractual requirement can't
        // be waved through just because it contains a modal verb.
        $this->assertFalse($this->service()->isGenuineBestPracticeText('Kritiske saker skal besvares innen 15 minutter.'));
    }

    public function test_wording_drifted_from_recommendation_to_fact_is_no_longer_genuine(): void
    {
        $this->assertTrue($this->service()->isGenuineBestPracticeText('Det anbefales å etablere døgnbemannet vakt.'));
        $this->assertFalse($this->service()->isGenuineBestPracticeText('Kunden har etablert døgnbemannet vakt.'));
    }

    public function test_empty_text_is_never_genuine_best_practice(): void
    {
        $this->assertFalse($this->service()->isGenuineBestPracticeText(''));
    }

    // =========================================================================
    // detectDeterministicConflict() — Del 3's cross-language verification safety net
    // =========================================================================

    public function test_time_format_variants_are_not_a_conflict(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Servicedesk er tilgjengelig fra klokken 09.00 til 15.00.',
            'The service desk is available from 09:00 to 15:00.',
        ));
    }

    public function test_duration_format_variants_are_not_a_conflict(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Kritiske hendelser skal besvares innen 30 minutter.',
            'Critical incidents shall be responded to within 30 min.',
        ));
    }

    public function test_weekday_and_business_days_phrasing_is_not_a_conflict(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Servicedesk er tilgjengelig mandag til fredag.',
            'The service desk is available Monday through Friday.',
        ));
    }

    public function test_quarterly_and_hvert_kvartal_are_not_a_conflict(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Systemeier gjennomgår tilgangsrettighetene hvert kvartal.',
            'Access rights are reviewed quarterly by the system owner.',
        ));
    }

    public function test_cross_language_paraphrase_of_the_same_clause_is_not_a_conflict(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Kritiske hendelser skal besvares innen 30 minutter.',
            'Critical incidents shall be responded to within 30 minutes.',
        ));
    }

    public function test_changed_number_is_a_conflict(): void
    {
        $this->assertSame('number_mismatch', $this->service()->detectDeterministicConflict(
            'Responstiden er 15 minutter.',
            'Response time is 30 minutes.',
        ));
    }

    public function test_permissive_source_upgraded_to_obligatory_claim_is_a_conflict(): void
    {
        $this->assertSame('modality_mismatch', $this->service()->detectDeterministicConflict(
            'Kunden skal ha en vaktordning.',
            'The customer may establish an on-call arrangement.',
        ));
    }

    public function test_recommendation_upgraded_to_a_current_state_claim_is_a_conflict(): void
    {
        $this->assertSame('modality_mismatch', $this->service()->detectDeterministicConflict(
            'Kunden har en selvbetjeningsportal.',
            'A self-service portal is recommended.',
        ));
    }

    public function test_supplier_swapped_for_customer_is_a_conflict(): void
    {
        $this->assertSame('actor_mismatch', $this->service()->detectDeterministicConflict(
            'Kunden gjennomgår tilgangsrettighetene.',
            'The supplier reviews the access rights.',
        ));
    }

    public function test_negation_dropped_is_a_conflict(): void
    {
        $this->assertSame('negation_mismatch', $this->service()->detectDeterministicConflict(
            'Tjenesten er tilgjengelig utenfor arbeidstid.',
            'The service is not available outside business hours.',
        ));
    }

    public function test_critical_cases_widened_to_all_cases_is_a_conflict(): void
    {
        $this->assertSame('scope_mismatch', $this->service()->detectDeterministicConflict(
            'Alle saker håndteres av den døgnbemannede vaktorganisasjonen.',
            'Critical incidents are handled by the on-call manager.',
        ));
    }

    public function test_business_days_widened_to_every_day_is_a_conflict(): void
    {
        $this->assertSame('scope_mismatch', $this->service()->detectDeterministicConflict(
            'Servicedesk er tilgjengelig alle dager.',
            'The service desk is available Monday through Friday.',
        ));
    }

    public function test_hyphenated_non_critical_compound_is_not_treated_as_negation(): void
    {
        // "ikke-kritiske" ("non-critical") is an ordinary adjective, not a negation of anything —
        // a real production false-positive found while re-evaluating run 37.
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Det skal gis statusoppdatering ved hver statusendring for ikke-kritiske hendelser.',
            'Status updates are provided on every status change for non-critical incidents.',
        ));
    }

    public function test_topic_similarity_alone_is_not_flagged_as_a_conflict(): void
    {
        // detectDeterministicConflict() only ever flags a genuine, specific mismatch — it is not
        // itself a substitute for a real semantic support decision (that is the AI verifier's
        // job); two texts that merely share a topic with no numbers/actors/modality to compare
        // must not be blocked by this deterministic net.
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Servicedesk håndterer henvendelser og hendelser.',
            'The service desk manages requests and incidents.',
        ));
    }

    public function test_empty_supporting_text_is_never_a_conflict(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict('Responstiden er 30 minutter.', ''));
    }
}
