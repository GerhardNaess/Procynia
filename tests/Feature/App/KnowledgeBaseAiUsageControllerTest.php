<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeBaseAiUsageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /**
     * Purpose: Confirm that an authenticated user with a valid customer context receives HTTP 200.
     * Inputs: A customer context with an active user.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_authenticated_user_gets_200_on_ai_usage_index(): void
    {
        $context = $this->customerContext('AI Usage 200 AS');

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'))
            ->assertOk();
    }

    /**
     * Purpose: Confirm that the response renders the correct Inertia component.
     * Inputs: A customer context with an active user.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_ai_usage_index_uses_correct_inertia_component(): void
    {
        $context = $this->customerContext('AI Usage Component AS');

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/AiUsage';
        });
    }

    /**
     * Purpose: Confirm that the Inertia props include all required top-level keys.
     * Inputs: A customer context with no evidence (minimal fixture).
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_ai_usage_props_contain_required_keys(): void
    {
        $context = $this->customerContext('AI Usage Props AS');

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page): bool {
            $props = data_get($page, 'props', []);

            return array_key_exists('documentUsageRows', $props)
                && array_key_exists('chunkUsageRows', $props)
                && array_key_exists('summary', $props)
                && array_key_exists('filters', $props);
        });
    }

    /**
     * Purpose: Confirm that empty state is returned when the customer has no evidence rows.
     * Inputs: A customer context with no evidence.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_empty_state_when_customer_has_no_evidence(): void
    {
        $context = $this->customerContext('AI Usage Empty AS');

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page): bool {
            $props = data_get($page, 'props', []);

            return data_get($props, 'documentUsageRows') === []
                && data_get($props, 'chunkUsageRows') === []
                && data_get($props, 'summary.document_count') === 0
                && data_get($props, 'summary.chunk_count') === 0
                && data_get($props, 'summary.evidence_count') === 0;
        });
    }

    /**
     * Purpose: Confirm that evidence from a different customer does not appear in the props.
     * Inputs: Two customers; evidence created for the foreign customer only.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_cross_customer_evidence_is_not_shown(): void
    {
        $ownContext = $this->customerContext('AI Usage Own AS');
        $foreignContext = $this->customerContext('AI Usage Foreign AS');

        [, $foreignRequirement] = $this->scaffoldCase($foreignContext['customer'], 'FOREIGN-001');
        [$foreignItem, $foreignVersion, $foreignChunk] = $this->scaffoldKnowledge($foreignContext['customer']);
        $this->createEvidence($foreignRequirement, $foreignItem, $foreignChunk, $foreignVersion);

        $response = $this->actingAs($ownContext['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page): bool {
            $props = data_get($page, 'props', []);

            return data_get($props, 'documentUsageRows') === []
                && data_get($props, 'summary.evidence_count') === 0;
        });
    }

    /**
     * Purpose: Confirm that SavedNoticeAiDocument rows have no effect on the usage aggregate.
     * Inputs: A customer with an AI document (saksdokument) but no SavedNoticeAiEvidence.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_saved_notice_ai_document_does_not_affect_usage_rows(): void
    {
        $context = $this->customerContext('AI Usage NoEvidence AS');

        $notice = $this->createSavedNotice($context['customer'], 'DOC-ONLY-001');

        SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $notice->id,
            'original_filename' => 'kravdokument.docx',
            'stored_path' => 'saved-notices/'.$notice->id.'/documents/kravdokument.docx',
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page): bool {
            $props = data_get($page, 'props', []);

            return data_get($props, 'documentUsageRows') === []
                && data_get($props, 'chunkUsageRows') === []
                && data_get($props, 'summary.evidence_count') === 0;
        });
    }

    /**
     * Purpose: Confirm that document rows include all fields required by the table and the document link.
     * Inputs: A customer context with one evidence row against a versioned knowledge item.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_document_usage_rows_contain_required_table_fields(): void
    {
        $context = $this->customerContext('AI Usage Fields AS');

        [$notice, $requirement] = $this->scaffoldCase($context['customer'], 'FIELDS-001');
        [$item, $version, $chunk] = $this->scaffoldKnowledge($context['customer']);
        $this->createEvidence($requirement, $item, $chunk, $version);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page) use ($item): bool {
            $rows = data_get($page, 'props.documentUsageRows', []);

            if (count($rows) !== 1) {
                return false;
            }

            $row = $rows[0];
            $expectedShowUrl = route('app.ai.knowledge-base.show', ['knowledgeItem' => $item->id]);

            return array_key_exists('knowledge_item_id', $row)
                && array_key_exists('original_filename', $row)
                && array_key_exists('document_type', $row)
                && array_key_exists('current_version_no', $row)
                && array_key_exists('current_version_approval_status', $row)
                && array_key_exists('case_count', $row)
                && array_key_exists('requirement_count', $row)
                && array_key_exists('evidence_count', $row)
                && array_key_exists('primary_count', $row)
                && array_key_exists('avg_match_score', $row)
                && array_key_exists('last_used_at', $row)
                && array_key_exists('evidence_on_superseded_version_count', $row)
                && array_key_exists('knowledge_item_show_url', $row)
                && $row['knowledge_item_show_url'] === $expectedShowUrl;
        });
    }

    /**
     * Purpose: Confirm that chunk rows include all fields required by the table and the document link.
     * Inputs: A customer context with one evidence row against a versioned knowledge item chunk.
     * Returns: None (assertion).
     * Side effects: None.
     */
    public function test_chunk_usage_rows_contain_required_table_fields(): void
    {
        $context = $this->customerContext('AI Usage Chunk Fields AS');

        [$notice, $requirement] = $this->scaffoldCase($context['customer'], 'CHUNK-001');
        [$item, $version, $chunk] = $this->scaffoldKnowledge($context['customer']);
        $this->createEvidence($requirement, $item, $chunk, $version);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.ai-usage'));

        $response->assertViewHas('page', function (array $page) use ($item, $chunk): bool {
            $rows = data_get($page, 'props.chunkUsageRows', []);

            if (count($rows) !== 1) {
                return false;
            }

            $row = $rows[0];
            $expectedShowUrl = route('app.ai.knowledge-base.show', ['knowledgeItem' => $item->id]);

            return array_key_exists('knowledge_item_chunk_id', $row)
                && array_key_exists('knowledge_item_id', $row)
                && array_key_exists('knowledge_item_show_url', $row)
                && array_key_exists('original_filename', $row)
                && array_key_exists('chunk_index', $row)
                && array_key_exists('chunk_type', $row)
                && array_key_exists('section_title', $row)
                && array_key_exists('heading_path', $row)
                && array_key_exists('topic', $row)
                && array_key_exists('sub_topic', $row)
                && array_key_exists('version_no_used', $row)
                && array_key_exists('version_is_current', $row)
                && array_key_exists('version_approval_status', $row)
                && array_key_exists('case_count', $row)
                && array_key_exists('requirement_count', $row)
                && array_key_exists('evidence_count', $row)
                && array_key_exists('primary_count', $row)
                && array_key_exists('avg_match_score', $row)
                && array_key_exists('max_match_score', $row)
                && array_key_exists('last_used_at', $row)
                && $row['knowledge_item_chunk_id'] === $chunk->id
                && $row['knowledge_item_id'] === $item->id
                && $row['knowledge_item_show_url'] === $expectedShowUrl;
        });
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    private function customerContext(string $customerName): array
    {
        static $sequence = 0;
        $sequence++;
        $code = str_pad(base_convert((string) $sequence, 10, 36), 2, '0', STR_PAD_LEFT);

        $language = Language::query()->create([
            'code' => $code,
            'name_en' => 'English',
            'name_no' => 'Engelsk',
        ]);
        $nationality = Nationality::query()->create([
            'code' => $code,
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
            'flag_emoji' => '🇳🇴',
        ]);
        $customer = Customer::query()->create([
            'name' => $customerName,
            'slug' => Str::slug($customerName).'-'.Str::lower(Str::random(6)),
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => $customerName.' User',
            'email' => Str::slug($customerName).'+user@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'user' => $user];
    }

    private function createSavedNotice(Customer $customer, string $externalId = 'NOTICE-001'): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => $externalId,
            'title' => 'Test anbudsutlysning '.$externalId,
        ]);
    }

    private function scaffoldCase(Customer $customer, string $externalId = 'NOTICE-001'): array
    {
        $notice = $this->createSavedNotice($customer, $externalId);

        $aiDocument = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $notice->id,
            'original_filename' => 'krav-'.$externalId.'.docx',
            'stored_path' => 'saved-notices/'.$notice->id.'/documents/krav.docx',
        ]);

        $aiDocumentChunk = SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $aiDocument->id,
            'content' => 'Kravtekst for '.$externalId.'.',
            'chunk_index' => 0,
            'char_start' => 0,
            'char_end' => 40,
        ]);

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'saved_notice_ai_document_id' => $aiDocument->id,
            'saved_notice_ai_document_chunk_id' => $aiDocumentChunk->id,
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_PHASE_1,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
        ]);

        return [$notice, $requirement];
    }

    private function scaffoldKnowledge(Customer $customer): array
    {
        $item = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Testdokument',
            'content' => 'Testinnhold.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
        ]);

        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'testdokument.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/'.$item->id.'/v1.docx',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        $chunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $item->id,
            'knowledge_item_version_id' => $version->id,
            'chunk_index' => 0,
            'content' => 'Testinnhold for chunk 0.',
            'start_offset' => 0,
            'end_offset' => 40,
        ]);

        return [$item, $version, $chunk];
    }

    private function createEvidence(
        SavedNoticeAiRequirement $requirement,
        KnowledgeItem $item,
        KnowledgeItemChunk $chunk,
        ?KnowledgeItemVersion $version,
    ): SavedNoticeAiEvidence {
        return SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $item->id,
            'knowledge_item_chunk_id' => $chunk->id,
            'knowledge_item_version_id' => $version?->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 75,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => 5432,
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.url' => null,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
}
