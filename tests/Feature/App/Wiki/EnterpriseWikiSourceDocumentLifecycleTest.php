<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A source document's status describes its processing, and nothing else.
 *
 * It used to describe an approval too: a run whose work was finished was moved back out of
 * `completed` and into `awaiting_document_owner_approval` until a document owner confirmed that the
 * content drawn from their files was represented correctly. That confirmation stopped gating Wiki
 * publication, so nothing would ever move those runs on — they would have sat as "Avventer
 * godkjenning" for good, describing a step that no longer exists, and blocking "Lag Wiki" on the
 * way.
 *
 * The lifecycle is now: not started, processing, finished, failed. Who owns a source document is
 * still recorded, and still means something — it is simply not a stage of work.
 */
class EnterpriseWikiSourceDocumentLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private const LEGACY_STATUS = 'awaiting_document_owner_approval';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── The lifecycle ───────────────────────────────────────────────────────

    /**
     * A run that has done its work ends finished, whether or not anybody has vouched for the
     * source. This is the case that used to produce "Avventer godkjenning".
     */
    public function test_a_processed_document_ends_finished_even_with_an_unsigned_source(): void
    {
        $case = $this->documentWithUnsignedSource();

        app(EnterpriseWikiDocumentFlowService::class)
            ->reconcileRunDocumentOwnerApprovalState($case['run']);

        $run = $case['run']->fresh();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);
        // And nobody was signed off on the way.
        $this->assertTrue($case['approval']->fresh()->isPending());
    }

    /** A finished run is not reopened when the document changes hands. */
    public function test_changing_the_owner_does_not_reopen_a_finished_run(): void
    {
        $case = $this->documentWithUnsignedSource();
        $case['run']->forceFill([
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ])->save();

        $newOwner = $this->user($case['customer']);
        $case['document']->forceFill(['owner_user_id' => $newOwner->id])->save();

        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($case['document']->fresh());

        $run = $case['run']->fresh();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);
    }

    /** A document nobody has processed yet has no run at all — the list reads that as not started. */
    public function test_an_unprocessed_document_has_no_run(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        $document = $this->document($customer, $owner);

        $this->assertSame(0, EnterpriseWikiIngestRun::query()
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->count());
    }

    /** Running and failed are untouched: they are real states of real processing. */
    public function test_running_and_failed_states_still_work(): void
    {
        $case = $this->documentWithUnsignedSource();

        $case['run']->forceFill(['status' => EnterpriseWikiIngestRun::STATUS_RUNNING])->save();
        $this->assertFalse($case['run']->fresh()->isTerminal());

        $case['run']->forceFill([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'failed_phase' => EnterpriseWikiIngestRun::STATUS_QA,
            'error_message' => 'Kvalitetskontroll feilet.',
        ])->save();

        $failed = $case['run']->fresh();
        $this->assertTrue($failed->isTerminal());
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QA, $failed->failed_phase);
    }

    // ── Nothing enters the legacy status any more ───────────────────────────

    public function test_no_path_puts_a_run_back_into_the_legacy_status(): void
    {
        $case = $this->documentWithUnsignedSource();
        $flow = app(EnterpriseWikiDocumentFlowService::class);

        $flow->reconcileRunDocumentOwnerApprovalState($case['run']);
        $flow->syncDocumentOwnerApprovals($case['document']->fresh());

        $this->assertNotSame(self::LEGACY_STATUS, $case['run']->fresh()->status);
        $this->assertSame(0, EnterpriseWikiIngestRun::query()->where('status', self::LEGACY_STATUS)->count());
    }

    /**
     * The migration's job, asserted on a row written the old way: it is settled into the state it
     * had already reached, rather than left describing a wait that can never end.
     */
    public function test_a_legacy_row_is_settled_rather_than_left_waiting(): void
    {
        $case = $this->documentWithUnsignedSource();

        DB::table('enterprise_wiki_ingest_runs')->where('id', $case['run']->id)->update([
            'status' => self::LEGACY_STATUS,
            'finished_at' => null,
            'error_message' => 'Avventer godkjenning fra Dokumenteier.',
        ]);

        // Exactly what the migration does.
        DB::table('enterprise_wiki_ingest_runs')
            ->where('status', self::LEGACY_STATUS)
            ->update([
                'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
                'finished_at' => DB::raw('COALESCE(finished_at, updated_at)'),
                'error_message' => null,
                'failed_phase' => null,
            ]);

        $run = $case['run']->fresh();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);
    }

    // ── What survives ───────────────────────────────────────────────────────

    /**
     * The point of keeping any of this: the document, who owns it, and the record tying a page
     * version to the material it drew on. Only the gate went.
     */
    public function test_the_document_its_owner_and_the_provenance_row_all_survive(): void
    {
        $case = $this->documentWithUnsignedSource();

        app(EnterpriseWikiDocumentFlowService::class)
            ->reconcileRunDocumentOwnerApprovalState($case['run']);

        $document = $case['document']->fresh();
        $approval = $case['approval']->fresh();

        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, $document->document_status);
        $this->assertSame((int) $case['owner']->id, (int) $document->owner_user_id);
        $this->assertSame((int) $case['owner']->id, (int) $approval->document_owner_user_id);
        $this->assertSame((int) $case['version']->id, (int) $approval->enterprise_wiki_page_version_id);
        $this->assertNull($approval->superseded_at);
    }

    /** And the Wiki page the run produced is exactly as it was. */
    public function test_the_generated_wiki_page_is_untouched(): void
    {
        $case = $this->documentWithUnsignedSource();
        $before = $case['page']->fresh();

        app(EnterpriseWikiDocumentFlowService::class)
            ->reconcileRunDocumentOwnerApprovalState($case['run']);

        $after = $case['page']->fresh();

        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->published_version_id, $after->published_version_id);
        $this->assertSame($before->current_version_id, $after->current_version_id);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * A document whose run has finished its work, and whose owner has not signed off — the exact
     * shape that used to be diverted into "Avventer godkjenning".
     *
     * @return array<string, mixed>
     */
    private function documentWithUnsignedSource(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer);
        $document = $this->document($customer, $owner);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'kilde-'.Str::lower(Str::random(8)),
            'title' => 'Exit-prosess',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Exit-prosess',
            'generated_by_model' => 'gpt-5',
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'started_at' => now()->subMinutes(10),
        ]);

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'document_owner_user_id' => $owner->id,
            'source_document_ids' => [$document->id],
            'source_documents_hash' => hash('sha256', (string) $document->id),
            'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
        ]);

        return compact('customer', 'owner', 'document', 'page', 'version', 'run', 'approval');
    }

    private function document(Customer $customer, User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'original_filename' => 'kilde-'.Str::random(4).'.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function user(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@kildestatus.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(string $name = 'Kildestatus AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
