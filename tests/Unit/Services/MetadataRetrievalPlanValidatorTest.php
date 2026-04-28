<?php

namespace Tests\Unit\Services;

use App\Services\Ai\Retrieval\MetadataRetrievalPlanValidator;
use Tests\TestCase;

class MetadataRetrievalPlanValidatorTest extends TestCase
{
    public function test_it_removes_unknown_fields_and_values_while_preserving_valid_metadata(): void
    {
        $validator = new MetadataRetrievalPlanValidator();

        $validated = $validator->validate([
            'selected_metadata' => [
                'topic' => ['Tema A', 'Ukjent tema'],
                'keywords' => ['Nøkkelord A', 'Nøkkelord Z'],
                'unknown_field' => ['skal_fjernes'],
            ],
            'search_text' => '  Metadata søk  ',
            'intent_summary' => '  Kort oppsummering  ',
            'confidence' => 1.4,
        ], [
            'fields' => [
                'topic' => ['Tema A', 'Tema B'],
                'keywords' => ['Nøkkelord A', 'Nøkkelord B'],
            ],
        ]);

        $this->assertSame([
            'topic' => ['Tema A'],
            'keywords' => ['Nøkkelord A'],
        ], $validated['selected_metadata']);
        $this->assertSame('Metadata søk', $validated['search_text']);
        $this->assertSame('Kort oppsummering', $validated['intent_summary']);
        $this->assertSame(1.0, $validated['confidence']);
    }

    public function test_it_keeps_valid_values_even_when_other_values_are_invalid(): void
    {
        $validator = new MetadataRetrievalPlanValidator();

        $validated = $validator->validate([
            'selected_metadata' => [
                'sub_topic' => ['Underemne A', 'Ukjent underemne'],
                'section_title' => ['Del 1'],
            ],
            'search_text' => 'Søk',
            'intent_summary' => 'Oppsummering',
            'confidence' => 0.62,
        ], [
            'fields' => [
                'sub_topic' => ['Underemne A'],
                'section_title' => ['Del 1'],
            ],
        ]);

        $this->assertSame([
            'sub_topic' => ['Underemne A'],
            'section_title' => ['Del 1'],
        ], $validated['selected_metadata']);
        $this->assertSame('Søk', $validated['search_text']);
        $this->assertSame('Oppsummering', $validated['intent_summary']);
        $this->assertSame(0.62, $validated['confidence']);
    }
}
