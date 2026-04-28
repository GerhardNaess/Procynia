<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use App\Services\Ai\Knowledge\KnowledgeVocabularySuggestionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeVocabularySuggestionValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_pending_suggestions_for_new_terms(): void
    {
        [$batch, $catalog] = $this->fixtureBundle();

        $service = app(KnowledgeVocabularySuggestionValidationService::class);
        $result = $service->validateAndPersist($batch, [
            'batch_summary' => 'Kort oppsummering.',
            'suggestions' => [
                [
                    'type' => 'service_product_tag',
                    'canonical_name' => 'Nytt begrep',
                    'synonyms' => ['Ny synonym'],
                    'description' => 'Ny beskrivelse.',
                    'related_existing_term' => null,
                    'reason' => 'Nytt begrep i dokumentene.',
                    'confidence_score' => 0.91,
                ],
            ],
        ], $catalog);

        $this->assertSame('Kort oppsummering.', $result['batch_summary']);
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['related_count']);
        $this->assertSame(1, KnowledgeMetadataTermSuggestion::query()->count());

        $suggestion = KnowledgeMetadataTermSuggestion::query()->firstOrFail();

        $this->assertSame($batch->customer_id, $suggestion->customer_id);
        $this->assertSame('Nytt begrep', $suggestion->suggested_canonical_name);
        $this->assertSame(['Ny synonym'], $suggestion->suggested_synonyms);
        $this->assertNull($suggestion->related_existing_term_id);
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_PENDING, $suggestion->status);
    }

    public function test_it_skips_suggestions_that_match_approved_canonical_names_or_synonyms(): void
    {
        [$batch, $catalog] = $this->fixtureBundle();

        $service = app(KnowledgeVocabularySuggestionValidationService::class);
        $result = $service->validateAndPersist($batch, [
            'batch_summary' => 'Kort oppsummering.',
            'suggestions' => [
                [
                    'type' => 'service_product_tag',
                    'canonical_name' => 'Governance',
                    'synonyms' => [],
                    'description' => 'Duplikat av godkjent canonical name.',
                    'related_existing_term' => null,
                    'reason' => 'Skal ikke bli forslag.',
                    'confidence_score' => 0.88,
                ],
                [
                    'type' => 'service_product_tag',
                    'canonical_name' => 'samhandling',
                    'synonyms' => [],
                    'description' => 'Duplikat av godkjent synonym.',
                    'related_existing_term' => null,
                    'reason' => 'Skal ikke bli forslag.',
                    'confidence_score' => 0.88,
                ],
            ],
        ], $catalog);

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(0, $result['related_count']);
        $this->assertSame(2, $result['skipped_count']);
        $this->assertSame(0, KnowledgeMetadataTermSuggestion::query()->count());
    }

    public function test_it_links_related_existing_term_hints_to_the_existing_approved_term(): void
    {
        [$batch, $catalog] = $this->fixtureBundle();

        $service = app(KnowledgeVocabularySuggestionValidationService::class);
        $result = $service->validateAndPersist($batch, [
            'batch_summary' => 'Kort oppsummering.',
            'suggestions' => [
                [
                    'type' => 'service_product_tag',
                    'canonical_name' => 'Samhandlingsmodell',
                    'synonyms' => ['leveransestyring', 'beslutningsfora'],
                    'description' => 'Utvidelse av eksisterende begrep.',
                    'related_existing_term' => 'Governance',
                    'reason' => 'Begrepet bygger videre på Governance.',
                    'confidence_score' => 0.87,
                ],
            ],
        ], $catalog);

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(1, $result['related_count']);
        $this->assertSame(1, KnowledgeMetadataTermSuggestion::query()->count());

        $suggestion = KnowledgeMetadataTermSuggestion::query()->firstOrFail();

        $this->assertSame('Governance', $suggestion->suggested_canonical_parent);
        $this->assertNotNull($suggestion->related_existing_term_id);
        $this->assertSame('Governance', $catalog['groups']['service_product_tag'][0]['canonical_name']);
        $this->assertContains('Samhandlingsmodell', $suggestion->suggested_synonyms);
        $this->assertContains('leveransestyring', $suggestion->suggested_synonyms);
    }

    private function fixtureBundle(): array
    {
        $customer = $this->createCustomer('Validation Customer AS');
        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'service_product_tag',
            'canonical_name' => 'Governance',
            'synonyms' => ['samhandling'],
            'description' => 'Styringsmodell.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'theme_tag',
            'canonical_name' => 'Drift',
            'synonyms' => ['driftsmodell'],
            'description' => 'Driftsrelatert tema.',
            'approved' => true,
        ]);

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $customer->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_UPLOADED,
            'source_document_ids' => [11, 12],
            'summary' => null,
            'error_message' => null,
            'created_by' => $user->id,
        ]);

        $catalog = app(KnowledgeMetadataVocabularyService::class)->buildCatalogForCustomer($customer->id);

        return [$batch, $catalog];
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
