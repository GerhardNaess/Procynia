<?php

namespace Tests\Unit\Services;

use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\RequirementKnowledgeDocumentRecommendationService;
use PHPUnit\Framework\TestCase;

class RequirementKnowledgeDocumentRecommendationServiceTest extends TestCase
{
    public function test_it_recommends_a_learning_document_from_requirement_text(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Leverandøren skal ha lærling med godkjent læreforhold og fagbrev.',
        ]);

        $recommendation = $service->recommendForRequirement($requirement);

        $this->assertSame('Lærlingeordning og kompetanseutvikling', $recommendation['recommended_document_title']);
        $this->assertSame('laerlingeordning-og-kompetanseutvikling.docx', $recommendation['suggested_filename']);
    }

    public function test_it_recommends_a_language_document_from_the_grounding_judge_context(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Språk i samhandling og dokumentasjon. All kommunikasjon og dokumentasjon skal være på norsk på minimum B2-nivå.',
        ]);

        $recommendation = $service->recommendForRequirement($requirement, [
            'status' => 'unsupported',
            'can_generate_answer' => false,
            'directly_supported_points' => [],
            'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning er dokumentert.'],
            'unsupported_points' => [
                'Språkkrav, norsk dokumentasjon og B2-nivå er ikke dokumentert i kunnskapsgrunnlaget.',
            ],
            'missing_knowledge_summary' => 'Kunnskapsgrunnlaget mangler språkkrav og norsk dokumentasjon.',
            'recommended_document_title' => 'Beredskap og hendelseshåndtering',
            'suggested_filename' => 'beredskap-og-hendelseshandtering.docx',
            'reasoning_summary' => 'Relevant støtte mangler.',
        ]);

        $this->assertSame('Språkkrav og norsk dokumentasjon', $recommendation['recommended_document_title']);
        $this->assertSame('sprakkrav-og-norsk-dokumentasjon.docx', $recommendation['suggested_filename']);
    }

    public function test_it_prefers_topic_and_sub_topic_from_the_requirement_snapshot(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Uansett tekstinnhold skal eksplisitt tema brukes.',
            'current_requirement_snapshot' => [
                'topic' => 'Tilgangsstyring',
                'sub_topic' => 'Identitetsforvaltning',
                'keywords' => ['autentisering', 'autorisering'],
            ],
        ]);

        $recommendation = $service->recommendForRequirement($requirement);

        $this->assertSame('Tilgangsstyring og Identitetsforvaltning', $recommendation['recommended_document_title']);
        $this->assertSame('tilgangsstyring-og-identitetsforvaltning.docx', $recommendation['suggested_filename']);
    }

    public function test_it_uses_a_judge_recommendation_when_it_matches_the_missing_theme(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Leverandøren skal ha lærling med godkjent læreforhold og fagbrev.',
        ]);

        $recommendation = $service->recommendForRequirement($requirement, [
            'status' => 'partial',
            'can_generate_answer' => false,
            'directly_supported_points' => [],
            'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning er dokumentert.'],
            'unsupported_points' => ['Lærling og fagbrev er ikke dokumentert.'],
            'missing_knowledge_summary' => 'Kunnskapsgrunnlaget mangler lærlingeordning.',
            'recommended_document_title' => 'Lærlingeordning og kompetanseutvikling',
            'suggested_filename' => 'laerlingeordning-og-kompetanseutvikling.docx',
            'reasoning_summary' => 'Lærlingeordning mangler.',
        ]);

        $this->assertSame('Lærlingeordning og kompetanseutvikling', $recommendation['recommended_document_title']);
        $this->assertSame('laerlingeordning-og-kompetanseutvikling.docx', $recommendation['suggested_filename']);
    }

    public function test_it_recommends_an_incident_response_document_from_judge_context(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Leverandøren skal beskrive beredskap og hendelseshåndtering.',
        ]);

        $recommendation = $service->recommendForRequirement($requirement, [
            'status' => 'unsupported',
            'can_generate_answer' => false,
            'directly_supported_points' => [],
            'related_but_insufficient_points' => [],
            'unsupported_points' => ['Beredskap er ikke dokumentert i den relevante kunnskapsbasen.'],
            'missing_knowledge_summary' => 'Kravet mangler dokumentert beredskap og hendelseshåndtering.',
            'recommended_document_title' => null,
            'suggested_filename' => null,
            'reasoning_summary' => 'Ingen relevant støtte.',
        ]);

        $this->assertSame('Beredskap og hendelseshåndtering', $recommendation['recommended_document_title']);
        $this->assertSame('beredskap-og-hendelseshandtering.docx', $recommendation['suggested_filename']);
    }

    public function test_it_recommends_a_microsoft_change_document_from_missing_requirement_theme(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Leverandøren bør ha rutiner for å følge opp Microsoft-endringer og proaktivt informere Kunden med anbefalte tiltak, konsekvensvurdering og prioritering, integrert i styring og endringshåndtering.',
        ]);

        $recommendation = $service->recommendForRequirement($requirement, [
            'status' => 'partial',
            'can_generate_answer' => false,
            'directly_supported_points' => [],
            'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning og hendelseshåndtering er dokumentert.'],
            'unsupported_points' => [
                'Microsoft-endringsoppfølging er ikke dokumentert.',
                'Anbefalte tiltak, konsekvensvurdering, prioritering og integrasjon med endringshåndtering er ikke dokumentert.',
            ],
            'missing_knowledge_summary' => 'Kunnskapsgrunnlaget mangler dokumentert støtte for Microsoft-endringsoppfølging og tilhørende tiltak.',
            'recommended_document_title' => 'Beredskap og hendelseshåndtering',
            'suggested_filename' => 'beredskap-og-hendelseshandtering.docx',
            'reasoning_summary' => 'Bare generell hendelseshåndtering er funnet.',
        ]);

        $this->assertSame('Proaktiv oppfølging av Microsoft-endringer', $recommendation['recommended_document_title']);
        $this->assertSame('proaktiv-oppfolging-av-microsoft-endringer.docx', $recommendation['suggested_filename']);
    }

    public function test_it_falls_back_to_a_generic_document_name_when_no_theme_is_reliable(): void
    {
        $service = new RequirementKnowledgeDocumentRecommendationService();
        $requirement = (new SavedNoticeAiRequirement())->forceFill([
            'requirement_text' => 'Leverandøren skal oppfylle kravet på en sikker og dokumenterbar måte.',
        ]);

        $recommendation = $service->recommendForRequirement($requirement);

        $this->assertSame('Dokumentasjon for udekket krav', $recommendation['recommended_document_title']);
        $this->assertSame('dokumentasjon-for-udekket-krav.docx', $recommendation['suggested_filename']);
    }
}
