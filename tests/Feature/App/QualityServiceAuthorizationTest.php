<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityTool;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Quality\QualityActivityArticleService;
use App\Services\Quality\QualityActivityControlService;
use App\Services\Quality\QualityFlowClarificationService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Services\Quality\QualityToolService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\QualityFlowVersion;
use Tests\TestCase;

/**
 * Every ordinary write in Kvalitet checks its actor in the service itself, phase 0 step 12
 * (docs/external-api-phase-0-plan.md §2f): the key the controller already asks for, in the actor's
 * own customer, with the Kvalitet module. A direct call — a job, a future API operation, another
 * module — cannot write past it, and a refused call writes nothing.
 *
 * The keys are the ones the pages already use, so nobody's workflow changes: quality.create
 * registers a document, quality.edit changes one (including registering a control from an
 * activity), and attaching a file belongs to both.
 */
class QualityServiceAuthorizationTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EDITOR = [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT];

    private const CREATOR = [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_CREATE];

    private const EVERYTHING = [
        CustomerPermissionCatalog::QUALITY_VIEW,
        CustomerPermissionCatalog::QUALITY_CREATE,
        CustomerPermissionCatalog::QUALITY_EDIT,
        CustomerPermissionCatalog::QUALITY_APPROVE,
        CustomerPermissionCatalog::QUALITY_DELETE,
    ];

    /** Every write that quality.edit governs. */
    private const EDIT_WRITES = [
        'update_item', 'replace_steps', 'replace_io', 'replace_checklist', 'update_control', 'relate', 'unrelate',
        'link_wiki_page', 'unlink_wiki_page', 'add_evidence', 'unlink_document', 'add_activity_control',
        'create_activity_control', 'place_controls', 'remove_activity_control', 'register_tool', 'link_tool',
        'store_flow', 'dismiss_clarification', 'resolve_clarification',
    ];

    /** Every write that quality.create governs. */
    private const CREATE_WRITES = ['create_item', 'draft_article', 'create_article'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        // No AI call may leave the test, not even from a write that wrongly got through.
        Http::preventStrayRequests();
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

    public function test_a_reader_writes_nothing_through_any_service(): void
    {
        $world = $this->world();
        $reader = $this->member($world['customer'], [CustomerPermissionCatalog::QUALITY_VIEW]);

        $this->assertRefused($world, $reader, [...self::EDIT_WRITES, ...self::CREATE_WRITES, 'link_document']);
    }

    /** Customer separation: every key in their own customer is nothing in another's. */
    public function test_another_customers_editor_writes_nothing(): void
    {
        $world = $this->world();
        $other = $this->customer();
        $outsider = $this->member($other, self::EVERYTHING);

        $this->assertRefused($world, $outsider, [...self::EDIT_WRITES, ...self::CREATE_WRITES, 'link_document']);
    }

    public function test_without_the_kvalitet_module_the_keys_write_nothing(): void
    {
        $world = $this->world();
        $editor = $this->member($world['customer'], self::EVERYTHING);
        $world['customer']->packageEntitlements()->delete();

        $this->assertRefused($world, $editor->fresh(), [...self::EDIT_WRITES, ...self::CREATE_WRITES, 'link_document']);
    }

    /** Each key opens exactly its own writes, as on the pages. */
    public function test_each_key_opens_its_own_writes_and_no_others(): void
    {
        $world = $this->world();
        $editor = $this->member($world['customer'], self::EDITOR);
        $creator = $this->member($world['customer'], self::CREATOR);

        $this->assertRefused($world, $editor, self::CREATE_WRITES, 'edit only');
        $this->assertRefused($world, $creator, self::EDIT_WRITES, 'create only');

        // Attaching a file belongs to both.
        foreach (['edit' => [$editor, QualityItemDocument::RELATION_TYPE_TEMPLATE], 'create' => [$creator, QualityItemDocument::RELATION_TYPE_ATTACHMENT]] as $who => [$person, $capacity]) {
            $link = app(QualityItemService::class)->linkDocument((int) $world['customer']->id, $world['policy'], $world['document'], $capacity, null, $person);
            $this->assertInstanceOf(QualityItemDocument::class, $link, $who);
            $this->assertSame((int) $person->id, (int) $link->created_by_user_id, $who);
        }

        // Registering a control from an activity is editing the process, as it always was.
        $control = $this->attempts($world, $editor)['create_activity_control']();
        $this->assertSame(QualityItem::TYPE_CONTROL, $control->quality_type);
        $this->assertSame((int) $editor->id, (int) $control->created_by_user_id);

        $item = $this->attempts($world, $creator)['create_item']();
        $this->assertSame((int) $creator->id, (int) $item->created_by_user_id);
    }

    /** The editor's every edit write goes through, and names them where the row has a creator. */
    public function test_an_editor_makes_every_edit(): void
    {
        $world = $this->world();
        $editor = $this->member($world['customer'], self::EDITOR);
        $attempts = $this->attempts($world, $editor);

        foreach (self::EDIT_WRITES as $write) {
            $attempts[$write]();
        }

        $this->assertSame(1, QualityItemWikiLink::query()->where('quality_item_id', $world['policy']->id)->where('created_by_user_id', $editor->id)->count());
        $this->assertSame(1, QualityTool::query()->where('customer_id', $world['customer']->id)->where('created_by_user_id', $editor->id)->count());
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  list<string>  $writes
     */
    private function assertRefused(array $world, User $actor, array $writes, string $who = ''): void
    {
        $attempts = $this->attempts($world, $actor);
        $before = $this->snapshot($world['customer']);

        foreach ($writes as $write) {
            try {
                $attempts[$write]();
                $this->fail("{$who}: {$write} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(AuthorizationException::class, $e, "{$who}: {$write} — ".$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot($world['customer']), "{$who}: nothing is written");
        Http::assertNothingSent();
    }

    /**
     * Every ordinary Kvalitet write, called directly on its service with this actor.
     *
     * @param  array<string, mixed>  $world
     * @return array<string, \Closure(): mixed>
     */
    private function attempts(array $world, User $actor): array
    {
        $customerId = (int) $world['customer']->id;
        $items = app(QualityItemService::class);
        $controls = app(QualityActivityControlService::class);
        $tools = app(QualityToolService::class);
        $articles = app(QualityActivityArticleService::class);
        $clarifications = app(QualityFlowClarificationService::class);

        return [
            'create_item' => fn () => $items->createItem($customerId, ['quality_type' => QualityItem::TYPE_POLICY, 'title' => 'Ny policy'], $actor),
            'update_item' => fn () => $items->updateItem($customerId, $world['policy'], ['title' => 'Endret policy'], $actor),
            'replace_steps' => fn () => $items->replaceProcessSteps($customerId, $world['process'], [['title' => 'Nytt steg']], $actor),
            'replace_io' => fn () => $items->replaceProcessIo($customerId, $world['process'], 'input', [['label' => 'Bestilling']], $actor),
            'replace_checklist' => fn () => $items->replaceChecklistItems($customerId, $world['checklist'], [['text' => 'Sjekk']], $actor),
            'update_control' => fn () => $items->updateControlDetail($customerId, $world['control'], ['criterion' => 'Endret kriterium'], $actor),
            'relate' => fn () => $items->relate($customerId, $world['checklist'], $world['process'], QualityItemRelation::TYPE_GOVERNS, $actor),
            'unrelate' => fn () => $items->unrelate($customerId, $world['relation'], $actor),
            'link_wiki_page' => fn () => $items->linkWikiPage($customerId, $world['policy'], $world['page'], QualityItemWikiLink::LINK_TYPE_SUPPORTS, null, $actor),
            'unlink_wiki_page' => fn () => $items->unlinkWikiPage($customerId, $world['wiki_link'], $actor),
            'link_document' => fn () => $items->linkDocument($customerId, $world['policy'], $world['document'], QualityItemDocument::RELATION_TYPE_TEMPLATE, null, $actor),
            'add_evidence' => fn () => $items->addControlEvidence($customerId, $world['control'], 'Signert skjema', null, null, $actor),
            'unlink_document' => fn () => $items->unlinkDocument($customerId, $world['document_link'], $actor),
            'create_activity_control' => fn () => $items->createActivityControl($customerId, 'Ny kontroll', 'Kriterium', $actor),
            'add_activity_control' => fn () => $controls->add($world['process'], $world['blueprint'], 'steg', 'Fire øyne', null, $actor),
            'place_controls' => fn () => $controls->place($customerId, [['process_id' => $world['process']->id, 'activity_key' => 'steg', 'control_item_id' => $world['control']->id]], $actor),
            'remove_activity_control' => fn () => $controls->remove($world['placement'], $actor),
            'register_tool' => fn () => $tools->register($customerId, $world['tool_document'], ['title' => 'Mal'], $actor),
            'link_tool' => fn () => $tools->linkToControl($customerId, $world['control'], $world['tool'], $actor),
            'store_flow' => fn () => app(QualityProcessBlueprintService::class)->store($customerId, $world['process'], $this->flow('Endret steg'), QualityProcessBlueprint::SOURCE_MANUAL, $actor, basedOn: QualityFlowVersion::of($world['process'])),
            'dismiss_clarification' => fn () => $clarifications->dismiss($world['process'], 'Hvem godkjenner?', 'Beskrivelse', $actor),
            'resolve_clarification' => fn () => $clarifications->resolve($world['process'], 'Hvem attesterer?', 'Beskrivelse', $actor),
            'draft_article' => fn () => $articles->draft($world['process'], $world['blueprint'], 'steg', 'no', $actor),
            'create_article' => fn () => $articles->create($world['process'], $world['blueprint'], 'steg', 'Artikkel', 'Tekst.', $actor),
        ];
    }

    /** @return array<string, int|string> what a refused write must leave as it was */
    private function snapshot(Customer $customer): array
    {
        $count = fn (string $table): int => DB::table($table)->where('customer_id', $customer->id)->count();

        return [
            'items' => $count('quality_items'),
            'item_state' => md5((string) json_encode(DB::table('quality_items')->where('customer_id', $customer->id)->orderBy('id')->get(['id', 'title', 'status', 'updated_at']))),
            'steps' => $count('quality_process_steps'),
            'io' => $count('quality_process_io'),
            'checklist' => $count('quality_checklist_items'),
            'control_details' => md5((string) json_encode(DB::table('quality_control_details')->where('customer_id', $customer->id)->orderBy('id')->get(['id', 'criterion']))),
            'relations' => $count('quality_item_relations'),
            'wiki_links' => $count('quality_item_wiki_links'),
            'documents' => $count('quality_item_documents'),
            'placements' => $count('quality_activity_controls'),
            'tools' => $count('quality_tools'),
            'blueprint' => md5((string) json_encode(DB::table('quality_process_blueprints')->where('customer_id', $customer->id)->orderBy('id')->get(['id', 'payload', 'status']))),
            'clarifications' => $count('quality_flow_clarification_resolutions'),
            'wiki_documents' => $count('enterprise_wiki_documents'),
        ];
    }

    /** @return array<string, mixed> one of each thing a write can touch, set up by the System Owner */
    private function world(): array
    {
        $customer = $this->customer();
        $owner = $this->owner($customer);
        $items = app(QualityItemService::class);

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Innkjøp');
        $blueprint = app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Registrer leverandøren'), QualityProcessBlueprint::SOURCE_MANUAL, $owner, basedOn: QualityFlowVersion::of($process));
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjøpspolicy');
        $checklist = $this->item($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste');
        $control = $items->createItem((int) $customer->id, ['quality_type' => QualityItem::TYPE_CONTROL, 'title' => 'Fire øyne'], $owner);
        $page = $this->page($customer, 'Innkjøpsrutine');
        $document = $this->document($customer, 'innkjop.pdf');
        $toolDocument = $this->document($customer, 'mal.docx');
        $tool = QualityTool::query()->create(['customer_id' => $customer->id, 'enterprise_wiki_document_id' => $this->document($customer, 'verktoy.xlsx')->id, 'title' => 'Verktøy']);

        return [
            'customer' => $customer,
            'process' => $process,
            'blueprint' => $blueprint,
            'policy' => $policy,
            'checklist' => $checklist,
            'control' => $control,
            'page' => $page,
            'document' => $document,
            'tool_document' => $toolDocument,
            'tool' => $tool,
            'relation' => $items->relate((int) $customer->id, $policy, $process, QualityItemRelation::TYPE_GOVERNS, $owner),
            'wiki_link' => $items->linkWikiPage((int) $customer->id, $process, $page, QualityItemWikiLink::LINK_TYPE_DOCUMENTS, null, $owner),
            'document_link' => $items->linkDocument((int) $customer->id, $process, $document, QualityItemDocument::RELATION_TYPE_SOURCE, null, $owner),
            'placement' => app(QualityActivityControlService::class)->place((int) $customer->id, [['process_id' => $process->id, 'activity_key' => 'steg', 'control_item_id' => $control->id]], $owner)[0],
        ];
    }

    /** @return array<string, mixed> */
    private function flow(string $step): array
    {
        return [
            'lanes' => [['key' => 'innkjop', 'label' => 'Innkjøper']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'innkjop', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'steg', 'lane' => 'innkjop', 'type' => 'step', 'label' => $step],
                ['key' => 'slutt', 'lane' => 'innkjop', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'steg'],
                ['from' => 'steg', 'to' => 'slutt'],
            ],
        ];
    }

    private function item(Customer $customer, string $type, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    private function page(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function document(Customer $customer, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', $filename.Str::random(8)),
            'extracted_text' => 'Innhold i '.$filename,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private function member(Customer $customer, array $permissionKeys): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kvalitet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
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
            'name' => 'Kvalitet Skriving AS',
            'slug' => 'kvalitet-skriving-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        return $customer;
    }
}
