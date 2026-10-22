<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemEvent;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Quality\QualityAttentionService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessContextReader;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Evidence is withdrawn, never deleted — finding A29, with the part of A20 it needs
 * (docs/external-api-phase-0-plan.md §2h).
 *
 * «Trekk tilbake» leaves the row where it was, with who withdrew it and when, and writes an event
 * in the item's history. Withdrawn evidence no longer counts — in the register, in Trenger
 * oppmerksomhet, in the other modules — but stays on the control as history, keeps the control
 * from being deleted, and cannot be changed, deleted or cascaded away. The events cannot be either.
 */
class QualityEvidenceWithdrawalTest extends TestCase
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

    /** The whole act, through the page: withdrawn, recorded, shown apart, still protecting the control. */
    public function test_withdrawing_evidence_keeps_it_as_history_with_who_and_when(): void
    {
        $world = $this->world();
        $editor = $world['editor'];

        $this->actingAs($editor)->post("/app/quality/items/{$world['control']->id}/evidence", [
            'title' => 'Signert kontrollskjema Q1',
            'description' => 'Signert av kvalitetsleder.',
            'enterprise_wiki_document_id' => $world['document']->id,
        ])->assertSessionHasNoErrors();
        $evidence = QualityItemDocument::query()->where('quality_item_id', $world['control']->id)->sole();

        $this->actingAs($editor)->delete("/app/quality/document-links/{$evidence->id}")
            ->assertSessionHas('success', __('procynia.quality.flash.evidence_withdrawn'));

        $evidence->refresh();
        $this->assertTrue($evidence->isWithdrawn());
        $this->assertSame((int) $editor->id, (int) $evidence->withdrawn_by_user_id);
        $this->assertSame($editor->name, $evidence->withdrawn_by_name);
        $this->assertSame((int) $world['document']->id, (int) $evidence->enterprise_wiki_document_id, 'the file stays linked');

        // Two events, in order, under the person who did each.
        $events = QualityItemEvent::query()->where('quality_item_id', $world['control']->id)->orderBy('id')->get();
        $this->assertSame([QualityItemEvent::EVIDENCE_REGISTERED, QualityItemEvent::EVIDENCE_WITHDRAWN], $events->pluck('event')->all());
        $this->assertSame([(int) $editor->id, (int) $editor->id], $events->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('Signert kontrollskjema Q1', $events->last()->metadata['title']);
        $this->assertSame((int) $evidence->id, (int) $events->last()->quality_item_document_id);

        // The page shows it apart, as withdrawn, with who and when.
        $props = $this->actingAs($editor)->get("/app/quality/items/{$world['control']->id}")->viewData('page')['props'];
        $row = collect($props['control_evidence'])->sole();
        $this->assertTrue($row['withdrawn']);
        $this->assertSame($editor->name, $row['withdrawn_by']);
        $this->assertSame(now()->toDateString(), $row['withdrawn_at']);

        // It no longer counts, and still protects the control from being deleted.
        $register = $this->actingAs($editor)->get('/app/quality?tab=controls')->viewData('page')['props']['control_register'];
        $this->assertSame(0, $register[$world['control']->id]['evidence_count']);
        $this->assertSame('procynia.quality.errors.control_with_history_not_deletable', app(QualityItemService::class)->deletionBlocker($world['control']));
    }

    public function test_a_withdrawal_is_final(): void
    {
        $world = $this->world();
        $evidence = app(QualityItemService::class)->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema', null, null, $world['editor']);
        app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $evidence, $world['editor']);

        $this->expectException(ValidationException::class);
        app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $evidence->fresh(), $world['editor']);
    }

    /** The same file can be registered as evidence again once its earlier registration is withdrawn. */
    public function test_the_same_file_can_be_registered_again_after_a_withdrawal(): void
    {
        $world = $this->world();
        $service = app(QualityItemService::class);
        $first = $service->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema', null, $world['document'], $world['editor']);
        $service->unlinkDocument((int) $world['customer']->id, $first, $world['editor']);

        $second = $service->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema på nytt', null, $world['document'], $world['editor']);

        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertSame(2, QualityItemDocument::query()->where('quality_item_id', $world['control']->id)->count());
        $this->assertSame(1, QualityItemDocument::query()->where('quality_item_id', $world['control']->id)->whereNull('withdrawn_at')->count());
    }

    /** What other modules and the attention list see is the evidence in force. */
    public function test_withdrawn_evidence_no_longer_counts_anywhere_else(): void
    {
        $world = $this->world();
        $world['control']->forceFill(['status' => QualityItem::STATUS_ACTIVE])->save();
        $evidence = app(QualityItemService::class)->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema', null, null, $world['editor']);

        $this->assertNotContains((int) $world['control']->id, $this->attention($world['customer']));
        $this->assertCount(1, app(QualityProcessContextReader::class)->controlEvidence((int) $world['customer']->id, [(int) $world['control']->id])[(int) $world['control']->id] ?? []);

        app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $evidence, $world['editor']);

        $this->assertContains((int) $world['control']->id, $this->attention($world['customer']));
        $this->assertSame([], app(QualityProcessContextReader::class)->controlEvidence((int) $world['customer']->id, [(int) $world['control']->id])[(int) $world['control']->id] ?? []);
    }

    /** Neither SQL nor a cascade removes evidence, and a withdrawal cannot be undone or rewritten. */
    public function test_the_database_keeps_evidence_and_its_history(): void
    {
        $world = $this->world();
        $service = app(QualityItemService::class);
        $evidence = $service->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema', 'Notat', $world['document'], $world['editor']);
        $service->unlinkDocument((int) $world['customer']->id, $evidence, $world['editor']);
        $event = QualityItemEvent::query()->where('quality_item_document_id', $evidence->id)->latest('id')->firstOrFail();

        $rows = DB::table('quality_item_documents')->where('id', $evidence->id);
        $this->assertRefused(fn () => (clone $rows)->delete(), 'deleting evidence');
        $this->assertRefused(fn () => (clone $rows)->update(['withdrawn_at' => null, 'withdrawn_by_name' => null, 'withdrawn_by_user_id' => null]), 'undoing a withdrawal');
        $this->assertRefused(fn () => (clone $rows)->update(['withdrawn_by_name' => 'Noen andre']), 'rewriting who withdrew');
        $this->assertRefused(fn () => (clone $rows)->update(['title' => 'Omskrevet']), 'rewriting the evidence');
        $this->assertRefused(fn () => (clone $rows)->update(['relation_type' => 'attachment']), 'turning evidence into something else');
        $this->assertRefused(fn () => DB::table('quality_items')->where('id', $world['control']->id)->delete(), 'deleting the control by cascade');
        $this->assertRefused(fn () => DB::table('quality_item_events')->where('id', $event->id)->update(['event' => 'x']), 'rewriting an event');
        $this->assertRefused(fn () => DB::table('quality_item_events')->where('id', $event->id)->delete(), 'deleting an event');

        // A deleted file still lets go of the evidence, which stays (the existing rule).
        QualityItemDocument::releaseEvidenceFromDocument($world['document']);
        $this->assertNull($evidence->fresh()->enterprise_wiki_document_id);
        $this->assertTrue($evidence->fresh()->isWithdrawn());
    }

    /** Evidence on any item, not only a control, is withdrawn rather than removed and keeps it. */
    public function test_evidence_on_a_process_is_withdrawn_and_keeps_the_process(): void
    {
        $world = $this->world();
        $process = QualityItem::query()->create(['customer_id' => $world['customer']->id, 'quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Innkjøp', 'status' => QualityItem::STATUS_DRAFT]);
        $link = app(QualityItemService::class)->linkDocument((int) $world['customer']->id, $process, $world['document'], QualityItemDocument::RELATION_TYPE_EVIDENCE, null, $world['editor']);
        $this->assertSame(1, QualityItemEvent::query()->where('quality_item_id', $process->id)->where('event', QualityItemEvent::EVIDENCE_REGISTERED)->count());

        app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $link, $world['editor']);
        $this->assertTrue($link->fresh()->isWithdrawn());
        $this->assertSame('procynia.quality.errors.item_with_evidence_not_deletable', app(QualityItemService::class)->deletionBlocker($process));

        // Other capacities are links and go as before.
        $source = app(QualityItemService::class)->linkDocument((int) $world['customer']->id, $process, $world['document'], QualityItemDocument::RELATION_TYPE_SOURCE, null, $world['editor']);
        app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $source, $world['editor']);
        $this->assertNull(QualityItemDocument::query()->find($source->id));
    }

    /** A reader may not withdraw; another customer cannot reach the evidence at all. */
    public function test_only_an_editor_in_the_customer_withdraws(): void
    {
        $world = $this->world();
        $evidence = app(QualityItemService::class)->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema', null, null, $world['editor']);
        $reader = $this->member($world['customer'], [CustomerPermissionCatalog::QUALITY_VIEW]);
        $other = $this->customer();
        $outsider = $this->member($other, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($reader)->delete("/app/quality/document-links/{$evidence->id}")->assertForbidden();
        $this->actingAs($outsider)->delete("/app/quality/document-links/{$evidence->id}")->assertNotFound();

        try {
            app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $evidence, $outsider);
            $this->fail('another customer is refused by the service');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }

        $this->assertFalse($evidence->fresh()->isWithdrawn());
        $this->assertSame(0, QualityItemEvent::query()->where('event', QualityItemEvent::EVIDENCE_WITHDRAWN)->count());
    }

    public function test_deleting_the_customer_takes_evidence_and_events_along(): void
    {
        $world = $this->world();
        $evidence = app(QualityItemService::class)->addControlEvidence((int) $world['customer']->id, $world['control'], 'Skjema', null, null, $world['editor']);
        app(QualityItemService::class)->unlinkDocument((int) $world['customer']->id, $evidence, $world['editor']);

        DB::table('customers')->where('id', $world['customer']->id)->delete();

        $this->assertNull(QualityItemDocument::query()->find($evidence->id));
        $this->assertSame(0, QualityItemEvent::query()->where('customer_id', $world['customer']->id)->count());
    }

    /** @return list<int> */
    private function attention(Customer $customer): array
    {
        $finding = collect(app(QualityAttentionService::class)->findings((int) $customer->id))->firstWhere('key', QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE);

        return array_map('intval', array_column($finding['items'] ?? [], 'id'));
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("the database must refuse {$what}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_CREATE, CustomerPermissionCatalog::QUALITY_EDIT]);
        $control = app(QualityItemService::class)->createItem((int) $customer->id, ['quality_type' => QualityItem::TYPE_CONTROL, 'title' => 'Fire øyne'], $editor);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kontrollskjema.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', Str::random(16)),
            'extracted_text' => 'Kontrollskjema',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return compact('customer', 'editor', 'control', 'document');
    }

    /** @param  list<string>  $keys */
    private function member(Customer $customer, array $keys): User
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
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions($keys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Kvalitet Evidens AS',
            'slug' => 'kvalitet-evidens-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        return $customer;
    }
}
