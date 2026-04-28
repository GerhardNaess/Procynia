<?php

namespace Tests\Unit\Services;

use App\Services\Ai\Knowledge\KnowledgeVocabularyExtractionService;
use ReflectionMethod;
use Tests\TestCase;

class KnowledgeVocabularyExtractionServiceTest extends TestCase
{
    public function test_analysis_schema_requires_related_existing_term_on_each_suggestion(): void
    {
        $service = app(KnowledgeVocabularyExtractionService::class);
        $method = new ReflectionMethod($service, 'analysisSchema');
        $method->setAccessible(true);

        $schema = $method->invoke($service);

        $required = data_get($schema, 'properties.suggestions.items.required', []);

        $this->assertContains('related_existing_term', $required);
    }
}
