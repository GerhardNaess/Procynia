<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use App\Services\EnterpriseWiki\EnterpriseWikiNavigationReferenceDetector;
use PHPUnit\Framework\TestCase;

/**
 * Finding #5646: "For detaljert flyt og rollebeskrivelser, se Illustrasjon av Incident
 * Management." was classified as a best-practice suggestion even though it is only a navigation
 * pointer to another Wiki page. isPureNavigationReference() is the deterministic, structural (no
 * AI, no broad keyword list) check that identifies this pattern from the raw excerpt/block
 * markdown, which still carries [[wikilink]] markup.
 */
class EnterpriseWikiNavigationReferenceDetectorTest extends TestCase
{
    private function detector(): EnterpriseWikiNavigationReferenceDetector
    {
        return new EnterpriseWikiNavigationReferenceDetector(new EnterpriseWikiLinkParser);
    }

    public function test_bare_norwegian_see_reference_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_run_5646_finding_pattern_with_introductory_clause_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'For detaljert flyt og rollebeskrivelser, se [[incident-management-illustrasjon-3f9a1|Illustrasjon av Incident Management]].',
        ));
    }

    public function test_norwegian_reference_verb_omtalt_pa_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Begreper og rammeverk er omtalt på [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_more_information_phrasing_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Mer informasjon finnes i [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_bare_english_see_for_details_reference_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'See [[itil-incident-management|ITIL Incident Management]] for details.',
        ));
    }

    public function test_assertion_before_a_comma_joined_reference_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Incident Management skal alltid ha én tydelig sakseier, se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_assertion_sentence_followed_by_a_reference_sentence_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Incident Management skal ha tydelig sakseierskap. Se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_assertion_semicolon_joined_with_a_reference_in_the_same_sentence_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Normal tjenesteleveranse skal gjenopprettes så raskt som mulig; se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_english_assertion_sentence_followed_by_a_reference_sentence_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'The process requires clear ownership. See [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_text_without_any_wikilink_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Kunden har etablert døgnbemannet vaktordning.',
        ));
    }

    public function test_heading_line_is_never_pure_navigation_even_with_a_link(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            '# Se [[itil-incident-management|ITIL Incident Management]]',
        ));
    }

    public function test_empty_text_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(''));
    }

    public function test_whitespace_only_text_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference("  \n  "));
    }

    /**
     * A link inside an otherwise substantial paragraph must never make the whole paragraph
     * navigation-only — the sentence carrying the link is short, but a PRECEDING sentence in the
     * same text still carries its own real content.
     */
    public function test_link_inside_a_substantial_paragraph_does_not_make_the_whole_text_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Illustrasjonen tydeliggjør hvordan Kunde og Leverandør samhandler gjennom en hendelsesforløp, '.
            'med vekt på kontaktflater, rolleavklaringer og overleveringer som sikrer kontinuitet i behandlingen. '.
            'Se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }
}
