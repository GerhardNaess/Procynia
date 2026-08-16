<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiConceptIdentityMatcher;
use PHPUnit\Framework\TestCase;

class EnterpriseWikiConceptIdentityMatcherTest extends TestCase
{
    public function test_itil_incident_management_variants_are_the_same_identity(): void
    {
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'ITIL Incident Management',
            'Incident Management',
        ));

        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'Incident Management',
            'Incident management (ITIL)',
        ));

        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'ITIL Incident Management',
            'Incident management (ITIL)',
        ));
    }

    public function test_identical_titles_match(): void
    {
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'Incident Management',
            'Incident Management',
        ));
    }

    public function test_case_differences_do_not_prevent_a_match(): void
    {
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'incident management',
            'INCIDENT MANAGEMENT',
        ));
    }

    public function test_different_itil_processes_do_not_match(): void
    {
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'Incident Management',
            'Problem Management',
        ));
    }

    public function test_unrelated_concepts_do_not_match(): void
    {
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'Masterdata Samhandling',
            'Incident Management',
        ));
    }

    /**
     * A lone generic word must not subset-match a specific multi-word title — otherwise
     * "Management" would match "Incident Management", "Problem Management", etc. equally,
     * defeating the point of the conservative subset rule.
     */
    public function test_single_generic_word_does_not_match_a_more_specific_title(): void
    {
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'Management',
            'Incident Management',
        ));
    }

    public function test_more_specific_title_still_matches_a_broader_variant(): void
    {
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::sameIdentity(
            'Incident Management',
            'ITIL Incident Management Process',
        ));
    }

    public function test_empty_titles_never_match(): void
    {
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::sameIdentity('', 'Incident Management'));
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::sameIdentity('Incident Management', ''));
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::sameIdentity('', ''));
    }
    // =========================================================================
    // titleCoversConcept() — the DIRECTED "did this candidate get its page?" question
    // =========================================================================

    /**
     * Run 51: the candidate "Avvikshåndtering" was decided "create" and the decision created
     * "Avvikshåndtering i prosjekter" for it. sameIdentity() says no (a single-token title needs
     * exact equality), so the decision was sent into a repair pass it did not need.
     */
    public function test_a_page_title_that_specialises_the_concept_name_covers_it(): void
    {
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept(
            'Avvikshåndtering',
            'Avvikshåndtering i prosjekter',
        ));
    }

    public function test_an_exact_match_still_covers_the_concept(): void
    {
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept('Testplan', 'Testplan'));
        $this->assertTrue(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept('Incident Management', 'ITIL Incident Management'));
    }

    /**
     * The relaxation is a PREFIX rule, not a subset rule, precisely so the generic-word failure
     * stays out: "Management" does not lead "Change Management", so it does not cover it.
     */
    public function test_a_generic_word_does_not_cover_a_title_that_merely_contains_it(): void
    {
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept('Management', 'Change Management'));
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept('Testplan', 'Systemtest og testplan'));
    }

    public function test_an_unrelated_title_never_covers_the_concept(): void
    {
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept('Migreringsstrategi', 'Kostnadsstyring i prosjekter'));
        $this->assertFalse(EnterpriseWikiConceptIdentityMatcher::titleCoversConcept('', 'Avvikshåndtering i prosjekter'));
    }
}
