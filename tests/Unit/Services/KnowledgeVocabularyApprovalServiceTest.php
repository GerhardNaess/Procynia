<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeVocabularyApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeVocabularyApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_approves_new_suggestions_as_authoritative_terms(): void
    {
        [$customer, $user, $term] = $this->fixtureBundle();

        $suggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $customer->id,
            'batch_id' => KnowledgeVocabularyAnalysisBatch::query()->create([
                'customer_id' => $customer->id,
                'status' => KnowledgeVocabularyAnalysisBatch::STATUS_UPLOADED,
                'source_document_ids' => [1],
                'summary' => null,
                'error_message' => null,
                'created_by' => $user->id,
            ])->id,
            'suggested_term' => 'Nytt begrep',
            'suggested_canonical_name' => 'Nytt begrep',
            'suggested_type' => 'theme_tag',
            'suggested_synonyms' => ['ny synonym'],
            'suggested_description' => 'Beskrivelse',
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Begrunnelse',
            'confidence_score' => 0.93,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $service = app(KnowledgeVocabularyApprovalService::class);
        $updatedSuggestion = $service->approveSuggestion($suggestion->id, $user->id);

        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_APPROVED, $updatedSuggestion->status);
        $this->assertSame(2, KnowledgeMetadataTerm::query()->where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('knowledge_metadata_terms', [
            'customer_id' => $customer->id,
            'type' => 'theme_tag',
            'canonical_name' => 'Nytt begrep',
            'approved' => true,
        ]);
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_APPROVED, $suggestion->fresh()->status);
        $this->assertSame($term->id, $term->fresh()->id);
    }

    public function test_it_rejects_pending_suggestions_without_creating_terms(): void
    {
        [$customer, $user] = $this->fixtureBundle();

        $suggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $customer->id,
            'batch_id' => KnowledgeVocabularyAnalysisBatch::query()->create([
                'customer_id' => $customer->id,
                'status' => KnowledgeVocabularyAnalysisBatch::STATUS_UPLOADED,
                'source_document_ids' => [1],
                'summary' => null,
                'error_message' => null,
                'created_by' => $user->id,
            ])->id,
            'suggested_term' => 'Avvis meg',
            'suggested_canonical_name' => 'Avvis meg',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Skal avvises.',
            'confidence_score' => 0.21,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $service = app(KnowledgeVocabularyApprovalService::class);
        $updatedSuggestion = $service->rejectSuggestion($suggestion->id, $user->id);

        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_REJECTED, $updatedSuggestion->status);
        $this->assertSame(1, KnowledgeMetadataTerm::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_REJECTED, $suggestion->fresh()->status);
    }

    public function test_it_merges_new_synonyms_into_existing_approved_terms_without_duplicates(): void
    {
        [$customer, $user, $term] = $this->fixtureBundle();

        $suggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $customer->id,
            'batch_id' => KnowledgeVocabularyAnalysisBatch::query()->create([
                'customer_id' => $customer->id,
                'status' => KnowledgeVocabularyAnalysisBatch::STATUS_UPLOADED,
                'source_document_ids' => [1],
                'summary' => null,
                'error_message' => null,
                'created_by' => $user->id,
            ])->id,
            'suggested_term' => 'samhandling',
            'suggested_canonical_name' => 'samhandling',
            'suggested_type' => 'service_product_tag',
            'suggested_synonyms' => ['nytt synonym', 'samhandling'],
            'suggested_description' => 'Ny beskrivelse.',
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Skal flettes inn i eksisterende term.',
            'confidence_score' => 0.92,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $service = app(KnowledgeVocabularyApprovalService::class);
        $updatedSuggestion = $service->mergeSuggestion($suggestion->id, $term->id, $user->id);

        $freshTerm = $term->fresh();
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_MERGED, $updatedSuggestion->status);
        $this->assertContains('samhandling', $freshTerm->synonyms);
        $this->assertContains('nytt synonym', $freshTerm->synonyms);
        $this->assertCount(2, $freshTerm->synonyms);
    }

    private function fixtureBundle(): array
    {
        $customer = $this->createCustomer('Approval Customer AS');
        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'service_product_tag',
            'canonical_name' => 'Governance',
            'synonyms' => ['samhandling'],
            'description' => 'Styringsmodell.',
            'approved' => true,
        ]);

        return [$customer, $user, $term];
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
