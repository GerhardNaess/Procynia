<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeVocabularyExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class KnowledgeVocabularyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_renders_the_ai_vocabulary_workspace_with_customer_scoped_data(): void
    {
        $context = $this->customerContext('Vocabulary Workspace AS');

        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => 'service_product_tag',
            'canonical_name' => 'Governance',
            'synonyms' => ['samhandling'],
            'description' => 'Styringsmodell.',
            'approved' => true,
        ]);

        $document = $this->createKnowledgeItem($context['customer'], [
            'original_filename' => 'representative.docx',
            'summary' => 'Dokumentsammendrag.',
            'extracted_text' => 'Samhandling og møtefora.',
        ]);

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $context['customer']->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW,
            'source_document_ids' => [$document->id],
            'summary' => 'Kort oppsummering.',
            'error_message' => null,
            'created_by' => $context['user']->id,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Samhandlingsmodell',
            'suggested_canonical_name' => 'Samhandlingsmodell',
            'suggested_type' => 'service_product_tag',
            'suggested_synonyms' => ['styringsmodell'],
            'suggested_description' => 'Beskrivelse.',
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => $term->id,
            'reason' => 'Relevant begrep.',
            'confidence_score' => 0.86,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-vocabulary.index'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document, $term): bool {
            $props = data_get($page, 'props', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeVocabulary/Index'
                && data_get($props, 'pageTitle') === 'Selskapsvokabular'
                && count(data_get($props, 'approvedVocabularyGroups', [])) === 1
                && data_get($props, 'approvedVocabularyGroups.0.terms.0.delete_url') === route('app.ai.knowledge-vocabulary.terms.destroy', ['term' => $term->id])
                && count(data_get($props, 'suggestions', [])) === 1
                && count(data_get($props, 'recentBatches', [])) === 1
                && count(data_get($props, 'sourceDocuments', [])) === 1
                && data_get($props, 'sourceDocuments.0.id') === $document->id
                && data_get($props, 'suggestions.0.related_existing_term_label') === 'Governance';
        });
    }

    public function test_it_shows_correct_field_labels_for_chunk_based_suggestions(): void
    {
        $context = $this->customerContext('Vocabulary Chunk Labels AS');

        $document = $this->createKnowledgeItem($context['customer'], [
            'original_filename' => 'labels.docx',
            'summary' => 'Dokumentsammendrag.',
            'extracted_text' => 'Tema A, Underemne A og stikkord.',
        ]);

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $context['customer']->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW,
            'source_document_ids' => [$document->id],
            'summary' => 'Kort oppsummering.',
            'error_message' => null,
            'created_by' => $context['user']->id,
        ]);

        $chunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Tema A, Underemne A og stikkord.',
            'start_offset' => 0,
            'end_offset' => 40,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'source_chunk_id' => $chunk->id,
            'suggested_term' => 'Tema A',
            'suggested_canonical_name' => 'Tema A',
            'suggested_type' => 'topic',
            'suggested_synonyms' => ['styring'],
            'suggested_description' => 'Beskrivelse.',
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Relevant begrep.',
            'confidence_score' => null,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'source_chunk_id' => $chunk->id,
            'suggested_term' => 'Underemne A',
            'suggested_canonical_name' => 'Underemne A',
            'suggested_type' => 'sub_topic',
            'suggested_synonyms' => ['oppfølging'],
            'suggested_description' => 'Beskrivelse.',
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Relevant begrep.',
            'confidence_score' => null,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'source_chunk_id' => $chunk->id,
            'suggested_term' => 'Stikkord A',
            'suggested_canonical_name' => 'Stikkord A',
            'suggested_type' => 'keywords',
            'suggested_synonyms' => ['nøkkelord'],
            'suggested_description' => 'Beskrivelse.',
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Relevant begrep.',
            'confidence_score' => null,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-vocabulary.index'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $suggestions = collect(data_get($page, 'props.suggestions', []))->keyBy('suggested_term');

            return data_get($page, 'component') === 'App/AI/KnowledgeVocabulary/Index'
                && data_get($suggestions, 'Tema A.suggested_type_label') === 'Emne'
                && data_get($suggestions, 'Underemne A.suggested_type_label') === 'Underemne'
                && data_get($suggestions, 'Stikkord A.suggested_type_label') === 'Nøkkelord'
                && data_get($suggestions, 'Tema A.source_label') === 'labels.docx · Chunk 1'
                && data_get($suggestions, 'Tema A.suggested_synonyms.0') === 'styring'
                && data_get($suggestions, 'Tema A.suggested_description') === 'Beskrivelse.'
                && data_get($suggestions, 'Tema A.reason') === 'Relevant begrep.';
        });
    }

    public function test_it_starts_a_batch_analysis_from_selected_documents_and_creates_pending_suggestions(): void
    {
        $context = $this->customerContext('Vocabulary Batch AS');
        $document = $this->createKnowledgeItem($context['customer'], [
            'original_filename' => 'analysis-source.docx',
            'summary' => 'Dokumentsammendrag.',
            'extracted_text' => 'Samhandling og møtefora for oppfølging av leveransen.',
        ]);

        $extraction = Mockery::mock(KnowledgeVocabularyExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->andReturn([
                'batch_summary' => 'Dokumentene beskriver styring og samhandling.',
                'suggestions' => [
                    [
                        'type' => 'service_product_tag',
                        'canonical_name' => 'Nytt begrep',
                        'synonyms' => ['Ny synonym'],
                        'description' => 'Beskrivelse.',
                        'related_existing_term' => null,
                        'reason' => 'Nytt begrep i dokumentene.',
                        'confidence_score' => 0.93,
                    ],
                ],
            ]);
        $this->app->instance(KnowledgeVocabularyExtractionService::class, $extraction);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-vocabulary.analysis-batches.store'), [
            'source_document_ids' => [$document->id],
        ]);

        $response->assertRedirect();

        $batch = KnowledgeVocabularyAnalysisBatch::query()->where('customer_id', $context['customer']->id)->firstOrFail();

        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW, $batch->status);
        $this->assertSame('Dokumentene beskriver styring og samhandling.', $batch->summary);
        $this->assertSame(1, KnowledgeMetadataTermSuggestion::query()->count());
        $this->assertSame('Nytt begrep', KnowledgeMetadataTermSuggestion::query()->firstOrFail()->suggested_term);
    }

    public function test_it_updates_an_approved_vocabulary_term(): void
    {
        $context = $this->customerContext('Vocabulary Update AS');

        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => 'process',
            'canonical_name' => 'Problem',
            'synonyms' => ['feilhåndtering'],
            'description' => 'Opprinnelig beskrivelse.',
            'approved' => true,
        ]);

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-vocabulary.terms.update', ['term' => $term->id]), [
            'type' => 'process',
            'canonical_name' => 'Problemhåndtering',
            'synonyms' => 'problem, root cause analysis',
            'description' => 'Oppdatert beskrivelse.',
        ]);

        $response->assertRedirect();

        $term->refresh();

        $this->assertSame('process', $term->type);
        $this->assertSame('Problemhåndtering', $term->canonical_name);
        $this->assertSame(['problem', 'root cause analysis'], $term->synonyms);
        $this->assertSame('Oppdatert beskrivelse.', $term->description);
        $this->assertTrue($term->approved);
    }

    public function test_it_deletes_an_approved_vocabulary_term_and_detaches_related_suggestions(): void
    {
        $context = $this->customerContext('Vocabulary Delete Term AS');

        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => 'theme_tag',
            'canonical_name' => 'Slettbart tema',
            'synonyms' => ['tema'],
            'description' => 'Skal slettes.',
            'approved' => true,
        ]);

        $suggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => null,
            'suggested_term' => 'Relatert forslag',
            'suggested_canonical_name' => 'Relatert forslag',
            'suggested_type' => 'theme_tag',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => $term->id,
            'reason' => 'Skal løsne referansen ved sletting.',
            'confidence_score' => null,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $response = $this->actingAs($context['user'])
            ->delete(route('app.ai.knowledge-vocabulary.terms.destroy', ['term' => $term->id]));

        $response->assertRedirect(route('app.ai.knowledge-vocabulary.index'));

        $this->assertDatabaseMissing('knowledge_metadata_terms', [
            'id' => $term->id,
            'customer_id' => $context['customer']->id,
        ]);

        $this->assertNull($suggestion->fresh()->related_existing_term_id);
    }

    public function test_it_blocks_foreign_customer_term_update_actions(): void
    {
        $first = $this->customerContext('Vocabulary Term Customer One AS');
        $second = $this->customerContext('Vocabulary Term Customer Two AS');

        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $second['customer']->id,
            'type' => 'process',
            'canonical_name' => 'Problem',
            'synonyms' => [],
            'description' => 'Beskrivelse.',
            'approved' => true,
        ]);

        $this->actingAs($first['user'])
            ->patch(route('app.ai.knowledge-vocabulary.terms.update', ['term' => $term->id]), [
                'type' => 'process',
                'canonical_name' => 'Oppdatert problem',
                'synonyms' => '',
                'description' => 'Oppdatert beskrivelse.',
            ])
            ->assertNotFound();
    }

    public function test_it_blocks_foreign_customer_term_delete_actions(): void
    {
        $first = $this->customerContext('Vocabulary Term Delete Customer One AS');
        $second = $this->customerContext('Vocabulary Term Delete Customer Two AS');

        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $second['customer']->id,
            'type' => 'theme_tag',
            'canonical_name' => 'Utenlandsk term',
            'synonyms' => [],
            'description' => 'Beskrivelse.',
            'approved' => true,
        ]);

        $this->actingAs($first['user'])
            ->delete(route('app.ai.knowledge-vocabulary.terms.destroy', ['term' => $term->id]))
            ->assertNotFound();
    }

    public function test_it_marks_a_review_batch_completed_once_the_last_pending_suggestion_is_handled(): void
    {
        $context = $this->customerContext('Vocabulary Completion AS');

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $context['customer']->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW,
            'source_document_ids' => [101],
            'summary' => 'Dokumentene beskriver styring og samhandling.',
            'error_message' => null,
            'created_by' => $context['user']->id,
        ]);

        $firstSuggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Første begrep',
            'suggested_canonical_name' => 'Første begrep',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Første forslag.',
            'confidence_score' => 0.9,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $secondSuggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Andre begrep',
            'suggested_canonical_name' => 'Andre begrep',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Andre forslag.',
            'confidence_score' => 0.9,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $this->actingAs($context['user'])
            ->patch(route('app.ai.knowledge-vocabulary.suggestions.reject', ['suggestion' => $firstSuggestion->id]))
            ->assertRedirect();

        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW, $batch->fresh()->status);
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_REJECTED, $firstSuggestion->fresh()->status);
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_PENDING, $secondSuggestion->fresh()->status);

        $this->actingAs($context['user'])
            ->patch(route('app.ai.knowledge-vocabulary.suggestions.reject', ['suggestion' => $secondSuggestion->id]))
            ->assertRedirect();

        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(KnowledgeMetadataTermSuggestion::STATUS_REJECTED, $secondSuggestion->fresh()->status);
    }

    public function test_it_completes_stale_review_batches_when_rendering_the_workspace(): void
    {
        $context = $this->customerContext('Vocabulary Stale Review AS');

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $context['customer']->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW,
            'source_document_ids' => [101],
            'summary' => 'Dokumentene beskriver styring og samhandling.',
            'error_message' => null,
            'created_by' => $context['user']->id,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Godkjent begrep',
            'suggested_canonical_name' => 'Godkjent begrep',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Godkjent forslag.',
            'confidence_score' => 0.9,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_APPROVED,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Avvist begrep',
            'suggested_canonical_name' => 'Avvist begrep',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Avvist forslag.',
            'confidence_score' => 0.9,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_REJECTED,
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-vocabulary.index'))
            ->assertOk();

        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_it_blocks_foreign_customer_approval_actions(): void
    {
        $first = $this->customerContext('Vocabulary Customer One AS');
        $second = $this->customerContext('Vocabulary Customer Two AS');

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $second['customer']->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW,
            'source_document_ids' => [1],
            'summary' => null,
            'error_message' => null,
            'created_by' => $second['user']->id,
        ]);

        $suggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $second['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Foreign term',
            'suggested_canonical_name' => 'Foreign term',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Foreign customer data.',
            'confidence_score' => 0.9,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $this->actingAs($first['user'])
            ->patch(route('app.ai.knowledge-vocabulary.suggestions.approve', ['suggestion' => $suggestion->id]))
            ->assertNotFound();
    }

    public function test_it_deletes_failed_batches_and_their_suggestions_for_the_current_customer(): void
    {
        $context = $this->customerContext('Vocabulary Delete AS');

        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $context['customer']->id,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_FAILED,
            'source_document_ids' => [101],
            'summary' => null,
            'error_message' => 'OpenAI request failed with HTTP status [400].',
            'created_by' => $context['user']->id,
        ]);

        $suggestion = KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $context['customer']->id,
            'batch_id' => $batch->id,
            'suggested_term' => 'Slettbar term',
            'suggested_canonical_name' => 'Slettbar term',
            'suggested_type' => 'topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Skal fjernes sammen med batchen.',
            'confidence_score' => 0.5,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $this->actingAs($context['user'])
            ->delete(route('app.ai.knowledge-vocabulary.analysis-batches.destroy', ['batch' => $batch->id]))
            ->assertRedirect(route('app.ai.knowledge-vocabulary.index'));

        $this->assertDatabaseMissing('knowledge_vocabulary_analysis_batches', [
            'id' => $batch->id,
            'customer_id' => $context['customer']->id,
        ]);
        $this->assertDatabaseMissing('knowledge_metadata_term_suggestions', [
            'id' => $suggestion->id,
            'customer_id' => $context['customer']->id,
        ]);
    }

    private function customerContext(string $customerName): array
    {
        $customer = $this->createCustomer($customerName);
        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        return compact('customer', 'user');
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

    private function createKnowledgeItem(Customer $customer, array $overrides = []): KnowledgeItem
    {
        $title = $overrides['title'] ?? 'Representative document';
        $filename = $overrides['original_filename'] ?? 'representative.docx';
        $content = $overrides['content'] ?? ($overrides['extracted_text'] ?? 'Representative content.');

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $content,
            'original_filename' => $filename,
            'storage_path' => $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-documents/representative.docx',
            'mime_type' => $overrides['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'content_type' => $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'summary' => $overrides['summary'] ?? 'Oppsummering',
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $overrides));
    }
}
