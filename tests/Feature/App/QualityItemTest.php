<?php

namespace Tests\Feature\App;

use App\Jobs\Quality\ProjectQualityItemToGraph;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityChecklistItem;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\QualityProcessIo;
use App\Models\QualityProcessStep;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Quality as its own domain.
 *
 * What these tests defend, in the order the rebuild set out to fix things:
 *
 *  - A styrende dokument exists on its own terms. It is created, owned and reviewed without any
 *    Wiki page being involved, and it is never a Wiki page wearing a type.
 *  - Wiki is reached into, not taken over: attaching a page changes nothing about the page, and the
 *    same page may back several documents or none.
 *  - A relation may only join a pair of types the matrix allows — including the case independent
 *    from/to lists got wrong, process -> work instruction.
 *  - Structure belongs to the type that has it.
 *  - Kvalitet is a paid module, and the gate is the backend's, not the rail's.
 *  - Nothing crosses a customer boundary.
 */
class QualityItemTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    // ---------------------------------------------------------------------
    // Entitlement
    // ---------------------------------------------------------------------

    public function test_quality_is_refused_to_a_customer_without_the_module(): void
    {
        ['owner' => $owner] = $this->context(grantQuality: false);

        $this->actingAs($owner)
            ->get('/app/quality')
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_the_write_actions_are_gated_by_the_same_entitlement_as_the_page(): void
    {
        ['owner' => $owner] = $this->context(grantQuality: false);

        // The guard is applied to the route group by name prefix, so a POST is refused exactly as
        // the GET is — a bookmarked form must not be a way past the commercial boundary.
        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_POLICY,
                'title' => 'Innkjopspolicy',
            ])
            ->assertRedirect(route('app.dashboard'));

        $this->assertSame(0, QualityItem::query()->count());
    }

    public function test_the_grc_package_opens_quality_too(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context(grantQuality: false);
        $this->grant($customer, 'grc');

        $this->actingAs($owner)->get('/app/quality')->assertOk();
    }

    // ---------------------------------------------------------------------
    // The item itself
    // ---------------------------------------------------------------------

    public function test_a_quality_item_is_created_without_any_wiki_page(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_POLICY,
                'title' => 'Innkjopspolicy',
                'code' => 'POL-01',
                'purpose' => 'Setter rammene for alle anskaffelser.',
                'owner_user_id' => $owner->id,
                'status' => QualityItem::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $item = QualityItem::query()->where('customer_id', $customer->id)->sole();

        // The whole premise of the rebuild: a policy exists because the organisation has one, not
        // because somebody wrote a Wiki page about it.
        $this->assertSame(QualityItem::TYPE_POLICY, $item->quality_type);
        $this->assertSame('POL-01', $item->code);
        $this->assertSame($owner->id, $item->owner_user_id);
        $this->assertSame(0, QualityItemWikiLink::query()->where('quality_item_id', $item->id)->count());

        Queue::assertPushed(ProjectQualityItemToGraph::class);
    }

    public function test_a_document_number_identifies_one_document_per_customer(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy', 'POL-01');

        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_PROCESS,
                'title' => 'Anskaffelsesprosess',
                'code' => 'pol-01',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, QualityItem::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_next_review_date_is_derived_rather_than_typed(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $item = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->patch("/app/quality/items/{$item->id}", [
                'review_interval_months' => 12,
                'last_reviewed_at' => '2026-01-15',
            ])
            ->assertRedirect();

        $item->refresh();

        // A date somebody has to remember to move is a date that is wrong.
        $this->assertSame('2027-01-15', $item->next_review_at?->toDateString());
        $this->assertSame($owner->id, $item->last_reviewed_by_user_id);
    }

    public function test_an_items_type_cannot_be_changed(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $item = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->patch("/app/quality/items/{$item->id}", [
                'title' => 'Anskaffelsesprosess',
                'quality_type' => QualityItem::TYPE_CHECKLIST,
            ]);

        // Retyping could only mean "delete the structure and keep the title", which the caller can
        // do explicitly and should have to.
        $this->assertSame(QualityItem::TYPE_PROCESS, $item->refresh()->quality_type);
    }

    public function test_an_owner_must_belong_to_the_same_customer(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['owner' => $foreignOwner] = $this->context();

        $item = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->patch("/app/quality/items/{$item->id}", ['owner_user_id' => $foreignOwner->id])
            ->assertSessionHasErrors('owner_user_id');

        $this->assertNull($item->refresh()->owner_user_id);
    }

    public function test_another_customers_item_is_not_found(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $foreignItem = $this->item($otherCustomer, QualityItem::TYPE_POLICY, 'Fremmed policy');

        $this->actingAs($owner)->get("/app/quality/items/{$foreignItem->id}")->assertNotFound();
        $this->actingAs($owner)->patch("/app/quality/items/{$foreignItem->id}", ['title' => 'Kapret'])->assertNotFound();
        $this->actingAs($owner)->delete("/app/quality/items/{$foreignItem->id}")->assertNotFound();

        $this->assertSame('Fremmed policy', $foreignItem->refresh()->title);
    }

    // ---------------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------------

    public function test_a_process_owns_its_steps_inputs_and_outputs(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/structure", [
                'steps' => [
                    ['title' => 'Behovsvurdering', 'responsibility' => 'Innkjopsansvarlig'],
                    ['title' => 'Markedsdialog', 'description' => 'Kartlegg tilbydere.'],
                    // Blank rows are dropped rather than stored empty, which is what makes
                    // "remove a step" a client-side splice.
                    ['title' => '   '],
                ],
                'inputs' => [['label' => 'Godkjent budsjett']],
                'outputs' => [['label' => 'Signert kontrakt']],
            ])
            ->assertRedirect();

        $steps = QualityProcessStep::query()->where('quality_item_id', $process->id)->orderBy('position')->get();

        $this->assertCount(2, $steps);
        $this->assertSame([1, 2], $steps->pluck('position')->map(fn ($p): int => (int) $p)->all());
        $this->assertSame('Innkjopsansvarlig', $steps[0]->responsibility);

        $this->assertSame(
            ['Godkjent budsjett'],
            QualityProcessIo::query()
                ->where('quality_item_id', $process->id)
                ->where('direction', QualityProcessIo::DIRECTION_INPUT)
                ->pluck('label')
                ->all(),
        );
        $this->assertSame(
            ['Signert kontrakt'],
            QualityProcessIo::query()
                ->where('quality_item_id', $process->id)
                ->where('direction', QualityProcessIo::DIRECTION_OUTPUT)
                ->pluck('label')
                ->all(),
        );
    }

    public function test_structure_is_replaced_as_a_set_so_positions_stay_contiguous(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/structure", [
            'steps' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']],
        ]);

        // The middle step is gone and the order has changed — exactly what a reorder looks like
        // from the client, and what row-by-row updates could not express without violating the
        // unique index on (item, position) halfway through.
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/structure", [
            'steps' => [['title' => 'C'], ['title' => 'A']],
        ]);

        $steps = QualityProcessStep::query()->where('quality_item_id', $process->id)->orderBy('position')->get();

        $this->assertSame(['C', 'A'], $steps->pluck('title')->all());
        $this->assertSame([1, 2], $steps->pluck('position')->map(fn ($p): int => (int) $p)->all());
    }

    public function test_a_checklist_owns_its_items(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $checklist = $this->item($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste tilbud');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$checklist->id}/structure", [
                'checklist_items' => [
                    ['text' => 'Signatur er innhentet', 'is_required' => true],
                    ['text' => 'Referanser er vurdert', 'is_required' => false],
                ],
            ])
            ->assertRedirect();

        $items = QualityChecklistItem::query()->where('quality_item_id', $checklist->id)->orderBy('position')->get();

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->is_required);
        $this->assertFalse($items[1]->is_required);
    }

    public function test_a_control_gets_its_detail_row_from_the_moment_it_exists(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->actingAs($owner)->post('/app/quality/items', [
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => 'Stikkprove innkjop',
        ]);

        $control = QualityItem::query()->where('customer_id', $customer->id)->sole();

        // Present but empty, so a later edit updates a row rather than discovering one is missing.
        $this->assertNotNull(QualityControlDetail::query()->where('quality_item_id', $control->id)->first());

        $this->actingAs($owner)
            ->put("/app/quality/items/{$control->id}/structure", [
                'control' => [
                    'criterion' => 'Alle kjop over 100 000 har dokumentert konkurranse.',
                    'responsibility' => 'Controller',
                    'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
                ],
            ])
            ->assertRedirect();

        $detail = QualityControlDetail::query()->where('quality_item_id', $control->id)->sole();

        $this->assertSame('Controller', $detail->responsibility);
        $this->assertSame(QualityControlDetail::FREQUENCY_QUARTERLY, $detail->frequency);
    }

    public function test_structure_belongs_to_the_type_that_has_it(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$policy->id}/structure", [
                'steps' => [['title' => 'Et steg']],
            ])
            ->assertSessionHasErrors('steps');

        $this->assertSame(0, QualityProcessStep::query()->where('quality_item_id', $policy->id)->count());
    }

    // ---------------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------------

    public function test_a_legal_relation_is_stored_once_in_the_direction_it_is_true_in(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $policy->id,
                'to_item_id' => $process->id,
                'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            ])
            ->assertRedirect();

        // One row, not a mirrored pair: "a policy governs a process" is not true in reverse.
        $this->assertSame(1, QualityItemRelation::query()->where('customer_id', $customer->id)->count());

        $relation = QualityItemRelation::query()->sole();
        $this->assertSame($policy->id, (int) $relation->from_item_id);
        $this->assertSame($process->id, (int) $relation->to_item_id);
    }

    public function test_a_relation_the_matrix_forbids_is_refused(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $instruction = $this->item($customer, QualityItem::TYPE_WORK_INSTRUCTION, 'Signering i ERP');

        // The case ordered pairs exist for: `uses` is legal procedure -> work instruction and
        // process -> checklist, but a process reaches an instruction through its procedure.
        // Independent from/to lists would have allowed this.
        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $process->id,
                'to_item_id' => $instruction->id,
                'relation_type' => QualityItemRelation::TYPE_USES,
            ])
            ->assertSessionHasErrors('relation_type');

        $this->assertSame(0, QualityItemRelation::query()->count());
    }

    public function test_a_relation_cannot_reach_another_customers_item(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $foreignProcess = $this->item($otherCustomer, QualityItem::TYPE_PROCESS, 'Fremmed prosess');

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $policy->id,
                'to_item_id' => $foreignProcess->id,
                'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            ])
            ->assertNotFound();

        $this->assertSame(0, QualityItemRelation::query()->count());
    }

    // ---------------------------------------------------------------------
    // The seam to Wiki
    // ---------------------------------------------------------------------

    public function test_attaching_a_wiki_page_changes_nothing_about_the_page(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');

        // The raw row, not the model's attribute array: a freshly-created model and a reloaded one
        // differ in key order and in which columns were hydrated, neither of which is a change to
        // the page.
        $before = (array) DB::table('enterprise_wiki_pages')->where('id', $page->id)->sole();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/wiki-links", [
                'enterprise_wiki_page_id' => $page->id,
                'link_type' => QualityItemWikiLink::LINK_TYPE_DOCUMENTS,
            ])
            ->assertRedirect();

        $this->assertSame(1, QualityItemWikiLink::query()->where('quality_item_id', $process->id)->count());

        // The retired model wrote a type onto the page. Nothing may now.
        $this->assertSame($before, (array) DB::table('enterprise_wiki_pages')->where('id', $page->id)->sole());
    }

    public function test_one_wiki_page_may_back_several_quality_items(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');

        foreach ([$policy, $process] as $item) {
            $this->actingAs($owner)
                ->post("/app/quality/items/{$item->id}/wiki-links", [
                    'enterprise_wiki_page_id' => $page->id,
                ])
                ->assertRedirect();
        }

        // Impossible under the retired model, where the link was a unique column on the page.
        $this->assertSame(2, QualityItemWikiLink::query()->where('enterprise_wiki_page_id', $page->id)->count());
    }

    public function test_a_wiki_page_from_another_customer_cannot_be_attached(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $foreignPage = $this->page($otherCustomer, 'Fremmed side');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/wiki-links", [
                'enterprise_wiki_page_id' => $foreignPage->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, QualityItemWikiLink::query()->count());
    }

    public function test_deleting_an_item_leaves_the_wiki_page_standing(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/wiki-links", [
            'enterprise_wiki_page_id' => $page->id,
        ]);

        $this->actingAs($owner)->delete("/app/quality/items/{$process->id}")->assertRedirect();

        $this->assertNull(QualityItem::query()->find($process->id));
        $this->assertSame(0, QualityItemWikiLink::query()->where('quality_item_id', $process->id)->count());
        $this->assertNotNull(EnterpriseWikiPage::query()->find($page->id));
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * @return array{customer: Customer, owner: User}
     */
    private function context(bool $grantQuality = true): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Test AS',
            'slug' => 'kvalitet-test-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'owner-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        if ($grantQuality) {
            $this->grant($customer, 'quality');
        }

        return ['customer' => $customer, 'owner' => $owner];
    }

    private function grant(Customer $customer, string $package): void
    {
        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => $package],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );
    }

    private function item(Customer $customer, string $type, string $title, ?string $code = null): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'code' => $code,
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
}
