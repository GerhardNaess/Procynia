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

    public function test_contrastive_ikke_men_construction_is_not_treated_as_negation(): void
    {
        // Run-38 fix: "ikke som en teoretisk modell, men som et styringsverktøy" asserts the "men"
        // (but) clause — it does not negate anything the claim needs to also state. This became a
        // real false-positive once claims could combine evidence from several excerpts: this
        // exact sentence was cited only for its unrelated "daglig arbeidet" phrase, and the
        // incidental "ikke ... men" elsewhere in the same excerpt wrongly blocked run-38 claim
        // 3777, which never negates anything itself.
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Leverandøren legger ITIL-rammeverk til grunn for styring, utvikling og daglig gjennomføring av IT-tjenester.',
            'Leverandøren bruker derfor ITIL ikke som en teoretisk modell, men som et styringsverktøy i det daglige arbeidet.',
        ));
    }

    public function test_contrastive_not_but_construction_is_not_treated_as_negation(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'The supplier uses ITIL as a governance tool in daily operations.',
            'The supplier uses ITIL not as a theoretical model, but as a governance tool in daily operations.',
        ));
    }

    public function test_plain_negation_inside_a_longer_combined_excerpt_is_still_a_conflict(): void
    {
        // The contrastive "ikke ... men" exclusion above must never swallow a genuine, standalone
        // negation elsewhere in the same combined text — fix #3's requirement that actor/negation/
        // modality/number/scope control stays strict.
        $this->assertSame('negation_mismatch', $this->service()->detectDeterministicConflict(
            'Tjenesten er tilgjengelig utenfor arbeidstid.',
            'Leverandøren bruker ITIL ikke som en teoretisk modell, men som et styringsverktøy. Tjenesten er ikke tilgjengelig utenfor arbeidstid.',
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

    // =========================================================================
    // detectDeterministicSupport() — run-38 fix: verbatim/near-verbatim claims are
    // deterministically supported before any AI call. Cases below are the ten
    // representative claims analyzed from run 38 (Masterdata ITIL, customer 4).
    // =========================================================================

    public function test_empty_candidate_list_is_never_deterministically_supported(): void
    {
        // Run-38 claims 3919 ("Kontinuerlig forbedring") and 3939 ("Endringsledelse") had zero
        // EnterpriseWikiSourceReference rows at all — nothing to combine, so this must always
        // fall through to full AI verification (which correctly still finds them unsupported).
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Prinsippet om kontinuerlig forbedring ligger til grunn for utvikling av prosessene gjennom hele avtaleperioden.',
            [],
        ));
    }

    public function test_exact_verbatim_claim_across_combined_excerpts_is_deterministically_supported(): void
    {
        // Run-38 claim 4037 ("Sammendrag: Masterdata ITIL") is word-for-word identical to
        // paragraph-15 among its six cited candidate excerpts — the clearest possible case that
        // must never need an AI call to confirm.
        $claim = 'Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering, prioritering og oppfølging av hendelser og forespørsler.';

        $this->assertTrue($this->service()->detectDeterministicSupport($claim, [
            'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
            'Leverandøren benytter ITIL-praksiser som grunnlag for hvordan tjenesteleveransen gjennomføres i det daglige, for eksempel innenfor hendelseshåndtering, requests, problemhåndtering og endringsstyring.',
            $claim,
            'Hendelser som gjentar seg eller har større konsekvenser analyseres videre gjennom problemhåndtering, hvor rotårsak identifiseres og tiltak iverksettes for å redusere risiko for nye driftsavbrudd.',
            'Endringer i applikasjoner, infrastruktur og plattformer håndteres gjennom en kontrollert prosess hvor risiko, påvirkning og gjennomførbarhet vurderes før beslutning tas.',
            'På den måten sikrer Leverandøren at IT-tjenestene håndteres med nødvendig presisjon og kontroll.',
        ]));
    }

    public function test_paraphrase_synthesized_from_two_excerpts_is_not_deterministically_supported(): void
    {
        // Run-38 claim 3777 ("Masterdata ITIL") paraphrases and combines paragraph-8 and
        // paragraph-9 rather than repeating either verbatim — a real, legitimate synthesis this
        // fix leaves to full AI judgment (with the updated prompt), not to a deterministic
        // substring shortcut.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Leverandøren legger ITIL-rammeverk til grunn for styring, utvikling og daglig gjennomføring av IT-tjenester.',
            [
                'Leverandøren legger ITIL til grunn for styring og videreutvikling av IT-tjenestene, og bruker rammeverket aktivt for å sikre kontroll, forutsigbarhet og etterprøvbarhet i leveransen.',
                'I en virksomhet hvor IT-tjenestene understøtter kritiske funksjoner og er underlagt krav til sikkerhet, dokumentasjon og tilgjengelighet, er det avgjørende at prosessene fungerer i praksis. Leverandøren bruker derfor ITIL ikke som en teoretisk modell, men som et styringsverktøy i det daglige arbeidet.',
            ],
        ));
    }

    public function test_reworded_restatement_of_a_single_excerpt_is_not_deterministically_supported(): void
    {
        // Run-38 claim 3814 ("ITIL-rammeverk") reuses paragraph-8's exact phrase "kontroll,
        // forutsigbarhet og etterprøvbarhet" but reframes it as a definition sentence — near-
        // verbatim in spirit, but not a contiguous substring, so it must still go through AI
        // rather than being silently auto-approved by a loose fuzzy match.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'ITIL-rammeverk er et styrings- og forbedringsrammeverk som legges til grunn for styring og videreutvikling av IT-tjenester, med vekt på kontroll, forutsigbarhet og etterprøvbarhet.',
            [
                'Leverandøren legger ITIL til grunn for styring og videreutvikling av IT-tjenestene, og bruker rammeverket aktivt for å sikre kontroll, forutsigbarhet og etterprøvbarhet i leveransen.',
            ],
        ));
    }

    public function test_reinforced_claim_is_not_deterministically_supported(): void
    {
        // Run-38 claim 3858 ("Problemhåndtering") adds the unsupported emphasis "sentral" — not a
        // substring of its source excerpts regardless, so this stays correctly false and reaches
        // full AI/deterministic-conflict judgment instead.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Problemhåndtering er en sentral praksis i leveransen basert på ITIL-rammeverk.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Hendelser som gjentar seg eller har større konsekvenser analyseres videre gjennom problemhåndtering, hvor rotårsak identifiseres og tiltak iverksettes for å redusere risiko for nye driftsavbrudd.',
            ],
        ));
    }

    public function test_near_verbatim_list_restatement_with_added_reference_is_not_deterministically_supported(): void
    {
        // Run-38 claim 3875 ("Endringsstyring") restates paragraph-11's list of practices almost
        // verbatim but restructures the sentence and adds a page reference — deliberately not
        // matched deterministically (it is not a contiguous substring), left to AI instead.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Endringsstyring inngår som del av et felles fundament sammen med Hendelseshåndtering, Forespørselshåndtering, Problemhåndtering og Kunnskapsforvaltning i leveransen beskrevet i Masterdata ITIL.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Endringer i applikasjoner, infrastruktur og plattformer håndteres gjennom en kontrollert prosess hvor risiko, påvirkning og gjennomførbarhet vurderes før beslutning tas.',
            ],
        ));
    }

    public function test_list_membership_restatement_is_not_deterministically_supported(): void
    {
        // Run-38 claim 3885 ("Kunnskapsforvaltning") — same paragraph-11 list, different
        // sentence structure; not a substring, correctly left to AI.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Kunnskapsforvaltning er en av ITIL-praksisene som brukes som felles fundament i samhandlingen mellom kunde og leverandør.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Leverandøren legger ITIL til grunn for styring og videreutvikling av IT-tjenestene, og bruker rammeverket aktivt for å sikre kontroll, forutsigbarhet og etterprøvbarhet i leveransen.',
            ],
        ));
    }

    public function test_misattributed_claim_with_high_word_overlap_is_not_deterministically_supported(): void
    {
        // Run-38 claim 4048 ("Hendelseshåndtering") is the critical regression case: it scores
        // ~90% raw token overlap against its combined candidate excerpts (bag-of-words would
        // wrongly approve it), yet the described function — "forutsigbar registrering,
        // prioritering og oppfølging" — is actually stated in the source about "Brukerstøtte",
        // not "Hendelseshåndtering". A strict contiguous-substring check correctly finds no
        // match and leaves this misattribution to full AI/human judgment instead of silently
        // approving it.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Hendelseshåndtering bidrar til forutsigbar registrering, prioritering og oppfølging av saker som påvirker Kundens IT-tjenester.',
            [
                'I en virksomhet hvor IT-tjenestene understøtter kritiske funksjoner og er underlagt krav til sikkerhet, dokumentasjon og tilgjengelighet, er det avgjørende at prosessene fungerer i praksis. Leverandøren bruker derfor ITIL ikke som en teoretisk modell, men som et styringsverktøy i det daglige arbeidet.',
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Leverandøren benytter ITIL-praksiser som grunnlag for hvordan tjenesteleveransen gjennomføres i det daglige, for eksempel innenfor hendelseshåndtering, requests, problemhåndtering og endringsstyring.',
                'Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering, prioritering og oppfølging av hendelser og forespørsler.',
            ],
        ));
    }

    public function test_terminology_mismatch_synthesis_is_not_deterministically_supported(): void
    {
        // Run-38 claim 4062 ("Forespørselshåndtering") combines an English "requests" mention
        // with the Norwegian list term — real synthesis across excerpts and languages, correctly
        // left to AI rather than a substring shortcut.
        $this->assertFalse($this->service()->detectDeterministicSupport(
            'Forespørselshåndtering er en ITIL-praksis Leverandøren benytter i den daglige tjenesteleveransen, sammen med blant annet hendelseshåndtering, problemhåndtering og endringsstyring.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Leverandøren benytter ITIL-praksiser som grunnlag for hvordan tjenesteleveransen gjennomføres i det daglige, for eksempel innenfor hendelseshåndtering, requests, problemhåndtering og endringsstyring.',
            ],
        ));
    }

    public function test_deterministic_support_ignores_blank_candidate_excerpts(): void
    {
        $this->assertFalse($this->service()->detectDeterministicSupport('Responstiden er 30 minutter.', ['', '   ']));
    }

    public function test_deterministic_support_is_false_for_blank_claim_text(): void
    {
        $this->assertFalse($this->service()->detectDeterministicSupport('', ['Responstiden er 30 minutter.']));
    }

    // =========================================================================
    // filterToRelevantSentences() — run-38 fix: combining several full paragraphs must not let
    // an unrelated sentence's incidental negation/modality/scope marker leak into the
    // deterministic conflict check.
    // =========================================================================

    public function test_unrelated_trailing_sentence_with_incidental_negation_is_dropped(): void
    {
        // Real run-38 excerpt (paragraph-11): its first sentence is about the claim's topic
        // (ITIL practices), its second sentence is an unrelated remark about change management
        // that happens to contain "uten" ("without") — that "uten" must not count as evidence
        // the source negates something the claim asserts.
        $claim = 'Leverandøren legger ITIL-rammeverk til grunn for styring, utvikling og daglig gjennomføring av IT-tjenester.';
        $excerpt = 'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren. '
            .'Gjennom dette sikres en drift hvor avvik håndteres raskt, årsaker følges opp systematisk og endringer gjennomføres uten å sette stabilitet eller etterlevelse i fare.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertStringContainsString('felles fundament', $filtered);
        $this->assertStringNotContainsString('uten å sette', $filtered);
    }

    public function test_full_claim_conflict_check_no_longer_false_positives_on_a_combined_unrelated_sentence(): void
    {
        // End-to-end regression for run-38 claim 3777: combining paragraph-8, paragraph-9, and
        // paragraph-11 (as the AI legitimately does to synthesize this claim) must not fail
        // deterministic conflict just because paragraph-11 has an unrelated trailing sentence
        // with its own "uten" ("without").
        $claim = 'Leverandøren legger ITIL-rammeverk til grunn for styring, utvikling og daglig gjennomføring av IT-tjenester.';
        $excerpt = 'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren. '
            .'Gjennom dette sikres en drift hvor avvik håndteres raskt, årsaker følges opp systematisk og endringer gjennomføres uten å sette stabilitet eller etterlevelse i fare.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertNull($this->service()->detectDeterministicConflict($claim, $filtered));
    }

    public function test_relevant_sentence_is_kept_when_it_shares_a_claim_token(): void
    {
        $claim = 'Responstiden er 30 minutter.';
        $excerpt = 'Dette avsnittet handler om noe helt annet. Responstiden for kritiske hendelser er 30 minutter.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertStringContainsString('Responstiden for kritiske hendelser er 30 minutter.', $filtered);
        $this->assertStringNotContainsString('helt annet', $filtered);
    }

    public function test_returns_empty_string_when_no_clause_shares_a_token(): void
    {
        // Run-38 fix (second pass): falling back to the raw excerpt here reintroduced exactly the
        // risk-marker clauses this method exists to exclude (verified against real run-38 data,
        // claim 3780's paragraph-15) — an excerpt with zero relevant clauses now contributes
        // nothing rather than being kept in full.
        $claim = 'Responstiden er 30 minutter.';
        $excerpt = 'Dette avsnittet handler om noe helt annet tema.';

        $this->assertSame('', $this->service()->filterToRelevantSentences($claim, $excerpt));
    }

    public function test_clause_with_uten_sharing_only_a_generic_noun_is_dropped(): void
    {
        // Real run-38 excerpt (claim 3783's paragraph-11): the risk-marker clause shares only the
        // generic, ubiquitous noun "endringer" with the claim — not specific enough to trust its
        // "uten" as relevant to a claim about a different topic entirely.
        $claim = 'Endringer vurderes for risiko, påvirkning og gjennomførbarhet før beslutning tas.';
        $excerpt = 'Dette sikrer forutsigbar håndtering av henvendelser, kontroll på endringer og god sporbarhet, '
            .'og endringer gjennomføres uten å sette stabilitet eller etterlevelse i fare.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertStringNotContainsString('uten å sette', $filtered);
    }

    public function test_ikke_kun_scope_qualifier_is_not_treated_as_negation(): void
    {
        // Real run-38 excerpt cited by several claims (3788, 3789, 3790, 4041, 4059, 3881, 3943):
        // "ikke kun forståelse" ("not just understanding") is a scope qualifier — it affirms the
        // practical-application half, it does not negate anything the claim would need to also
        // state.
        $claim = 'Innføring av prosesser gjennomføres kontrollert og med tydelig oppfølging av etterlevelse.';
        $excerpt = 'Innføring av prosessene gjennomføres kontrollert og med tydelig oppfølging av etterlevelse. '
            .'Opplæring og oppfølging rettes mot praktisk anvendelse, ikke kun forståelse av prinsipper.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertNull($this->service()->detectDeterministicConflict($claim, $filtered));
    }

    public function test_ikke_bare_scope_qualifier_is_not_treated_as_negation(): void
    {
        // Real run-38 excerpt (claim 3880's paragraph-29): "ikke bare beskriver" ("not just
        // describes") is the same scope-qualifier pattern as "ikke kun", not a negation.
        $claim = 'Endringsstyring etableres og innføres som et målrettet forbedringsløp som tar utgangspunkt i faktisk praksis.';
        $excerpt = 'På denne bakgrunn etableres forbedrede arbeidsprosesser for de prioriterte områdene, '
            .'og ikke bare beskriver ønsket praksis.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertNull($this->service()->detectDeterministicConflict($claim, $filtered));
    }

    // =========================================================================
    // detectSubjectMismatch() — run-38 fix: a deterministic backstop against
    // misattribution when the AI's own subject_entity self-report is not reliable.
    // =========================================================================

    public function test_real_run_38_misattribution_case_is_flagged(): void
    {
        // Claim 4048: "Hendelseshåndtering" is only named (as one item in a list) in the first
        // excerpt; the second excerpt describes the specific claimed function in detail for
        // "Brukerstøtte" instead, never mentioning "hendelseshåndtering" at all.
        $this->assertTrue($this->service()->detectSubjectMismatch(
            'Hendelseshåndtering bidrar til forutsigbar registrering, prioritering og oppfølging av saker som påvirker Kundens IT-tjenester.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering, prioritering og oppfølging av hendelser og forespørsler. '
                .'Saker som krever dypere faglig vurdering løftes videre til relevante miljøer innen drift og applikasjonsforvaltning, slik at feil rettes raskt og med riktig kompetanse. '
                .'Dette er avgjørende for en virksomhet hvor tilgang til fagdata og analyseverktøy må opprettholdes uten avbrudd.',
            ],
        ));
    }

    public function test_same_subject_combination_is_not_flagged(): void
    {
        // Claim 4022 ("Brukerstøtte fungerer som inngang...") genuinely is about the same subject
        // ("brukerstøtte") that both cited excerpts name — legitimate combination, not attribution.
        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering og prioritering av hendelser og forespørsler.',
            [
                'Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering, prioritering og oppfølging av hendelser og forespørsler.',
                'Saker som krever dypere faglig vurdering hos Brukerstøtte løftes videre til relevante miljøer innen drift og applikasjonsforvaltning.',
            ],
        ));
    }

    public function test_single_cited_excerpt_is_never_flagged(): void
    {
        // Misattribution requires combining excerpts about different subjects — a single-excerpt
        // claim was never affected by this failure mode and must not be newly blocked by it.
        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Hendelseshåndtering bidrar til forutsigbar registrering, prioritering og oppfølging av saker.',
            ['Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering, prioritering og oppfølging av hendelser og forespørsler.'],
        ));
    }

    public function test_low_token_overlap_excerpt_missing_subject_is_not_flagged(): void
    {
        // The claim's subject is absent from this excerpt, but it barely overlaps with the
        // claim's other content at all — ordinary complementary evidence about a related but
        // distinct point, not a wholesale function transplant.
        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Hendelseshåndtering bidrar til forutsigbar registrering, prioritering og oppfølging av saker som påvirker Kundens IT-tjenester.',
            [
                'ITIL-praksiser som hendelseshåndtering brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Endringer i applikasjoner og infrastruktur håndteres gjennom en kontrollert prosess hvor risiko vurderes.',
            ],
        ));
    }

    public function test_empty_claim_tokens_are_never_flagged(): void
    {
        $this->assertFalse($this->service()->detectSubjectMismatch('', ['Tekst en.', 'Tekst to.']));
    }

    public function test_subject_named_only_by_a_low_coverage_candidate_is_not_flagged(): void
    {
        // Run-38 fix (second pass): a mismatch requires that NO relevant candidate contains the
        // subject — not that some candidate happens to lack it. Real run-38 claim 4062: paragraph-
        // 11 names the claim's subject "forespørselshåndtering" directly; a different, more
        // generically-worded excerpt that also reaches the coverage bar must not cancel that out.
        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Forespørselshåndtering er en ITIL-praksis Leverandøren benytter i den daglige tjenesteleveransen, sammen med blant annet hendelseshåndtering, problemhåndtering og endringsstyring.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Leverandøren benytter ITIL-praksiser som grunnlag for hvordan tjenesteleveransen gjennomføres i det daglige, for eksempel innenfor hendelseshåndtering, problemhåndtering og endringsstyring.',
            ],
        ));
    }

    public function test_leading_generic_party_noun_is_never_treated_as_the_subject(): void
    {
        // Requirement: "leverandøren" and "innføringen" must never serve as "the entity" — a claim
        // leading with either has no specific, named ITIL practice for this check to verify.
        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Leverandøren etablerer forbedrede arbeidsprosesser med tydelige krav til hvordan saker registreres, vurderes, besluttes og følges opp.',
            [
                'Leverandøren videreutvikler prosessene der dagens arbeidsmåter ikke gir tilstrekkelig styring eller oversikt.',
                'På denne bakgrunn etableres forbedrede arbeidsprosesser for de prioriterte områdene, med tydelige krav til hvordan saker registreres, vurderes, besluttes og følges opp.',
            ],
        ));

        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Innføringen skjer kontrollert med vekt på praktisk anvendelse og etterlevelse.',
            [
                'Innføring av prosessene gjennomføres kontrollert og med tydelig oppfølging av etterlevelse.',
                'Roller og ansvar avklares samtidig, med klare forventninger til hvem som beslutter.',
            ],
        ));
    }

    public function test_claim_without_a_known_practice_name_is_never_flagged(): void
    {
        // detectSubjectMismatch() only has a well-defined job when the claim names one of the
        // known ITIL practices; a claim that names none of them has no specific subject to check.
        $this->assertFalse($this->service()->detectSubjectMismatch(
            'Kontinuerlig forbedring gir et faktabasert grunnlag for å videreutvikle prosesser og arbeidsmåter.',
            [
                'ITIL-praksiser brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Erfaringer fra drift gir et faktabasert grunnlag for å identifisere forbedringsområder.',
            ],
        ));
    }

    public function test_run_38_claim_4048_misattribution_still_rejected_after_the_fix(): void
    {
        // Requirement: claim 4048 must remain rejected under the revised detectSubjectMismatch().
        // "Hendelseshåndtering" is only named (as one item in a list) in the first excerpt; the
        // second excerpt describes the specific claimed function in detail for "Brukerstøtte"
        // instead, and reaches the coverage bar without ever mentioning "hendelseshåndtering".
        $this->assertTrue($this->service()->detectSubjectMismatch(
            'Hendelseshåndtering bidrar til forutsigbar registrering, prioritering og oppfølging av saker som påvirker Kundens IT-tjenester.',
            [
                'ITIL-praksiser som hendelseshåndtering, forespørselshåndtering, problemhåndtering, endringsstyring og kunnskapsforvaltning brukes som et felles fundament for samhandlingen mellom Kunden og Leverandøren.',
                'Brukerstøtte fungerer som inngang til tjenestene og håndterer registrering, prioritering og oppfølging av hendelser og forespørsler. '
                .'Dette sikrer forutsigbar håndtering av henvendelser og god sporbarhet i alle aktiviteter som påvirker Kundens IT-tjenester.',
            ],
        ));
    }

    /**
     * Run-39 fix: this document repeatedly pairs a negated "current gap" clause with an
     * un-negated "improvement" clause naming the same ITIL domain noun ("styring") — sharing
     * that one noun with the claim used to be enough for clauseIsRelevant() to keep the negated
     * clause, so hasNegationMarker() then found a bare "ikke" the claim itself never had.
     * Real production case: claim 4112, citing paragraph-20 and paragraph-22, was wrongly
     * downgraded to not_supported via deterministic_reason=negation_mismatch.
     */
    public function test_run_39_claim_4112_negated_gap_clause_no_longer_manufactures_a_conflict(): void
    {
        $claim = 'Slik styrkes styring, prioritering og evne til å identifisere og følge opp underliggende '
            .'årsaker til feil, også under belastning og ved endringer.';

        $paragraph20 = 'Leverandøren legger ITIL til grunn for hvordan prosessene utformes og videreutvikles, '
            .'og tar et tydelig ansvar for å styrke kvaliteten i hvordan IT-tjenestene styres og følges opp. '
            .'For Kunden innebærer dette arbeidsformer som gir bedre kontroll på drift, tydeligere prioriteringer '
            .'og mer forutsigbar gjennomføring av endringer.';

        $paragraph22 = 'Leverandøren videreutvikler prosessene der dagens arbeidsmåter ikke gir tilstrekkelig '
            .'styring eller oversikt. Dette omfatter blant annet tydeligere prioritering av hendelser, mer '
            .'konsekvent håndtering av endringer og bedre grunnlag for å identifisere og følge opp underliggende '
            .'årsaker til feil. Målet er å redusere operasjonell risiko og sikre en drift som er stabil også '
            .'under belastning og ved endringer.';

        $combined = trim(
            $this->service()->filterToRelevantSentences($claim, $paragraph20)
            .' '
            .$this->service()->filterToRelevantSentences($claim, $paragraph22)
        );

        $this->assertStringNotContainsString('ikke gir tilstrekkelig', $combined);
        $this->assertNull($this->service()->detectDeterministicConflict($claim, $combined));
    }

    /**
     * Run-39 fix: same pattern as claim 4112, but the shared/weak-overlap tokens are "dagens"
     * across several negated clauses within a 7-paragraph citation (paragraphs 26-32). Real
     * production case: claim 4111 was wrongly downgraded via deterministic_reason=negation_mismatch.
     */
    public function test_run_39_claim_4111_negated_gap_clauses_across_multiple_excerpts_no_longer_manufacture_a_conflict(): void
    {
        $claim = 'Et målrettet løp omfatter vurdering av dagens praksis, opplæring i prinsipper og roller, '
            .'etablering av tydelige beslutningspunkter og ansvar, samt kontrollert innføring med fokus på '
            .'praktisk anvendelse og forankring ved endringsledelse.';

        $paragraphs = [
            'Etablering og innføring av ITIL-prosesser gjennomføres som et målrettet forbedringsløp med '
                .'tydelig kobling til risiko, driftsevne og krav til etterlevelse. Arbeidet tar utgangspunkt i '
                .'hvordan IT-tjenestene faktisk brukes i dag, og hvor manglende styring eller utydelige '
                .'arbeidsmåter skaper risiko for driftsavbrudd, feil eller svak sporbarhet.',
            'Før etablering av prosessene gjennomfører Leverandøren målrettet opplæring i ITIL-prinsipper, '
                .'roller og arbeidsmåter for relevante miljøer, slik at innføringen bygger på et felles '
                .'utgangspunkt og gir ønsket effekt i praksis.',
            'Innledningsvis vurderer Leverandøren hvordan hendelser, endringer og øvrige driftsaktiviteter '
                .'håndteres i praksis, med særlig vekt på prioritering, beslutningspunkter og samspill mellom '
                .'involverte miljøer. Dette gir et konkret bilde av hvor dagens arbeidsmåter ikke gir '
                .'tilstrekkelig kontroll eller forutsigbarhet.',
            'På denne bakgrunn etableres forbedrede arbeidsprosesser for de prioriterte områdene, med '
                .'tydelige krav til hvordan saker registreres, vurderes, besluttes og følges opp. Prosessene '
                .'utformes slik at de gir reell styring i situasjoner som påvirker tilgjengelighet, sikkerhet '
                .'og etterlevelse, og ikke bare beskriver ønsket praksis.',
            'Roller og ansvar avklares samtidig, med klare forventninger til hvem som beslutter, hvem som '
                .'utfører og hvordan samhandling mellom brukerstøtte, tekniske miljøer og fagressurser skal '
                .'fungere. Dette er avgjørende for å sikre fremdrift i saker og unngå uklarheter i situasjoner '
                .'hvor responstid og kvalitet er kritisk.',
            'Innføring av prosessene gjennomføres kontrollert og med tydelig oppfølging av etterlevelse. '
                .'Leverandøren sikrer at medarbeidere forstår hvordan prosessene skal brukes i konkrete '
                .'arbeidssituasjoner, og at arbeidsmåtene faktisk tas i bruk i det daglige. Opplæring og '
                .'oppfølging rettes mot praktisk anvendelse, ikke kun forståelse av prinsipper.',
            'For områder som innebærer større endringer i arbeidsmåter eller organisering, benyttes '
                .'kompetanse innen endringsledelse for å sikre nødvendig forankring og gjennomføringsevne. '
                .'Målet er å oppnå varig endring i hvordan arbeidet utføres, ikke kun etablere nye '
                .'beskrivelser av prosessene.',
        ];

        $filtered = array_filter(array_map(
            fn (string $paragraph): string => $this->service()->filterToRelevantSentences($claim, $paragraph),
            $paragraphs,
        ));

        $combined = implode(' ', $filtered);

        $this->assertStringNotContainsString('ikke gir tilstrekkelig', $combined);
        $this->assertNull($this->service()->detectDeterministicConflict($claim, $combined));
    }

    /**
     * Guard against over-correction: a negated clause with STRONG (multi-token), specific overlap
     * against a non-negated claim must still be treated as relevant and still flagged as a
     * conflict — the run-39 fix only relaxes the single-generic-noun case.
     */
    public function test_negated_clause_with_strong_specific_overlap_is_still_a_conflict(): void
    {
        $claim = 'Tjenesten er tilgjengelig utenfor ordinær arbeidstid.';
        $excerpt = 'Dette avsnittet handler om noe helt annet tema. '
            .'Tjenesten er ikke tilgjengelig utenfor ordinær arbeidstid.';

        $filtered = $this->service()->filterToRelevantSentences($claim, $excerpt);

        $this->assertStringContainsString('ikke tilgjengelig', $filtered);
        $this->assertSame('negation_mismatch', $this->service()->detectDeterministicConflict($claim, $filtered));
    }
}
