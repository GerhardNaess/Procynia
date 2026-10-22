<?php

namespace Tests\Feature\App;

use App\Exceptions\EnterpriseWikiWithdrawalNotRepresentableException;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWikiAnswerStalenessService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWithdrawalService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageDeletionService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Quality\QualityItemService;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Deleting a process together with the Wiki pages it produced is one operation, finding A21
 * (docs/external-api-phase-0-plan.md §2f).
 *
 * The pages used to be deleted, and committed, before the process. Anything that then stopped the
 * process from going — an approval that landed in between, Wiki failing closed late, any error —
 * left the knowledge gone and the process standing. Now both halves are one transaction around a
 * locked process: whatever fails after the pages are deleted brings them back.
 *
 * The failures are injected right after Wiki has deleted the pages, which is the exact window the
 * old order left open.
 */
class QualityWikiDeletionAtomicityTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Queue::fake();
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

    public function test_the_process_and_its_pages_go_together(): void
    {
        $world = $this->world();

        $result = app(QualityItemService::class)->deleteItem((int) $world['customer']->id, $world['process'], $world['owner'], true);

        $this->assertSame(1, $result['wiki_pages_deleted']);
        $this->assertNull(QualityItem::query()->find($world['process']->id));
        $this->assertNull(EnterpriseWikiPage::query()->find($world['page']->id));
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($world['document']->id), 'the source document is never touched');
    }

    /** An approval lands after the pages are gone: the process refuses to go, and the pages come back. */
    public function test_a_process_approved_in_between_keeps_its_pages(): void
    {
        $world = $this->world();
        $this->afterWikiDeletes(function () use ($world): void {
            DB::table('quality_process_revisions')->insert([
                'customer_id' => $world['customer']->id,
                'quality_item_id' => $world['process']->id,
                'revision_number' => 1,
                'payload' => json_encode(['lanes' => [], 'nodes' => [], 'edges' => []]),
                'source' => 'manual',
                'approved_by_user_id' => $world['owner']->id,
                'approved_by_name' => $world['owner']->name,
                'approved_at' => now(),
                'created_at' => now(),
            ]);
        });

        try {
            app(QualityItemService::class)->deleteItem((int) $world['customer']->id, $world['process'], $world['owner'], true);
            $this->fail('the database refuses to delete an approved process');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNothingDeleted($world);
    }

    /** Wiki fails closed after deleting: neither half is kept, and the dialog says so. */
    public function test_wiki_failing_late_deletes_nothing_and_says_so(): void
    {
        $world = $this->world();
        $this->afterWikiDeletes(function () use ($world): never {
            throw EnterpriseWikiWithdrawalNotRepresentableException::writerRejectedVersion((int) $world['page']->id, 'test', new RuntimeException('injected'));
        });

        $this->actingAs($world['owner'])
            ->delete("/app/quality/items/{$world['process']->id}?delete_wiki_pages=1")
            ->assertSessionHas('error', __('procynia.quality.errors.wiki_page_deletion_failed'));

        $this->assertNothingDeleted($world);
    }

    /** Any other failure in the same window rolls back the same way. */
    public function test_any_failure_after_the_pages_rolls_them_back(): void
    {
        $world = $this->world();
        $this->afterWikiDeletes(function (): never {
            throw new RuntimeException('injected');
        });

        try {
            app(QualityItemService::class)->deleteItem((int) $world['customer']->id, $world['process'], $world['owner'], true);
            $this->fail('the injected failure propagates');
        } catch (RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }

        $this->assertNothingDeleted($world);
    }

    /** Wiki's own permission still decides: quality.delete does not carry wiki.delete. */
    public function test_wikis_permission_still_decides_and_nothing_moves(): void
    {
        $world = $this->world();
        $deleter = User::query()->create([
            'name' => 'Kvalitetsleder',
            'email' => 'kvalitet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $world['customer']->id,
            'is_active' => true,
        ]);
        $role = CustomerRole::query()->create(['customer_id' => $world['customer']->id, 'name' => 'Kvalitetsleder', 'is_active' => true]);
        $role->syncPermissions([CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_DELETE]);
        $deleter->customerRoles()->attach($role->id, ['customer_id' => $world['customer']->id]);

        try {
            app(QualityItemService::class)->deleteItem((int) $world['customer']->id, $world['process'], $deleter, true);
            $this->fail('Wiki refuses');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNothingDeleted($world);
    }

    /** @param  array<string, mixed>  $world */
    private function assertNothingDeleted(array $world): void
    {
        $this->assertNotNull(QualityItem::query()->find($world['process']->id), 'the process stays');
        $this->assertNotNull(EnterpriseWikiPage::query()->find($world['page']->id), 'and so do the pages it produced');
        $this->assertSame(1, QualityActivityWikiPage::query()->where('quality_item_id', $world['process']->id)->count(), 'and the provenance that says so');
    }

    /** Wiki's real deletion, followed by $then — inside whatever transaction the caller holds. */
    private function afterWikiDeletes(\Closure $then): void
    {
        $this->app->bind(EnterpriseWikiPageDeletionService::class, fn ($app) => new class($app->make(EnterpriseWikiDocumentWithdrawalService::class), $app->make(EnterpriseWikiDocumentWikiAnswerStalenessService::class), $app->make(HumanDecisionGuard::class), $app->make(CustomerPermissionService::class), $then) extends EnterpriseWikiPageDeletionService
        {
            public function __construct($withdrawal, $staleness, $guard, $permissions, private readonly \Closure $then)
            {
                parent::__construct($withdrawal, $staleness, $guard, $permissions);
            }

            public function deletePages(int $customerId, Collection $pageIds, User $actor): array
            {
                $result = parent::deletePages($customerId, $pageIds, $actor);
                ($this->then)();

                return $result;
            }
        });
    }

    /** @return array<string, mixed> a process whose activity produced one Wiki page */
    private function world(): array
    {
        $customer = $this->customer();
        $owner = $this->owner($customer);
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Avvikshåndtering',
            'status' => QualityItem::STATUS_DRAFT,
        ]);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'avviksrutine.md',
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', Str::random(16)),
            'extracted_text' => 'Avviksrutine',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'avviksrutine-'.Str::lower(Str::random(8)),
            'title' => 'Avviksrutine',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
        QualityActivityWikiPage::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'activity_key' => 'vurder',
            'enterprise_wiki_document_id' => $document->id,
            'created_by_user_id' => $owner->id,
        ]);

        return compact('customer', 'owner', 'process', 'document', 'page');
    }

    private function owner(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Sletting AS',
            'slug' => 'kvalitet-sletting-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        return $customer;
    }
}
