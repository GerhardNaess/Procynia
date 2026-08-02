<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionCoverageValidator;
use Tests\TestCase;

/**
 * Wiki run-586: a concept page ("Samhandlings- og styringsmodell") was generated with its two
 * planned `## ` headings present but with zero body text under either — nothing in the schema,
 * parser, or QA noticed. These tests cover EnterpriseWikiPlannedSectionCoverageValidator, the
 * deterministic check that catches exactly this.
 */
class EnterpriseWikiPlannedSectionCoverageValidatorTest extends TestCase
{
    private function validator(): EnterpriseWikiPlannedSectionCoverageValidator
    {
        return new EnterpriseWikiPlannedSectionCoverageValidator;
    }

    // =========================================================================
    // 1: Two planned sections, both with substance — passes
    // =========================================================================

    public function test_two_planned_sections_with_substance_pass(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt om modellen.

            ## Roller i styringsmodellen

            Delivery Executive har overordnet ansvar for leveransen og kontraktsoppfølging.

            ## Møtefora og beslutningsflyt

            Strategisk forum møtes årlig og behandler overordnede prioriteringer.
            MD;

        $issues = $this->validator()->validate(
            ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
            $markdown,
            'concept',
        );

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // 2/3: One / two empty planned sections — rejected (the run-586 shape)
    // =========================================================================

    public function test_one_empty_planned_section_is_rejected(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            Delivery Executive har overordnet ansvar for leveransen.

            ## Møtefora og beslutningsflyt
            MD;

        $issues = $this->validator()->validate(
            ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
            $markdown,
            'concept',
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_EMPTY, $issues[0]['type']);
        $this->assertSame('Møtefora og beslutningsflyt', $issues[0]['planned_topic']);
        $this->assertTrue(EnterpriseWikiPlannedSectionCoverageValidator::isBlocking($issues[0]));
    }

    public function test_two_empty_planned_sections_are_rejected_run_586_shape(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Samhandlings- og styringsmodellen beskriver hvordan leverandør og kunde styrer leveransen.

            ## Roller i styringsmodellen

            ## Møtefora og beslutningsflyt
            MD;

        $issues = $this->validator()->validate(
            ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
            $markdown,
            'concept',
        );

        $this->assertCount(2, $issues);
        foreach ($issues as $issue) {
            $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_EMPTY, $issue['type']);
            $this->assertTrue(EnterpriseWikiPlannedSectionCoverageValidator::isBlocking($issue));
        }
    }

    // =========================================================================
    // 4: Missing planned heading entirely (with source grounding) — rejected
    // =========================================================================

    public function test_missing_planned_heading_with_source_grounding_is_rejected(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            Delivery Executive har overordnet ansvar for leveransen.
            MD;

        $sourceText = 'Møtefora og beslutningsflyt er sentralt for styringsmodellen, med strategisk forum, taktisk forum og operativt forum.';

        $issues = $this->validator()->validate(
            ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
            $markdown,
            'concept',
            $sourceText,
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_MISSING, $issues[0]['type']);
        $this->assertNull($issues[0]['heading']);
        $this->assertTrue($issues[0]['source_grounded']);
        $this->assertTrue(EnterpriseWikiPlannedSectionCoverageValidator::isBlocking($issues[0]));
    }

    // =========================================================================
    // 5: Section with only a wikilink — rejected
    // =========================================================================

