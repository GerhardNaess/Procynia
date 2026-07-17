<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WikiSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ─── Successful upload ────────────────────────────────────────────────────

    public function test_system_owner_can_upload_pdf_as_enterprise_wiki_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Tjenestebeskrivelse innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tjeneste.pdf', 256, 'application/pdf'),
        ]);

        $response->assertRedirect(route('app.wiki.index'));

        $this->assertSame(1, EnterpriseWikiDocument::query()
            ->where('customer_id', $customer->id)
            ->count());
    }

    public function test_system_owner_can_upload_docx_as_enterprise_wiki_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Kompetansebeskrivelse innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create(
                'kompetanse.docx',
                128,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
        ]);

        $response->assertRedirect(route('app.wiki.index'));

        $this->assertSame(1, EnterpriseWikiDocument::query()
            ->where('customer_id', $customer->id)
            ->count());
    }

    // ─── Customer scoping ─────────────────────────────────────────────────────

    public function test_uploaded_document_is_scoped_to_uploading_customer(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer('Eier AS');
        $other = $this->createCustomer('Fremmed AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('scoped.pdf', 64, 'application/pdf'),
        ]);

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $other->id)->count());
    }

    // ─── Extraction: success ──────────────────────────────────────────────────

    public function test_document_status_is_extracted_when_extraction_succeeds(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Kapittel 1. Kompetanse og erfaring fra norske virksomheter.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('status.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, $doc->document_status);
    }

    public function test_extracted_text_is_saved_when_extraction_succeeds(): void
    {
        Storage::fake('local');
        $expectedText = 'Kapittel 1. Tjenestebeskrivelse for norske virksomheter.';
        $this->mockExtractorReturning($expectedText);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tekst.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($expectedText, $doc->extracted_text);
    }

    // ─── Extraction: failure ──────────────────────────────────────────────────

    public function test_document_status_is_failed_when_extraction_returns_empty(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tom.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED, $doc->document_status);
    }

    public function test_extracted_text_is_null_when_extraction_returns_empty(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tom.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($doc->extracted_text);
    }

    public function test_no_document_is_left_as_pending_after_store(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Noe tekst.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('check.pdf', 64, 'application/pdf'),
        ]);

        $pendingCount = EnterpriseWikiDocument::query()
            ->where('customer_id', $customer->id)
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING)
            ->count();

        $this->assertSame(0, $pendingCount);
    }

    // ─── Field assertions ─────────────────────────────────────────────────────

    public function test_uploaded_by_user_id_is_set_on_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('uploader.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($user->id, $doc->uploaded_by_user_id);
    }

    public function test_uploaded_document_defaults_to_uploaded_user_as_owner_when_no_owner_is_sent(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('owner-default.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($user->id, $doc->owner_user_id);
        $this->assertTrue($doc->owner->is($user));
    }

    public function test_system_owner_can_change_document_owner(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);

        $this->actingAs($owner)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $bidManager->id,
            ])
            ->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));

        $fresh = $document->fresh('owner');
        $this->assertSame($bidManager->id, $fresh->owner_user_id);
        $this->assertTrue($fresh->owner->is($bidManager));
    }

    public function test_document_owner_update_rejects_foreign_customer_user(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer('Egen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignOwner = $this->createUser($otherCustomer, User::BID_ROLE_BID_MANAGER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);

        $this->actingAs($owner)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $foreignOwner->id,
            ])
            ->assertSessionHasErrors(['owner_user_id']);

        $this->assertNull($document->fresh()->owner_user_id);
    }

    public function test_contributor_cannot_change_document_owner(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);

        $this->actingAs($contributor)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $contributor->id,
            ])
            ->assertForbidden();

        $this->assertNull($document->fresh()->owner_user_id);
    }

    public function test_changing_document_owner_syncs_current_page_versions_and_run_state(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $firstOwner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $secondOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $page = $this->createPageWithCurrentVersionAndClaim($customer, $document, 'owner-sync-page');
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, $page->id);

        $this->linkRunToVersion($run, $page->currentVersion);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $firstOwner->id,
            ])
            ->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));

        $document->refresh();
        $run->refresh();

        $this->assertSame($firstOwner->id, $document->owner_user_id);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
        $this->assertNull($run->finished_at);
        $this->assertStringContainsString('Dokumenteier', (string) $run->error_message);

        $approvals = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $page->currentVersion->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $approvals);
        $this->assertSame($firstOwner->id, $approvals->first()?->document_owner_user_id);
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approvals->first()?->approval_status);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $secondOwner->id,
            ])
            ->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));

        $document->refresh();
        $run->refresh();

        $approvals = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $page->currentVersion->id)
            ->orderBy('id')
            ->get();

        $this->assertSame($secondOwner->id, $document->owner_user_id);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
        $this->assertCount(2, $approvals);
        $this->assertSame(
            [$firstOwner->id, $secondOwner->id],
            $approvals->pluck('document_owner_user_id')->map(fn ($value) => (int) $value)->all(),
        );

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/sources/{$document->id}/owner", [
                'owner_user_id' => $secondOwner->id,
            ])
            ->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));

        $this->assertSame(
            2,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $page->currentVersion->id)
                ->count(),
        );
    }

    public function test_changing_document_owner_syncs_only_current_versions_that_use_the_document(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $newOwner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $usedDocument = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $unusedDocument = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $pageA = $this->createPageWithCurrentVersionAndClaim($customer, $usedDocument, 'used-page');
        $pageB = $this->createPageWithCurrentVersionAndClaim($customer, $unusedDocument, 'unused-page');
        $runA = $this->createIngestRun($customer, $usedDocument, EnterpriseWikiIngestRun::STATUS_COMPLETED, $pageA->id);
        $runB = $this->createIngestRun($customer, $unusedDocument, EnterpriseWikiIngestRun::STATUS_COMPLETED, $pageB->id);

        $this->linkRunToVersion($runA, $pageA->currentVersion);
        $this->linkRunToVersion($runB, $pageB->currentVersion);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/sources/{$usedDocument->id}/owner", [
                'owner_user_id' => $newOwner->id,
            ])
            ->assertRedirect(route('app.wiki.index', ['tab' => 'sources']));

        $this->assertSame($newOwner->id, $usedDocument->fresh()->owner_user_id);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $pageA->currentVersion->id)
                ->count(),
        );
        $this->assertSame(
            0,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $pageB->currentVersion->id)
                ->count(),
        );
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $runA->fresh()->status);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $runB->fresh()->status);
    }

    public function test_file_hash_sha256_is_set_on_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $file = UploadedFile::fake()->create('hash-check.pdf', 64, 'application/pdf');
        $expectedHash = hash_file('sha256', $file->getRealPath());

        $this->actingAs($user)->post('/app/wiki/sources', ['file' => $file]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($expectedHash, $doc->file_hash_sha256);
        $this->assertSame(64, strlen($doc->file_hash_sha256));
    }

    public function test_original_filename_is_preserved(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('min-fil-navn.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('min-fil-navn.pdf', $doc->original_filename);
    }

    public function test_file_is_stored_under_wiki_documents_path(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('path-check.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertStringStartsWith(
            sprintf('customers/%d/wiki-documents/', $customer->id),
            $doc->file_path,
        );

        Storage::disk('local')->assertExists($doc->file_path);
    }

    // ─── Deduplication ────────────────────────────────────────────────────────

    public function test_same_file_for_same_customer_is_rejected_as_duplicate(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $file = UploadedFile::fake()->create('duplikat.pdf', 64, 'application/pdf');
        $this->actingAs($user)->post('/app/wiki/sources', ['file' => $file])->assertRedirect();

        $firstDoc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->createWithContent(
                'duplikat-same-hash.pdf',
                (string) file_get_contents(Storage::disk('local')->path($firstDoc->file_path)),
            ),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_same_file_for_different_customer_is_allowed(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');
        $userA = $this->createUser($customerA, User::BID_ROLE_SYSTEM_OWNER);
        $userB = $this->createUser($customerB, User::BID_ROLE_SYSTEM_OWNER);

        $content = Str::random(256);

        $this->actingAs($userA)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->createWithContent('same.pdf', $content),
        ])->assertRedirect();

        $this->actingAs($userB)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->createWithContent('same.pdf', $content),
        ])->assertRedirect();

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customerA->id)->count());
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customerB->id)->count());
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_invalid_mime_type_is_rejected(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('script.txt', 8, 'text/plain'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_missing_file_is_rejected(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', []);

        $response->assertSessionHasErrors('file');
    }

    // ─── Ingest action ────────────────────────────────────────────────────────

    public function test_ingest_is_blocked_when_wiki_generation_not_available(): void
    {
        Queue::fake();
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createExtractedDocument($customer);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_ingest_is_not_blocked_by_availability_when_flag_is_true(): void
    {
        Queue::fake();
        config(['services.enterprise_wiki.ai_enabled' => true]);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createExtractedDocument($customer);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        // Passes availability check — redirects after successful run creation, not with 'error'.
        $response->assertRedirect();
        $response->assertSessionMissing('error');
        Queue::assertPushedOn('enterprise-wiki', RunEnterpriseWikiDocumentFlow::class);
        Queue::assertNotPushed(ProcessEnterpriseWikiIngest::class);
    }

    public function test_ingest_reuses_existing_active_run_without_duplicate_dispatch(): void
    {
        Queue::fake();
        config(['services.enterprise_wiki.ai_enabled' => true]);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createExtractedDocument($customer);

        $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest")
            ->assertRedirect(route('app.wiki.index'));

        $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest")
            ->assertRedirect(route('app.wiki.index'));

        $this->assertSame(1, EnterpriseWikiIngestRun::query()->count());
        Queue::assertPushedOn('enterprise-wiki', RunEnterpriseWikiDocumentFlow::class);
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, 1);
        Queue::assertNotPushed(ProcessEnterpriseWikiIngest::class);
    }

    public function test_ingest_rejects_other_customer_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignDoc = $this->createExtractedDocument($other);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$foreignDoc->id}/ingest");

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_ingest_rejects_pending_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_ingest_rejects_failed_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    // ─── Download ────────────────────────────────────────────────────────────

    public function test_system_owner_can_download_own_document(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 test content');

        $response = $this->actingAs($user)->get("/app/wiki/sources/{$document->id}/download");

        $response->assertOk();
    }

    public function test_bid_manager_can_download_document(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 test content');

        $response = $this->actingAs($user)->get("/app/wiki/sources/{$document->id}/download");

        $response->assertOk();
    }

    public function test_download_rejects_other_customer_document(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignDoc = $this->createDocument($other, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        Storage::disk('local')->put($foreignDoc->file_path, 'Fremmed innhold.');

        $this->actingAs($user)
            ->get("/app/wiki/sources/{$foreignDoc->id}/download")
            ->assertNotFound();
    }

    public function test_download_returns_404_when_file_missing_from_storage(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);

        $this->actingAs($user)
            ->get("/app/wiki/sources/{$document->id}/download")
            ->assertNotFound();
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function test_system_owner_can_delete_own_document_without_wiki_pages(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        Storage::disk('local')->put($document->file_path, 'test content');

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_path);
    }

    public function test_failed_document_without_wiki_page_can_be_deleted(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);
        Storage::disk('local')->put($document->file_path, 'test content');
        // Failed run with no wiki page (failed before sections_planned)
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED, null);

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $document->id]);
        $this->assertDatabaseCount('enterprise_wiki_ingest_runs', 0);
    }

    public function test_delete_removes_associated_ingest_runs_and_sections(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);
        Storage::disk('local')->put($document->file_path, 'test content');
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED, null);
        EnterpriseWikiIngestSection::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'section_index' => 0,
            'heading' => 'Avsnitt 1',
            'status' => 'failed',
        ]);

        $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}")->assertRedirect();

        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('enterprise_wiki_ingest_runs', ['id' => $run->id]);
        $this->assertDatabaseCount('enterprise_wiki_ingest_sections', 0);
    }

    public function test_delete_rejects_other_customer_document(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignDoc = $this->createDocument($other, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$foreignDoc->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $foreignDoc->id]);
    }

    public function test_delete_cascades_sole_source_wiki_page(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $page = $this->createWikiPage($customer);
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, $page->id);

        \Illuminate\Support\Facades\DB::table('enterprise_wiki_ingest_run_pages')->insert([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $page->id]);
    }

    public function test_delete_marks_existing_wiki_answers_stale_and_preview_reports_them(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        Storage::disk('local')->put($document->file_path, 'test content');

        $page = $this->createWikiPage($customer);
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED, $page->id);
        \Illuminate\Support\Facades\DB::table('enterprise_wiki_ingest_run_pages')->insert([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $savedNotice = $this->createSavedNotice($customer->id);
        $aiDocument = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($aiDocument, 'Beskriv prosessen.');
        $requirement = $this->createRequirement($savedNotice, $aiDocument, $chunk);
        $answer = SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => 'Eksisterende Wiki-svar.',
            'sources' => [[
                'enterprise_wiki_page_id' => $page->id,
                'page_title' => $page->title,
                'page_slug' => $page->slug,
                'page_type' => $page->page_type,
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'supporting_claim_ids' => [],
            ]],
            'research_trace' => ['research' => ['pages' => []], 'answer' => ['answer_sections' => []]],
            'alignment_trace' => ['sections' => [], 'coverage_status' => 'full', 'has_possible_conflict' => false, 'revision' => ['attempted' => false, 'section_keys' => []]],
            'model' => 'gpt-4.1-mini',
            'has_possible_conflict' => false,
            'generated_at' => now(),
        ]);

        $preview = $this->actingAs($user)->getJson("/app/wiki/sources/{$document->id}/delete-preview");
        $preview->assertOk();
        $preview->assertJsonPath('document_owner_name', null);
        $preview->assertJsonPath('stale_wiki_answer_count', 1);
        $preview->assertJsonPath('impacted_claim_count', 0);
        $preview->assertJsonPath('impacted_source_reference_count', 0);

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('success');

        $answer->refresh();
        $this->assertNotNull($answer->stale_at);
        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::STALE_REASON_SOURCE_DOCUMENT_DELETED, $answer->stale_reason);
        $this->assertSame('test.pdf', $answer->stale_context['deleted_document_name']);
        $this->assertSame('Eksisterende Wiki-svar.', $answer->answer_text);
    }

    public function test_delete_rejects_document_with_queued_ingest_run(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_QUEUED, null);

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $document->id]);
    }

    public function test_delete_rejects_document_with_running_ingest_run(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_RUNNING, null);

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $document->id]);
    }

    public function test_delete_rejects_document_with_sections_planned_ingest_run(): void
    {
        Storage::fake('local');
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $page = $this->createWikiPage($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $page->id);

        $response = $this->actingAs($user)->delete("/app/wiki/sources/{$document->id}");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $document->id]);
    }

    // ─── No knowledge_* models touched ───────────────────────────────────────

    public function test_no_knowledge_item_rows_are_created_during_upload(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('isolation.pdf', 64, 'application/pdf'),
        ]);

        $this->assertSame(0, \App\Models\KnowledgeItem::query()->where('customer_id', $customer->id)->count());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createDocument(Customer $customer, string $status): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'test.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/%s.pdf', $customer->id, Str::random(8)),
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => $status,
            'extracted_text' => $status === EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
                ? 'Testtekst for ingest.'
                : null,
        ]);
    }

    private function createSavedNotice(int $customerId): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'wiki-delete-'.Str::random(10),
            'title' => 'Wiki delete test',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://example.invalid',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-04-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);
    }

    private function createAiDocument(SavedNotice $savedNotice): SavedNoticeAiDocument
    {
        return SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'original_filename' => 'analysis.pdf',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/analysis.pdf', $savedNotice->id),
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
        ]);
    }

    private function createAiDocumentChunk(SavedNoticeAiDocument $document, string $content): SavedNoticeAiDocumentChunk
    {
        return SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $document->id,
            'chunk_index' => 0,
            'content' => $content,
            'char_start' => 0,
            'char_end' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim($content)) ?: []),
        ]);
    }

    private function createRequirement(
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
        SavedNoticeAiDocumentChunk $chunk,
    ): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => 'REQ-1',
            'requirement_text' => 'Beskriv prosessen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'published_at' => now(),
        ]);
    }

    private function createExtractedDocument(Customer $customer): EnterpriseWikiDocument
    {
        return $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
    }

    private function createIngestRun(
        Customer $customer,
        EnterpriseWikiDocument $document,
        string $status,
        ?int $pageId,
    ): EnterpriseWikiIngestRun {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', "enterprise_wiki_document:{$document->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
            'enterprise_wiki_page_id' => $pageId,
        ]);
    }

    private function createWikiPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'wiki-delete-test-'.Str::random(8),
            'title' => 'Delete Test Wiki Page',
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => hash('sha256', 'test'),
        ]);
    }

    private function createPageWithCurrentVersionAndClaim(Customer $customer, EnterpriseWikiDocument $document, string $slug): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug.'-'.Str::lower(Str::random(8)),
            'title' => Str::headline($slug),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => hash('sha256', $slug.'-'.Str::random(8)),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# '.e($page->title),
            'generated_by_model' => 'gpt-5',
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Kildetekst for '.$document->original_filename,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag for '.$document->original_filename,
        ]);

        $page->setRelation('currentVersion', $version);

        return $page;
    }

    private function linkRunToVersion(EnterpriseWikiIngestRun $run, EnterpriseWikiPageVersion $version): void
    {
        DB::table('enterprise_wiki_ingest_run_pages')->insert([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'generated_page_version_id' => $version->id,
            'action' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mockExtractorReturning(string $text): void
    {
        $this->mock(DocumentTextExtractor::class, function ($mock) use ($text): void {
            $mock->shouldReceive('extractText')->andReturn($text);
        });
    }

    private function createCustomer(string $name = 'Wiki Source Test AS'): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Wiki Uploader',
            'email' => Str::lower(Str::random(8)).'@wiki-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
