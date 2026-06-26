<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AuditKnowledgeLegacyFields;
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
            ->expectsOutputToContain('original_filename column: absent, skipped')
            ->expectsOutputToContain('storage_path column: absent, skipped')
            ->expectsOutputToContain('mime_type column: absent, skipped')
            ->expectsOutputToContain('file_size_bytes column: absent, skipped')
            ->expectsOutputToContain('extraction_status column: absent, skipped')
            ->expectsOutputToContain('extraction_error column: absent, skipped')
            ->expectsOutputToContain('extracted_text column: absent, skipped')
            ->expectsOutputToContain('content_fallback_candidates=2')
            ->expectsOutputToContain('content_type column: absent, skipped')
            ->expectsOutputToContain('is_active column: absent, skipped')
            ->expectsOutputToContain('BLOCKED')
            ->assertSuccessful();

        $this->assertSame($itemUpdatedAtBefore, DB::table('knowledge_items')->where('id', $auditItem->id)->value('updated_at'));
        $this->assertSame($versionUpdatedAtBefore, DB::table('knowledge_item_versions')->where('id', $currentVersion->id)->value('updated_at'));
        $this->assertSame($missingVersionItem->updated_at?->toDateTimeString(), $missingVersionItem->fresh()->updated_at?->toDateTimeString());
    }

    public function test_command_reports_absent_content_type_and_is_active_columns_as_skipped(): void
    {
        $customer = $this->createCustomer('Compatibility Audit Customer AS');

        $auditItem = $this->createKnowledgeItem($customer, [
            'title' => 'Compatibility drift document',
            'content' => 'Compatibility drift content.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
            'extracted_text' => 'Compatibility drift extracted text.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $this->createKnowledgeItemVersion($auditItem, [
            'version_no' => 1,
            'is_current' => true,
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/compatibility-drift.docx',
            'original_filename' => 'compatibility-drift.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Compatibility drift extracted text.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('content_type column: absent, skipped')
            ->expectsOutputToContain('is_active column: absent, skipped')
            ->expectsOutputToContain('OK_FOR_NEXT_STEP')
            ->assertSuccessful();
    }

    public function test_command_skips_content_type_when_column_is_absent(): void
    {
        $this->app->bind(AuditKnowledgeLegacyFields::class, function () {
            return new class extends AuditKnowledgeLegacyFields {
                protected $signature = 'knowledge:legacy-audit';

                protected function hasContentTypeColumn(): bool
                {
                    return false;
                }
            };
        });

        $customer = $this->createCustomer('Schema Absent Content Type AS');
        $item = $this->createKnowledgeItem($customer, [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'extracted_text' => 'Schema test content.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);
        $this->createKnowledgeItemVersion($item, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'schema-test.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/schema-test.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Schema test content.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('content_type column: absent, skipped')
            ->expectsOutputToContain('is_active column: absent, skipped')
            ->expectsOutputToContain('OK_FOR_NEXT_STEP')
            ->assertSuccessful();
    }

    public function test_command_skips_is_active_when_column_is_absent(): void
    {
        $this->app->bind(AuditKnowledgeLegacyFields::class, function () {
            return new class extends AuditKnowledgeLegacyFields {
                protected $signature = 'knowledge:legacy-audit';

                protected function hasIsActiveColumn(): bool
                {
                    return false;
                }
            };
        });

        $customer = $this->createCustomer('Schema Absent Is Active AS');
        $item = $this->createKnowledgeItem($customer, [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'extracted_text' => 'Schema test content.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);
        $this->createKnowledgeItemVersion($item, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'schema-test.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/schema-test.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Schema test content.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('is_active column: absent, skipped')
            ->expectsOutputToContain('content_type column: absent, skipped')
            ->expectsOutputToContain('OK_FOR_NEXT_STEP')
            ->assertSuccessful();
    }

    public function test_command_returns_ok_for_next_step_when_both_legacy_columns_absent(): void
    {
        $this->app->bind(AuditKnowledgeLegacyFields::class, function () {
            return new class extends AuditKnowledgeLegacyFields {
                protected $signature = 'knowledge:legacy-audit';

                protected function hasContentTypeColumn(): bool
                {
                    return false;
                }

                protected function hasIsActiveColumn(): bool
                {
                    return false;
                }
            };
        });

        $customer = $this->createCustomer('Schema Both Absent AS');
        $item = $this->createKnowledgeItem($customer, [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'extracted_text' => 'Schema test content.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);
        $this->createKnowledgeItemVersion($item, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'schema-test.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/schema-test.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Schema test content.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('content_type column: absent, skipped')
            ->expectsOutputToContain('is_active column: absent, skipped')
            ->expectsOutputToContain('OK_FOR_NEXT_STEP')
            ->assertSuccessful();
    }

    public function test_content_fallback_candidates_counts_correctly(): void
    {
        $customer = $this->createCustomer('Content Fallback Count AS');

        // Case A: version has non-blank extracted_text → not a candidate
        $itemA = $this->createKnowledgeItem($customer, ['content' => 'Item A has extracted text in its version.']);
        $this->createKnowledgeItemVersion($itemA, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'item-a.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/item-a.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Item A extracted text.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        // Case B: version exists but extracted_text is blank, content is not → is a candidate
        $itemB = $this->createKnowledgeItem($customer, ['content' => 'Item B has content but no extracted text.']);
        $this->createKnowledgeItemVersion($itemB, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'item-b.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/item-b.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => '',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_FAILED,
            'extraction_error' => 'Extraction failed.',
        ]);

        // Case D: content is blank → not a candidate even when version extracted_text is also blank
        $itemD = $this->createKnowledgeItem($customer, ['content' => '']);
        $this->createKnowledgeItemVersion($itemD, [
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'item-d.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/item-d.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => '',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_FAILED,
            'extraction_error' => 'Extraction failed.',
        ]);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('content_fallback_candidates=1')
            ->assertSuccessful();
    }

    public function test_content_fallback_candidates_counts_item_without_current_version(): void
    {
        $customer = $this->createCustomer('Content Fallback No Version AS');

        // Case C: no current version, content non-blank → is a candidate
        $this->createKnowledgeItem($customer, ['content' => 'Item without version has content that would be lost.']);

        $this->artisan('knowledge:legacy-audit')
            ->expectsOutputToContain('content_fallback_candidates=1')
            ->expectsOutputToContain('items_without_current_version=1')
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
        $kiOverrides = array_diff_key($overrides, array_flip(['original_filename', 'storage_path', 'mime_type', 'file_size_bytes', 'content_type', 'is_active']));

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $overrides['title'] ?? 'Legacy audit document',
            'content' => $content,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_status' => $overrides['document_status'] ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
        ], $kiOverrides));
    }

    private function createKnowledgeItemVersion(KnowledgeItem $knowledgeItem, array $overrides = []): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create(array_merge([
            'knowledge_item_id' => $knowledgeItem->id,
            'customer_id' => $knowledgeItem->customer_id,
            'version_no' => $overrides['version_no'] ?? 1,
            'is_current' => $overrides['is_current'] ?? true,
            'original_filename' => $overrides['original_filename'] ?? null,
            'storage_path' => $overrides['storage_path'] ?? null,
            'mime_type' => $overrides['mime_type'] ?? null,
            'file_size_bytes' => $overrides['file_size_bytes'] ?? null,
            'extracted_text' => $overrides['extracted_text'] ?? '',
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
        ], $overrides));
    }
}
