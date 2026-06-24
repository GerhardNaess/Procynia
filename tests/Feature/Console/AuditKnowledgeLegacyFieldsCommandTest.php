<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditKnowledgeLegacyFieldsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_empty_dataset_as_ok_for_next_step(): void
    {
        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('Knowledge legacy audit')
            ->expectsOutputToContain('Summary')
            ->expectsOutputToContain('Current version integrity')
            ->expectsOutputToContain('File identity mirrors')
            ->expectsOutputToContain('Extraction mirrors')
            ->expectsOutputToContain('Legacy compatibility mirrors')
            ->expectsOutputToContain('Content fallback')
            ->expectsOutputToContain('Recommendation')
            ->expectsOutputToContain('OK_FOR_NEXT_STEP')
            ->assertSuccessful();

        $this->assertDatabaseCount('knowledge_items', 0);
        $this->assertDatabaseCount('knowledge_item_versions', 0);
    }

    public function test_command_reports_mirror_drift_and_does_not_write_any_rows(): void
    {
        $customer = $this->createCustomer('Legacy Audit Customer AS');

        $missingVersionItem = $this->createKnowledgeItem($customer, [
            'title' => 'Missing current version',
            'content' => 'Knowledge item without a current version.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'original_filename' => 'missing-current-version.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/missing-current-version.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 512,
            'extracted_text' => 'Legacy extracted text that should still be counted.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ]);

        $auditItem = $this->createKnowledgeItem($customer, [
            'title' => 'Legacy drift document',
            'content' => 'Fallback content that should be used when extraction text is absent.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'original_filename' => 'legacy-drift.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/legacy-drift.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => '',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => 'Legacy extraction error must not replace the current version error.',
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $currentVersion = $this->createKnowledgeItemVersion($auditItem, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => null,
            'storage_path' => null,
            'mime_type' => null,
            'file_size_bytes' => null,
            'extracted_text' => '',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_FAILED,
            'extraction_error' => null,
        ]);

        DB::table('knowledge_items')
            ->where('id', $auditItem->id)
            ->update([
                'storage_path' => 'legacy/customers/'.$customer->id.'/knowledge-items/legacy-drift.docx',
                'original_filename' => 'legacy-drift-legacy.docx',
                'mime_type' => 'application/pdf',
                'file_size_bytes' => 4096,
                'extracted_text' => '',
                'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
                'extraction_error' => 'Legacy extraction error must be ignored.',
                'content_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
                'is_active' => false,
                'updated_at' => now(),
            ]);

        $itemUpdatedAtBefore = DB::table('knowledge_items')
            ->where('id', $auditItem->id)
            ->value('updated_at');
        $versionUpdatedAtBefore = DB::table('knowledge_item_versions')
            ->where('id', $currentVersion->id)
            ->value('updated_at');

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('items_without_current_version=1')
            ->expectsOutputToContain('current_version_missing_storage_path=1')
            ->expectsOutputToContain('current_version_missing_original_filename=1')
            ->expectsOutputToContain('current_version_missing_mime_type=1')
            ->expectsOutputToContain('current_version_missing_file_size_bytes=1')
            ->expectsOutputToContain('current_version_failed_extraction_status=1')
            ->expectsOutputToContain('current_version_failed_without_extraction_error=1')
            ->expectsOutputToContain('current_version_missing_extracted_text=1')
            ->expectsOutputToContain('storage_path_mismatches=1')
            ->expectsOutputToContain('original_filename_mismatches=1')
            ->expectsOutputToContain('mime_type_mismatches=1')
            ->expectsOutputToContain('file_size_bytes_mismatches=1')
            ->expectsOutputToContain('legacy_extraction_status_mismatches=1')
            ->expectsOutputToContain('legacy_extraction_error_mismatches=1')
            ->expectsOutputToContain('content_fallback_candidates=1')
            ->expectsOutputToContain('content_type_vs_document_type_mismatches=1')
            ->expectsOutputToContain('is_active_vs_document_status_mismatches=1')
            ->expectsOutputToContain('BLOCKED')
            ->assertSuccessful();

        $this->assertSame($itemUpdatedAtBefore, DB::table('knowledge_items')->where('id', $auditItem->id)->value('updated_at'));
        $this->assertSame($versionUpdatedAtBefore, DB::table('knowledge_item_versions')->where('id', $currentVersion->id)->value('updated_at'));
        $this->assertSame($missingVersionItem->updated_at?->toDateTimeString(), $missingVersionItem->fresh()->updated_at?->toDateTimeString());
    }

    public function test_command_treats_content_type_and_is_active_drift_as_expected_legacy_findings(): void
    {
        $customer = $this->createCustomer('Compatibility Audit Customer AS');

        $auditItem = $this->createKnowledgeItem($customer, [
            'title' => 'Compatibility drift document',
            'content' => 'Compatibility drift content.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
            'original_filename' => 'compatibility-drift.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/compatibility-drift.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Compatibility drift extracted text.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'is_active' => true,
        ]);

        $currentVersion = $this->createKnowledgeItemVersion($auditItem, [
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => 'Compatibility drift extracted text.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $auditItem->document_type);
        $this->assertSame(KnowledgeItem::CONTENT_TYPE_OTHER, $auditItem->content_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ARCHIVED, $auditItem->document_status);
        $this->assertTrue((bool) $auditItem->is_active);
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $currentVersion->fresh()->knowledgeItem?->document_type);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('content_type_vs_document_type_mismatches=1')
            ->expectsOutputToContain('is_active_vs_document_status_mismatches=1')
            ->expectsOutputToContain('Expected legacy findings')
            ->expectsOutputToContain('OK_FOR_NEXT_STEP')
            ->assertSuccessful();
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
        $content = $overrides['content'] ?? 'Legacy audit content.';

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $overrides['title'] ?? 'Legacy audit document',
            'content' => $content,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'content_type' => $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_status' => $overrides['document_status'] ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'original_filename' => $overrides['original_filename'] ?? 'legacy-audit-document.docx',
            'storage_path' => $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-items/legacy-audit-document.docx',
            'mime_type' => $overrides['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $overrides));
    }

    private function createKnowledgeItemVersion(KnowledgeItem $knowledgeItem, array $overrides = []): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create(array_merge([
            'knowledge_item_id' => $knowledgeItem->id,
            'customer_id' => $knowledgeItem->customer_id,
            'version_no' => $overrides['version_no'] ?? 1,
            'is_current' => $overrides['is_current'] ?? true,
            'original_filename' => $overrides['original_filename'] ?? $knowledgeItem->original_filename,
            'storage_path' => $overrides['storage_path'] ?? $knowledgeItem->storage_path,
            'mime_type' => $overrides['mime_type'] ?? $knowledgeItem->mime_type,
            'file_size_bytes' => $overrides['file_size_bytes'] ?? $knowledgeItem->file_size_bytes,
            'extracted_text' => $overrides['extracted_text'] ?? $knowledgeItem->extracted_text,
            'extraction_status' => $overrides['extraction_status'] ?? $knowledgeItem->extraction_status,
            'extraction_error' => $overrides['extraction_error'] ?? $knowledgeItem->extraction_error,
        ], $overrides));
    }
}
