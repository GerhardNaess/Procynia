<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeVocabularyAnalysisBatchService;
use App\Services\Ai\Knowledge\KnowledgeVocabularyExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class KnowledgeVocabularyAnalysisBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_creates_batches_scoped_to_the_customer(): void
    {
        [$customer, $user] = $this->customerContext('Batch Customer AS');
        $service = app(KnowledgeVocabularyAnalysisBatchService::class);

        $batch = $service->createBatch($customer->id, [101, 202], $user->id);

        $this->assertSame($customer->id, $batch->customer_id);
        $this->assertSame([101, 202], $batch->source_document_ids);
        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_UPLOADED, $batch->status);
        $this->assertSame($user->id, $batch->created_by);
    }

    public function test_it_marks_batch_pending_review_and_creates_suggestions_when_analysis_succeeds(): void
    {
        [$customer, $user] = $this->customerContext('Batch Customer AS');
        $document = $this->createKnowledgeItem($customer, [
            'original_filename' => 'analysis-source.docx',
            'extracted_text' => 'Samhandling og møtestruktur for oppfølging av leveransen.',
            'summary' => 'Dokumentsammendrag.',
        ]);
        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'service_product_tag',
            'canonical_name' => 'Governance',
            'synonyms' => ['samhandling'],
            'description' => 'Styringsmodell.',
            'approved' => true,
        ]);

        $extraction = Mockery::mock(KnowledgeVocabularyExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->with(
                Mockery::type(KnowledgeVocabularyAnalysisBatch::class),
                Mockery::type(Collection::class),
                Mockery::on(static fn (array $catalog): bool => ($catalog['type_counts']['service_product_tag'] ?? 0) === 1),
            )
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

        $service = app(KnowledgeVocabularyAnalysisBatchService::class);
        $batch = $service->createBatch($customer->id, [$document->id], $user->id);
        $result = $service->startAnalysis($batch->id);

        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW, $result->status);
        $this->assertSame('Dokumentene beskriver styring og samhandling.', $result->summary);
        $this->assertSame(1, KnowledgeMetadataTermSuggestion::query()->count());
    }

    public function test_it_marks_batch_failed_and_creates_no_suggestions_for_invalid_output(): void
    {
        [$customer, $user] = $this->customerContext('Batch Customer AS');
        $document = $this->createKnowledgeItem($customer, [
            'original_filename' => 'analysis-source.docx',
            'extracted_text' => 'Samhandling og møtestruktur for oppfølging av leveransen.',
            'summary' => 'Dokumentsammendrag.',
        ]);

        $extraction = Mockery::mock(KnowledgeVocabularyExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->andThrow(new RuntimeException('OpenAI vocabulary analysis response was not valid JSON.'));
        $this->app->instance(KnowledgeVocabularyExtractionService::class, $extraction);

        $service = app(KnowledgeVocabularyAnalysisBatchService::class);
        $batch = $service->createBatch($customer->id, [$document->id], $user->id);
        $result = $service->startAnalysis($batch->id);

        $this->assertSame(KnowledgeVocabularyAnalysisBatch::STATUS_FAILED, $result->status);
        $this->assertNotEmpty($result->error_message);
        $this->assertSame(0, KnowledgeMetadataTermSuggestion::query()->count());
    }

    private function customerContext(string $customerName): array
    {
        $customer = $this->createCustomer($customerName);
        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        return [$customer, $user];
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
        $title = $overrides['title'] ?? 'Metadata document';
        $filename = $overrides['original_filename'] ?? 'metadata-document.docx';
        $content = $overrides['content'] ?? 'Metadata document content.';

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $content,
            'original_filename' => $filename,
            'storage_path' => $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-items/metadata-document.docx',
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
