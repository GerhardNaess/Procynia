<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use App\Services\EnterpriseWiki\EnterpriseWikiNavigationReferenceDetector;
use PHPUnit\Framework\TestCase;

/**
 * Run 575's finding #5587: "Begreper og rammeverk er omtalt på ITIL Incident Management." was
 * classified as a best-practice suggestion even though it is only a navigation pointer to
 * another Wiki page. isPureNavigationReference() is the deterministic, structural (no AI, no
 * broad keyword list) check that identifies this pattern from the raw excerpt/block markdown,
 * which still carries [[wikilink]] markup.
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

    public function test_run_575_finding_5587_pattern_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Begreper og rammeverk er omtalt på [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_bare_english_reference_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Terms and frameworks are covered on [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_details_found_on_phrasing_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Detaljer finnes på [[incident-management-illustration|Incident Management Illustration]].',
        ));
    }

    public function test_more_information_phrasing_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Mer informasjon finnes i [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_see_also_phrasing_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'Se også siden om [[problem-management|Problem Management]].',
        ));
    }

    /**
     * Real run-575 excerpt (page_excerpt for claim #5587): a single compound sentence with two
     * clauses, joined by a comma, each pointing to a different Wiki page.
     */
    public function test_real_finding_5587_compound_excerpt_is_pure_navigation(): void
    {
        $this->assertTrue($this->detector()->isPureNavigationReference(
            'En samlet fremstilling finnes i [[incident-management-illustration-im-illu-a1b2c|Incident Management Illustration]], '.
            'mens begreper og rammeverk er omtalt på [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_assertion_followed_by_link_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Incident Management skal alltid ha én tydelig sakseier. Se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_assertion_before_link_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Se [[itil-incident-management|ITIL Incident Management]]. Incident Management skal alltid ha én tydelig sakseier.',
        ));
    }

    public function test_assertion_comma_joined_with_link_in_the_same_clause_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Incident Management skal alltid ha én tydelig sakseier, se [[itil-incident-management|ITIL Incident Management]].',
        ));
    }

    public function test_text_without_any_wikilink_is_not_pure_navigation(): void
    {
        $this->assertFalse($this->detector()->isPureNavigationReference(
            'Kunden har etablert døgnbemannet vaktordning.',
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
}
