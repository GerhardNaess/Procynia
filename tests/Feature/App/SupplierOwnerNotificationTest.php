<?php

namespace Tests\Feature\App;

use App\Models\Language;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Suppliers\SupplierNotificationService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\ReadsMyTasks;
use Tests\TestCase;

/**
 * Punkt 6C: Leverandører in the bell.
 *
 * One event — someone became intern ansvarlig for a supplier — tied to the saved change: on
 * registration and on reassignment, never on a save that leaves the owner where it was, never to the
 * person who made the choice, never twice for one handover, never when the write rolls back. The
 * notification is a message: reading or deleting it leaves the supplier's task in «Mine oppgaver»
 * exactly where it was, and once the person can no longer read the supplier, the bell stops showing
 * it.
 */
class SupplierOwnerNotificationTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use DatabaseTransactions;
    use ReadsMyTasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registering_a_supplier_tells_the_new_owner(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $this->supplierUser($customer, []);

        $this->actingAs($editor)
            ->post('/app/supplier-management', $this->supplierPayload($owner, ['name' => 'Stangeland Maskin']))
            ->assertSessionHasNoErrors();

        $supplier = Supplier::query()->where('name', 'Stangeland Maskin')->sole();
        $notification = $this->notificationsFor($owner)->sole();

        $this->assertSame(SupplierNotificationService::EVENT_OWNER_ASSIGNED, $notification->event_type);
        $this->assertSame((int) $customer->id, (int) $notification->customer_id);
        $this->assertSame('Ansvar for leverandør', $notification->title);
        $this->assertSame('Du er tildelt ansvar for leverandøren Stangeland Maskin.', $notification->message);
        $this->assertSame("/app/supplier-management/{$supplier->id}", $notification->target_url);
        $this->assertSame((int) $supplier->id, $notification->metadata['supplier_id']);
        $this->assertNull($notification->metadata['previous_owner_user_id']);
        $this->assertSame((int) $editor->id, $notification->metadata['assigned_by_user_id']);
        $this->assertSame(0, $this->notificationsFor($editor)->count());

        // In the bell, and the link opens that supplier.
        $panel = $this->bell($owner);
        $this->assertSame(1, $panel['unread_count']);
        $this->actingAs($owner)->get($panel['items'][0]['target_url'])->assertOk();
    }

    public function test_the_message_is_written_in_the_recipients_language(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $this->supplierUser($customer, []);
        $english = Language::query()->firstOrCreate(['code' => 'en'], ['name_en' => 'English', 'name_no' => 'Engelsk']);
        $owner->forceFill(['preferred_language_id' => $english->id])->save();

        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($owner, ['name' => 'Stangeland Maskin']));

        $notification = $this->notificationsFor($owner)->sole();
        $this->assertSame('Supplier responsibility', $notification->title);
        $this->assertSame('You have been made responsible for the supplier Stangeland Maskin.', $notification->message);
    }

    public function test_registering_with_yourself_as_owner_tells_nobody(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor))->assertSessionHasNoErrors();

        $this->assertSame(0, UserNotification::query()->where('customer_id', $customer->id)->count());
    }

    public function test_reassigning_tells_the_new_owner_and_not_the_old_one(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $first = $this->supplierUser($customer, []);
        $second = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $first, 'Drift AS');

        $this->actingAs($editor)
            ->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($second, ['name' => 'Drift AS']))
            ->assertSessionHasNoErrors();

        $notification = $this->notificationsFor($second)->sole();
        $this->assertSame((int) $first->id, $notification->metadata['previous_owner_user_id']);
        $this->assertSame(0, $this->notificationsFor($first)->count());
    }

    public function test_saving_without_changing_the_owner_tells_nobody(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $owner, 'Drift AS');

        foreach (['Drift AS', 'Drift og Vedlikehold AS', 'Drift og Vedlikehold AS'] as $name) {
            $this->actingAs($editor)
                ->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($owner, ['name' => $name]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(0, $this->notificationsFor($owner)->count());
    }

    public function test_reassigning_to_yourself_tells_nobody(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $other = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $other, 'Drift AS');

        $this->actingAs($editor)
            ->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($editor, ['name' => 'Drift AS']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, UserNotification::query()->where('customer_id', $customer->id)->count());
    }

    public function test_one_handover_is_one_notification_and_a_later_one_is_heard_again(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $first = $this->supplierUser($customer, []);
        $second = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $second, 'Drift AS');
        $service = app(SupplierNotificationService::class);

        $supplier->forceFill(['owner_user_id' => $first->id])->save();
        // The same handover reported twice — a retry, a double submit.
        $service->ownerAssigned($supplier, (int) $second->id, $editor);
        $service->ownerAssigned($supplier, (int) $second->id, $editor);
        $this->assertSame(1, $this->notificationsFor($first)->count());

        // Away and back is two real handovers.
        Carbon::setTestNow('2026-10-08 09:00:00');
        $this->actingAs($editor)->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($second, ['name' => 'Drift AS']));
        Carbon::setTestNow('2026-10-09 09:00:00');
        $this->actingAs($editor)->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($first, ['name' => 'Drift AS']));

        $this->assertSame(2, $this->notificationsFor($first)->count());
        $this->assertSame(1, $this->notificationsFor($second)->count());
    }

    public function test_a_change_that_rolls_back_is_never_announced(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');

        try {
            DB::transaction(function () use ($supplier, $owner, $editor): void {
                $supplier->forceFill(['owner_user_id' => $owner->id])->save();
                app(SupplierNotificationService::class)->ownerAssigned($supplier, (int) $editor->id, $editor);

                throw new RuntimeException('the save failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame((int) $editor->id, (int) $supplier->fresh()->owner_user_id);
        $this->assertSame(0, $this->notificationsFor($owner)->count());
    }

    public function test_an_inactive_owner_or_one_who_cannot_read_suppliers_is_not_told(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $inactive = $this->supplierUser($customer, []);
        $inactive->forceFill(['is_active' => false])->save();
        $withoutView = $this->member($customer);
        $service = app(SupplierNotificationService::class);

        foreach ([$inactive, $withoutView] as $recipient) {
            $supplier = $this->supplier($customer, $editor, 'Drift '.$recipient->id);
            $supplier->forceFill(['owner_user_id' => $recipient->id])->save();
            $service->ownerAssigned($supplier, (int) $editor->id, $editor);

            $this->assertSame(0, $this->notificationsFor($recipient)->count());
        }
    }

    public function test_a_notification_never_crosses_a_customer_boundary(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $outsider = $this->supplierUser($other, []);

        // The form refuses another customer's user outright…
        $this->actingAs($editor)
            ->post('/app/supplier-management', $this->supplierPayload($outsider))
            ->assertSessionHasErrors('owner_user_id');

        // …and a supplier made to point across anyway still tells nobody.
        $supplier = $this->supplier($customer, $editor, 'Drift AS');
        Supplier::query()->whereKey($supplier->id)->update(['owner_user_id' => $outsider->id]);
        app(SupplierNotificationService::class)->ownerAssigned($supplier->fresh(), (int) $editor->id, $editor);

        $this->assertSame(0, UserNotification::query()->where('user_id', $outsider->id)->count());
    }

    public function test_losing_access_hides_the_notification_and_the_link_stays_guarded(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $this->supplierUser($customer, []);
        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($owner, ['name' => 'Stangeland Maskin']));
        $notification = $this->notificationsFor($owner)->sole();
        $this->assertSame(1, $this->bell($owner)['unread_count']);

        $owner->customerRoles()->detach();
        $owner = $owner->fresh();

        $panel = $this->bell($owner);
        $this->assertSame(0, $panel['unread_count']);
        $this->assertSame([], $panel['items']);
        $this->assertStringNotContainsString('Stangeland Maskin', json_encode($panel));
        // The link was never the authorization.
        $this->actingAs($owner)->get($notification->target_url)->assertForbidden();
    }

    public function test_a_deleted_supplier_leaves_no_name_in_the_bell(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $owner = $this->supplierUser($customer, []);
        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($owner, ['name' => 'Feilregistrert AS']));
        $supplier = Supplier::query()->where('name', 'Feilregistrert AS')->sole();

        $this->actingAs($editor)->delete("/app/supplier-management/{$supplier->id}")->assertRedirect();

        $this->assertSame(0, $this->bell($owner)['unread_count']);
    }

    public function test_a_malformed_supplier_reference_hides_one_row_and_never_breaks_the_bell(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, []);

        foreach ([['supplier_id' => 'ikke-et-tall'], []] as $metadata) {
            UserNotification::query()->create([
                'customer_id' => $customer->id, 'user_id' => $owner->id,
                'event_type' => SupplierNotificationService::EVENT_OWNER_ASSIGNED, 'dedupe_key' => 'malformed:'.count($metadata),
                'severity' => UserNotification::SEVERITY_INFO, 'title' => 'Ansvar for leverandør', 'message' => 'Ukjent',
                'target_url' => '/app/supplier-management', 'metadata' => $metadata,
            ]);
        }

        $this->assertSame(0, $this->bell($owner)['unread_count']);
    }

    public function test_reading_or_deleting_the_notification_leaves_the_task(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $this->supplierUser($customer, []);
        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($owner, [
            'name' => 'Drift AS',
            ...$this->classification(Supplier::CRITICALITY_CRITICAL, 12),
        ]));
        $supplier = Supplier::query()->where('name', 'Drift AS')->sole();
        SupplierDocument::query()->create([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id,
            'document_type' => 'insurance_certificate', 'title' => 'Forsikring', 'valid_until' => '2026-10-06',
        ]);
        $notification = $this->notificationsFor($owner)->sole();

        $this->assertSame(1, $this->bell($owner)['unread_count']);
        $this->actingAs($owner)->patch(route('app.notifications.read', ['userNotification' => $notification->id]))->assertOk();
        $this->assertSame(0, $this->bell($owner)['unread_count']);
        $this->assertTrue((bool) $notification->fresh()->is_read);
        $this->assertCount(1, $this->supplierTasksFor($owner));

        $this->actingAs($owner)->delete(route('app.notifications.destroy', ['userNotification' => $notification->id]))->assertOk();
        $this->assertNull($notification->fresh());
        $this->assertSame([], $this->bell($owner)['items']);
        $this->assertCount(1, $this->supplierTasksFor($owner));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function notificationsFor(User $user)
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('event_type', SupplierNotificationService::EVENT_OWNER_ASSIGNED)
            ->get();
    }

    /** @return array<string, mixed> */
    private function bell(User $user): array
    {
        return $this->actingAs($user)->getJson(route('app.notifications.index'))->assertOk()->json('notifications');
    }

    /** @return list<array<string, mixed>> */
    private function supplierTasksFor(User $user): array
    {
        return $this->myTasksIn(
            $this->actingAs($user)->get(route('app.info-center.index'))->assertOk()->viewData('page')['props']['infoCenter'],
            'supplier',
        );
    }
}
