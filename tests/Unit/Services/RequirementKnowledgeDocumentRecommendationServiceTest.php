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