    public function test_section_with_only_a_wikilink_is_rejected(): void
    {
        $markdown = <<<'MD'
            # Applikasjonsdrift

            Innledende avsnitt om applikasjonsdrift.

            ## Møtefora og beslutningsflyt

            [[samhandlings-og-styringsmodell]]
            MD;

        $issues = $this->validator()->validate(['Møtefora og beslutningsflyt'], $markdown, 'concept');

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_ONLY_LINKS, $issues[0]['type']);
        $this->assertTrue(EnterpriseWikiPlannedSectionCoverageValidator::isBlocking($issues[0]));
    }

    // =========================================================================
    // 6: Section with a list or table and real substance — accepted
    // =========================================================================

    public function test_section_with_a_table_and_real_substance_is_accepted(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            | Rolle | Ansvar |
            | --- | --- |
            | Delivery Executive | Overordnet ansvar for leveransen og kontraktsoppfølging |
            | Security Manager | Rådgivning om sikkerhet og risiko |
            MD;

        $issues = $this->validator()->validate(['Roller i styringsmodellen'], $markdown, 'concept');

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // 7: Normalized heading variant (parenthetical suffix stripped) — accepted
    // =========================================================================

    public function test_normalized_heading_variant_is_accepted(): void
    {
        $markdown = <<<'MD'
            # Masterdata Samhandling

            Innledende avsnitt.

            ## Samhandlings- og styringsmodell for applikasjonsdrift

            Leveransen styres gjennom en helhetlig modell med tre nivåer.
            MD;

        $issues = $this->validator()->validate(
            ['Samhandlings- og styringsmodell for applikasjonsdrift (strategisk, taktisk og operativt nivå)'],
            $markdown,
            'article',
        );

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // 8: Unplanned, genuinely empty heading does not affect this rule
    // =========================================================================

    public function test_unplanned_empty_heading_does_not_affect_this_rule(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            Delivery Executive har overordnet ansvar.

            ## En helt uplanlagt overskrift
            MD;

        $issues = $this->validator()->validate(['Roller i styringsmodellen'], $markdown, 'concept');

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // 14: Pages without planned sections are unaffected
    // =========================================================================

    public function test_page_without_planned_sections_is_unaffected(): void
    {
        $issues = $this->validator()->validate([], '# Tittel

Bare et avsnitt, ingen planlagte seksjoner.', 'concept');

        $this->assertSame([], $issues);
    }

    public function test_summary_page_type_is_never_checked(): void
    {
        $markdown = "# Sammendrag\n\nEt kort sammendrag uten overskrifter.";

        $issues = $this->validator()->validate(['Noe planlagt tema'], $markdown, 'summary');

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // 15: A source section late in a long document is still found (heading match
    // is independent of position/length of the source text)
    // =========================================================================

    public function test_late_source_section_is_still_matched_regardless_of_document_length(): void
    {
        $longPrefix = str_repeat('Fyllstoff som ikke er relevant for planlagte seksjoner. ', 500);
        $sourceText = $longPrefix.' Møtefora og beslutningsflyt beskrives helt til slutt i dokumentet.';

        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            Delivery Executive har overordnet ansvar.
            MD;

        $issues = $this->validator()->validate(
            ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
            $markdown,
            'concept',
            $sourceText,
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_MISSING, $issues[0]['type']);
        $this->assertTrue($issues[0]['source_grounded'], 'A late source section must still be detected regardless of source text length.');
    }

    // =========================================================================
    // 16: Planned section without any source grounding — a signal, not a defect
    // =========================================================================

    public function test_planned_section_without_source_grounding_is_a_non_blocking_signal(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            Delivery Executive har overordnet ansvar.
            MD;

        // "Budsjettoppfølging" never appears anywhere in the source text.
        $sourceText = 'Dette dokumentet handler kun om roller og ansvar i leveransen, ingen andre tema.';

        $issues = $this->validator()->validate(
            ['Roller i styringsmodellen', 'Budsjettoppfølging'],
            $markdown,
            'concept',
            $sourceText,
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_MISSING, $issues[0]['type']);
        $this->assertSame('Budsjettoppfølging', $issues[0]['planned_topic']);
        $this->assertFalse($issues[0]['source_grounded']);
        $this->assertFalse(
            EnterpriseWikiPlannedSectionCoverageValidator::isBlocking($issues[0]),
            'A planned section with no detectable source grounding must be a signal, not a blocking defect — a thin source document legitimately does not support every planned section.',
        );
    }

    // =========================================================================
    // Secondary signal: below-minimum-substance
    // =========================================================================

    public function test_section_below_minimum_substance_is_flagged(): void
    {
        $markdown = <<<'MD'
            # Samhandlings- og styringsmodell

            Innledende avsnitt.

            ## Roller i styringsmodellen

            Se ITIL.
            MD;

        $issues = $this->validator()->validate(['Roller i styringsmodellen'], $markdown, 'concept');

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_BELOW_MINIMUM_SUBSTANCE, $issues[0]['type']);
        $this->assertTrue(EnterpriseWikiPlannedSectionCoverageValidator::isBlocking($issues[0]));
    }

    public function test_article_page_type_is_also_checked(): void
    {
        $markdown = <<<'MD'
            # Masterdata Samhandling

            Innledende avsnitt.

            ## Roller og ansvar i leveransen
            MD;

        $issues = $this->validator()->validate(['Roller og ansvar i leveransen'], $markdown, 'article');

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_EMPTY, $issues[0]['type']);
    }

    public function test_entity_page_type_is_also_checked(): void
    {
        $markdown = <<<'MD'
            # Advania

            Innledende avsnitt.

            ## Rolle i leveransen
            MD;

        $issues = $this->validator()->validate(['Rolle i leveransen'], $markdown, 'entity');

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedSectionCoverageValidator::TYPE_EMPTY, $issues[0]['type']);
    }
}
