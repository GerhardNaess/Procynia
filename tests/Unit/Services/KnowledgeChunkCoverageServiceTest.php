<?php

namespace Tests\Unit\Services;

use App\Services\KnowledgeChunkCoverageService;
use PHPUnit\Framework\TestCase;

class KnowledgeChunkCoverageServiceTest extends TestCase
{
    public function test_it_returns_red_when_no_chunks_are_available(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $grounding = $service->evaluateKnowledgeGrounding([], 'Lærling og fagbrev');

        $this->assertSame('red', $grounding['level']);
        $this->assertSame(0.0, $grounding['max_score']);
        $this->assertSame(0, $grounding['sources_count']);
    }

    public function test_it_falls_back_to_score_only_assessment_when_metadata_is_missing(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $grounding = $service->evaluateKnowledgeGrounding([
            ['score' => 0.55],
            ['score' => 0.51],
        ], 'Lærling og fagbrev');

        $this->assertSame('amber', $grounding['level']);
        $this->assertSame(0.55, $grounding['max_score']);
        $this->assertSame(2, $grounding['sources_count']);
    }

    public function test_it_returns_red_when_metadata_does_not_match_requirement_terms(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $grounding = $service->evaluateKnowledgeGrounding([
            [
                'score' => 0.74,
                'topic' => 'SOC',
                'sub_topic' => 'Overvåkning',
                'keywords' => ['hendelseshåndtering', 'beredskap'],
            ],
            [
                'score' => 0.69,
                'topic' => 'IRT',
                'sub_topic' => 'Operasjon',
                'keywords' => ['alarmhåndtering'],
            ],
        ], 'Bruk av lærlinger, læreforhold, fagbrev og IKT-servicefaget.');

        $this->assertSame('red', $grounding['level']);
        $this->assertSame(0.74, $grounding['max_score']);
        $this->assertSame(2, $grounding['sources_count']);
    }

    public function test_it_returns_green_when_metadata_and_scores_support_the_requirement(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $grounding = $service->evaluateKnowledgeGrounding([
            [
                'score' => 0.74,
                'topic' => 'Servicedesk',
                'sub_topic' => 'Lærlingordning',
                'keywords' => ['lærling', 'fagbrev', 'IKT-servicefaget'],
            ],
            [
                'score' => 0.68,
                'topic' => 'Servicedesk',
                'sub_topic' => 'Lærlingordning',
                'keywords' => ['læreforhold', 'lærling'],
            ],
        ], 'Bruk av lærlinger, læreforhold, fagbrev, IKT-servicefaget og Servicedesk/SPOC.');

        $this->assertSame('green', $grounding['level']);
        $this->assertSame(0.74, $grounding['max_score']);
        $this->assertSame(2, $grounding['sources_count']);
    }

    public function test_it_returns_green_when_section_context_and_scores_support_the_requirement(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $grounding = $service->evaluateKnowledgeGrounding([
            [
                'score' => 0.73,
                'section_title' => 'SIEM',
                'section_path' => 'SOC-tjenester > SIEM',
            ],
            [
                'score' => 0.66,
                'section_title' => 'SIEM',
                'section_path' => 'SOC-tjenester > SIEM',
            ],
        ], 'SIEM løsning og overvåkning.');

        $this->assertSame('green', $grounding['level']);
        $this->assertSame(0.73, $grounding['max_score']);
        $this->assertSame(2, $grounding['sources_count']);
    }

    public function test_it_never_returns_green_from_document_context_alone(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $grounding = $service->evaluateKnowledgeGrounding([
            [
                'score' => 0.74,
                'knowledge_item_title' => 'Lærling og fagbrev i praksis',
                'knowledge_item_summary' => 'Dokumentet beskriver lærling og fagbrev i en bred organisasjonskontekst.',
                'content_type' => 'reference',
            ],
            [
                'score' => 0.69,
                'knowledge_item_title' => 'Lærling og fagbrev i praksis',
                'knowledge_item_summary' => 'Dokumentet beskriver lærling og fagbrev i en bred organisasjonskontekst.',
                'content_type' => 'reference',
            ],
        ], 'Bruk av lærlinger, læreforhold, fagbrev og IKT-servicefaget.');

        $this->assertSame('amber', $grounding['level']);
        $this->assertSame(0.74, $grounding['max_score']);
        $this->assertSame(2, $grounding['sources_count']);
    }

    public function test_it_normalizes_keyword_input_deterministically(): void
    {
        $service = new KnowledgeChunkCoverageService();

        $this->assertSame(
            ['lærling', 'fagbrev', 'IKT-servicefaget'],
            $service->normalizeKeywords(' lærling, fagbrev, lærling, IKT-servicefaget, '),
        );
    }
}
