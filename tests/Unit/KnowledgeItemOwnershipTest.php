<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeItemOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_company_ownership_and_exposes_company_state_helpers(): void
    {
        $customer = $this->createCustomer('Ownership Default Customer AS');

        $document = $this->createKnowledgeItem($customer);

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_COMPANY, $document->ownership_type);
        $this->assertTrue($document->isCompanyOwned());
        $this->assertFalse($document->isPersonalOwned());
        $this->assertFalse($document->isCaseOwned());
        $this->assertNull($document->owner);
        $this->assertNull($document->owningSavedNotice);
    }

    public function test_it_resolves_personal_owner_and_case_notice_relations(): void
    {
        $customer = $this->createCustomer('Ownership Relation Customer AS');
        $owner = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'OWNERSHIP-001',
            'title' => 'Ownership case',
        ]);

        $personalDocument = $this->createKnowledgeItem($customer, [
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
            'owner_user_id' => $owner->id,
        ]);

        $caseDocument = $this->createKnowledgeItem($customer, [
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_CASE,
            'owner_user_id' => $owner->id,
            'owning_saved_notice_id' => $savedNotice->id,
        ]);

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_PERSONAL, $personalDocument->ownership_type);
        $this->assertTrue($personalDocument->isPersonalOwned());
        $this->assertSame($owner->id, $personalDocument->owner?->id);
        $this->assertNull($personalDocument->owningSavedNotice);

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_CASE, $caseDocument->ownership_type);
        $this->assertTrue($caseDocument->isCaseOwned());
        $this->assertSame($owner->id, $caseDocument->owner?->id);
        $this->assertSame($savedNotice->id, $caseDocument->owningSavedNotice?->id);
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
        $content = $overrides['content'] ?? 'Ownership test content.';

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'ownership_type' => $overrides['ownership_type'] ?? KnowledgeItem::OWNERSHIP_TYPE_COMPANY,
            'title' => $overrides['title'] ?? 'Ownership test document',
            'content' => $content,
            'original_filename' => $overrides['original_filename'] ?? 'ownership-test-document.docx',
            'storage_path' => $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-items/ownership-test-document.docx',
            'mime_type' => $overrides['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'content_type' => $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'summary' => $overrides['summary'] ?? 'Oppsummering',
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'owner_user_id' => $overrides['owner_user_id'] ?? null,
            'owning_saved_notice_id' => $overrides['owning_saved_notice_id'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $overrides));
    }
}
