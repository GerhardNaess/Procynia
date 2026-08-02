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
}
