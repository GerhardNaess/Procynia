<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditStatusChange;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Support\CustomerPermissionCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → Revisjoner, from database to page: the audit itself, its lifecycle and
 * who may do what.
 *
 * What these tests defend:
 *
 *  - Access is customer-wide and comes only from the customer's own roles: compliance.view reads,
 *    compliance.audit — not compliance.edit — registers, changes and runs audits, compliance.delete
 *    deletes one registered by mistake. System Owner holds none of it without a role of their own.
 *  - Another customer's audit is undiscoverable — not listed, searched or counted, and a 404 by URL
 *    for read and for every write.
 *  - The responsible person is an active person of the same customer who can read compliance.
 *  - Scope is required; planned end is required and never before the start.
 *  - Status is never a form field. Only the five transitions exist; Avbryt and Gjenåpne need a
 *    reason, Fullfør needs a conclusion, each writes one immutable history row.
 *  - What can be edited follows the status; a completed audit is locked until reopened, a cancelled
 *    one for good. Only a planned audit that never moved can be deleted.
 *  - The database holds the same lines: CHECKs, the conclusion rule, and the history trigger.
 */
class ComplianceAuditTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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
    // Registering
    // ---------------------------------------------------------------------

    public function test_an_auditor_registers_an_internal_audit_planned_and_without_history(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);

        $this->actingAs($auditor)->post('/app/compliance/audits', $this->auditPayload($auditor) + ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $audit = ComplianceAudit::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(ComplianceAudit::TYPE_INTERNAL, $audit->audit_type);
        // Status is not a form field: every audit starts planned.
        $this->assertSame(ComplianceAudit::STATUS_PLANNED, $audit->status);
        $this->assertSame((int) $auditor->id, (int) $audit->responsible_user_id);
        $this->assertSame('2026-11-01', $audit->planned_start_date->toDateString());
        $this->assertSame('2026-11-15', $audit->planned_end_date->toDateString());
        $this->assertNull($audit->auditor_name);
        $this->assertNull($audit->conclusion);
        $this->assertSame(0, $audit->statusChanges()->count());

        $props = $this->props($auditor, $audit);
        $this->assertSame('Intern revisjon av tilgangsstyring og brukeradministrasjon for IT-avdelingen.', $props['audit']['scope_description']);
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertTrue($props['permissions']['can_start']);
        $this->assertFalse($props['permissions']['can_complete']);
        $this->assertFalse($props['permissions']['can_reopen']);
        $this->assertSame([], $props['status_history']);
    }

    public function test_an_external_audit_carries_the_auditor_name(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);

        $this->actingAs($auditor)->post('/app/compliance/audits', [
            'auditor_name' => '  Sertifiseringsorganet AS  ',
        ] + $this->auditPayload($auditor, 'ISO 27001 sertifiseringsrevisjon', ComplianceAudit::TYPE_EXTERNAL))->assertSessionHasNoErrors();

        $audit = ComplianceAudit::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(ComplianceAudit::TYPE_EXTERNAL, $audit->audit_type);
        $this->assertSame('Sertifiseringsorganet AS', $audit->auditor_name);

        $row = $this->indexProps($auditor)['audits'][0];
        $this->assertSame('external', $row['audit_type']);
        $this->assertSame('Sertifiseringsorganet AS', $row['auditor_name']);
    }

    public function test_the_responsible_person_must_be_an_active_compliance_user_of_the_same_customer(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $noAccess = $this->complianceMember($customer);
        $inactive = $this->complianceReader($customer);
        $inactive->forceFill(['is_active' => false])->save();
        $stranger = $this->complianceReader($foreign);

        foreach ([$noAccess, $inactive, $stranger] as $candidate) {
            $this->actingAs($auditor)->post('/app/compliance/audits', $this->auditPayload($candidate))
                ->assertSessionHasErrors(['responsible_user_id' => 'Velg en aktiv person med tilgang til Etterlevelse og revisjon.']);
        }

        $this->actingAs($auditor)->post('/app/compliance/audits', ['responsible_user_id' => null] + $this->auditPayload($auditor))
            ->assertSessionHasErrors(['responsible_user_id' => 'Ansvarlig må fylles ut.']);
        $this->assertSame(0, ComplianceAudit::query()->where('customer_id', $customer->id)->count());

        // The candidates offered are exactly the valid ones.
        $offered = array_column($this->indexProps($auditor)['responsible_options'], 'id');
        $this->assertContains((int) $auditor->id, $offered);
        $this->assertNotContains((int) $noAccess->id, $offered);
        $this->assertNotContains((int) $inactive->id, $offered);
        $this->assertNotContains((int) $stranger->id, $offered);
    }

    public function test_a_responsible_person_who_is_deleted_leaves_the_audit_without_one(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $responsible = $this->complianceReader($customer);
        $audit = $this->complianceAudit($customer, $responsible);

        $responsible->delete();

        $this->assertNull($audit->fresh()->responsible_user_id);
        $this->assertNull($this->indexProps($auditor)['audits'][0]['responsible_name']);
    }

    public function test_the_planned_end_is_required_and_never_before_the_start(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);

        $this->actingAs($auditor)->post('/app/compliance/audits', ['planned_start_date' => '2026-11-20'] + $this->auditPayload($auditor))
            ->assertSessionHasErrors(['planned_end_date' => 'Planlagt slutt kan ikke være før planlagt start.']);
        $this->actingAs($auditor)->post('/app/compliance/audits', ['planned_end_date' => null] + $this->auditPayload($auditor))
            ->assertSessionHasErrors(['planned_end_date' => 'Planlagt slutt må fylles ut.']);
        $this->actingAs($auditor)->post('/app/compliance/audits', ['planned_end_date' => '15.11.2026'] + $this->auditPayload($auditor))
            ->assertSessionHasErrors(['planned_end_date' => 'Velg en gyldig dato for Planlagt slutt.']);
        $this->assertSame(0, ComplianceAudit::query()->where('customer_id', $customer->id)->count());

        // The same day is fine, and the start is optional.
        $this->actingAs($auditor)->post('/app/compliance/audits', ['planned_start_date' => '2026-11-15'] + $this->auditPayload($auditor, 'Samme dag'))->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post('/app/compliance/audits', ['planned_start_date' => null] + $this->auditPayload($auditor, 'Uten start'))->assertSessionHasNoErrors();
        $this->assertNull(ComplianceAudit::query()->where('title', 'Uten start')->sole()->planned_start_date);

        // And the database holds the line on its own.
        $audit = ComplianceAudit::query()->where('title', 'Samme dag')->sole();
        $this->assertRefused('an end before the start', fn () => DB::table('compliance_audits')->where('id', $audit->id)->update(['planned_start_date' => '2026-12-01']));
    }

    public function test_the_scope_description_is_required(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);

        foreach ([null, '', '   '] as $scope) {
            $this->actingAs($auditor)->post('/app/compliance/audits', ['scope_description' => $scope] + $this->auditPayload($auditor))
                ->assertSessionHasErrors(['scope_description' => 'Scope må fylles ut.']);
        }
        $this->actingAs($auditor)->post('/app/compliance/audits', ['title' => '', 'audit_type' => 'annual'] + $this->auditPayload($auditor))
            ->assertSessionHasErrors(['title' => 'Tittel må fylles ut.', 'audit_type' => 'Velg en gyldig verdi for Type.']);
        $this->assertSame(0, ComplianceAudit::query()->where('customer_id', $customer->id)->count());

        $audit = $this->complianceAudit($customer, $auditor);
        $this->assertRefused('a blank scope', fn () => DB::table('compliance_audits')->where('id', $audit->id)->update(['scope_description' => '  ']));
        $this->assertRefused('an unknown type', fn () => DB::table('compliance_audits')->where('id', $audit->id)->update(['audit_type' => 'annual']));
        $this->assertRefused('an unknown status', fn () => DB::table('compliance_audits')->where('id', $audit->id)->update(['status' => 'archived']));

        $this->expectException(DomainException::class);
        $audit->forceFill(['status' => 'archived'])->save();
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_start_moves_a_planned_audit_to_in_progress_and_logs_it(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);

        $this->actingAs($auditor)->post($this->url($audit, 'start'))->assertSessionHasNoErrors()->assertSessionHas('success', 'Revisjonen er startet.');

        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $audit->fresh()->status);
        $change = ComplianceAuditStatusChange::query()->where('audit_id', $audit->id)->sole();
        $this->assertSame(['planned', 'in_progress', null, (int) $auditor->id, (int) $customer->id], [$change->from_status, $change->to_status, $change->reason, (int) $change->changed_by_user_id, (int) $change->customer_id]);

        // Twice is not a second start.
        $this->actingAs($auditor)->post($this->url($audit, 'start'))->assertSessionHasErrors(['status' => 'Revisjonen kan ikke få denne statusen nå. Last siden på nytt.']);
        $this->assertSame(1, $audit->statusChanges()->count());
    }

    public function test_complete_requires_a_conclusion_and_writes_it_with_the_status(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        app(ComplianceAuditLifecycleService::class)->start($audit, $auditor);

        foreach ([[], ['conclusion' => ''], ['conclusion' => '   ']] as $payload) {
            $this->actingAs($auditor)->post($this->url($audit, 'complete'), $payload)
                ->assertSessionHasErrors(['conclusion' => 'Skriv en konklusjon før revisjonen fullføres.']);
        }
        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $audit->fresh()->status);
        $this->assertSame(1, $audit->statusChanges()->count());

        // No findings are needed, and none are created.
        $this->actingAs($auditor)->post($this->url($audit, 'complete'), ['conclusion' => '  Tilgangsstyringen fungerer etter hensikten.  '])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Revisjonen er fullført.');

        $fresh = $audit->fresh();
        $this->assertSame(ComplianceAudit::STATUS_COMPLETED, $fresh->status);
        $this->assertSame('Tilgangsstyringen fungerer etter hensikten.', $fresh->conclusion);
        $this->assertSame(['in_progress', 'completed'], [$fresh->statusChanges()->first()->from_status, $fresh->statusChanges()->first()->to_status]);
    }

    public function test_a_conclusion_saved_while_in_progress_is_enough_to_complete(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        app(ComplianceAuditLifecycleService::class)->start($audit, $auditor);

        $this->actingAs($auditor)->patch($this->url($audit), $this->auditPayload($auditor) + ['conclusion' => 'Lagret underveis.'])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit, 'complete'))->assertSessionHasNoErrors();

        $this->assertSame(ComplianceAudit::STATUS_COMPLETED, $audit->fresh()->status);
        $this->assertSame('Lagret underveis.', $audit->fresh()->conclusion);
    }

    public function test_cancel_from_planned_and_from_in_progress_needs_a_reason(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $planned = $this->complianceAudit($customer, $auditor, 'Planlagt');
        $running = $this->complianceAudit($customer, $auditor, 'Under arbeid');
        app(ComplianceAuditLifecycleService::class)->start($running, $auditor);

        foreach ([$planned, $running] as $audit) {
            foreach ([[], ['reason' => '  ']] as $payload) {
                $this->actingAs($auditor)->post($this->url($audit, 'cancel'), $payload)->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
            }
        }
        $this->assertSame(ComplianceAudit::STATUS_PLANNED, $planned->fresh()->status);
        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $running->fresh()->status);

        $this->actingAs($auditor)->post($this->url($planned, 'cancel'), ['reason' => 'Utsatt til neste år.'])->assertSessionHasNoErrors()->assertSessionHas('success', 'Revisjonen er avbrutt.');
        $this->actingAs($auditor)->post($this->url($running, 'cancel'), ['reason' => 'Revisor trakk seg.'])->assertSessionHasNoErrors();

        $this->assertSame(ComplianceAudit::STATUS_CANCELLED, $planned->fresh()->status);
        $this->assertSame(ComplianceAudit::STATUS_CANCELLED, $running->fresh()->status);
        $this->assertSame('Utsatt til neste år.', $planned->statusChanges()->sole()->reason);
        $this->assertSame(['in_progress', 'cancelled', 'Revisor trakk seg.'], [
            $running->statusChanges()->first()->from_status,
            $running->statusChanges()->first()->to_status,
            $running->statusChanges()->first()->reason,
        ]);

        // Not deleted, still listed — and read-only for good.
        $this->assertSame(2, count($this->indexProps($auditor)['audits']));
        $props = $this->props($auditor, $running);
        foreach (['can_edit', 'can_start', 'can_complete', 'can_cancel', 'can_reopen', 'can_delete', 'can_manage_requirements', 'can_manage_processes'] as $permission) {
            $this->assertFalse($props['permissions'][$permission], $permission);
        }
        $this->actingAs($auditor)->patch($this->url($running), $this->auditPayload($auditor, 'Endret'))
            ->assertSessionHas('error', 'Revisjonen er avbrutt og kan ikke endres.');
        $this->assertSame('Under arbeid', $running->fresh()->title);
    }

    public function test_a_completed_audit_is_locked(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::COMPLIANCE_DELETE]);
        $audit = $this->runToCompletion($customer, $auditor);

        $props = $this->props($auditor, $audit);
        $this->assertFalse($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_start']);
        $this->assertFalse($props['permissions']['can_complete']);
        $this->assertFalse($props['permissions']['can_cancel']);
        $this->assertFalse($props['permissions']['can_delete']);
        $this->assertFalse($props['permissions']['can_manage_requirements']);
        $this->assertTrue($props['permissions']['can_reopen']);
        $this->assertSame([], $props['editable_fields']);
        $this->assertSame([], $props['responsible_options']);

        $this->actingAs($auditor)->patch($this->url($audit), $this->auditPayload($auditor, 'Endret') + ['conclusion' => 'Ny konklusjon'])
            ->assertSessionHas('error', 'Revisjonen er fullført. Gjenåpne den før du endrer den.');
        $this->actingAs($auditor)->post($this->url($audit, 'cancel'), ['reason' => 'For sent.'])->assertSessionHasErrors('status');
        $this->actingAs($auditor)->post($this->url($audit, 'complete'), ['conclusion' => 'En gang til'])->assertSessionHasErrors('status');
        $this->actingAs($auditor)->delete($this->url($audit))->assertSessionHas('error');

        $fresh = $audit->fresh();
        $this->assertSame('Internrevisjon tilgangsstyring', $fresh->title);
        $this->assertSame('Ingen avvik funnet.', $fresh->conclusion);
        $this->assertSame(ComplianceAudit::STATUS_COMPLETED, $fresh->status);
    }

    public function test_reopen_needs_a_reason_and_makes_the_audit_editable_again(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->runToCompletion($customer, $auditor);

        $this->actingAs($auditor)->post($this->url($audit, 'reopen'), ['reason' => ''])->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertSame(ComplianceAudit::STATUS_COMPLETED, $audit->fresh()->status);

        $this->actingAs($auditor)->post($this->url($audit, 'reopen'), ['reason' => 'Konklusjonen må presiseres.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Revisjonen er gjenåpnet.');

        $fresh = $audit->fresh();
        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $fresh->status);
        // The conclusion stays until it is rewritten.
        $this->assertSame('Ingen avvik funnet.', $fresh->conclusion);

        $history = $this->props($auditor, $audit)['status_history'];
        $this->assertSame(
            [['completed', 'in_progress', 'Konklusjonen må presiseres.'], ['in_progress', 'completed', null], ['planned', 'in_progress', null]],
            array_map(fn (array $entry): array => [$entry['from_status'], $entry['to_status'], $entry['reason']], $history),
        );

        $this->actingAs($auditor)->patch($this->url($audit), $this->auditPayload($auditor) + ['conclusion' => 'Presisert konklusjon.'])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit, 'complete'))->assertSessionHasNoErrors();
        $this->assertSame('Presisert konklusjon.', $audit->fresh()->conclusion);
        $this->assertSame(4, $audit->statusChanges()->count());
    }

    public function test_every_other_transition_is_refused(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $lifecycle = app(ComplianceAuditLifecycleService::class);

        $planned = $this->complianceAudit($customer, $auditor, 'Planlagt');
        $this->actingAs($auditor)->post($this->url($planned, 'complete'), ['conclusion' => 'Hoppet over'])->assertSessionHasErrors('status');
        $this->actingAs($auditor)->post($this->url($planned, 'reopen'), ['reason' => 'Hvorfor'])->assertSessionHasErrors('status');

        $running = $this->complianceAudit($customer, $auditor, 'Under arbeid');
        $lifecycle->start($running, $auditor);
        $this->actingAs($auditor)->post($this->url($running, 'start'))->assertSessionHasErrors('status');
        $this->actingAs($auditor)->post($this->url($running, 'reopen'), ['reason' => 'Hvorfor'])->assertSessionHasErrors('status');

        $cancelled = $this->complianceAudit($customer, $auditor, 'Avbrutt');
        $lifecycle->cancel($cancelled, $auditor, 'Ikke aktuelt.');
        foreach (['start' => [], 'complete' => ['conclusion' => 'x'], 'cancel' => ['reason' => 'x'], 'reopen' => ['reason' => 'Ikke tillatt i v1']] as $action => $payload) {
            $this->actingAs($auditor)->post($this->url($cancelled, $action), $payload)->assertSessionHasErrors('status');
        }

        $this->assertSame(ComplianceAudit::STATUS_PLANNED, $planned->fresh()->status);
        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $running->fresh()->status);
        $this->assertSame(ComplianceAudit::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(0, $planned->statusChanges()->count());
        $this->assertSame(1, $running->statusChanges()->count());
        $this->assertSame(1, $cancelled->statusChanges()->count());

        // The database refuses a transition the service would never write, and a completed audit
        // without a conclusion.
        $this->assertRefused('planned → completed', fn () => ComplianceAuditStatusChange::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $planned->id, 'from_status' => 'planned', 'to_status' => 'completed', 'changed_at' => now(),
        ]));
        $this->assertRefused('cancelled → in_progress', fn () => ComplianceAuditStatusChange::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $cancelled->id, 'from_status' => 'cancelled', 'to_status' => 'in_progress', 'reason' => 'x', 'changed_at' => now(),
        ]));
        $this->assertRefused('a cancellation without a reason', fn () => ComplianceAuditStatusChange::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $running->id, 'from_status' => 'in_progress', 'to_status' => 'cancelled', 'reason' => ' ', 'changed_at' => now(),
        ]));
        $this->assertRefused('completed without a conclusion', fn () => DB::table('compliance_audits')->where('id', $running->id)->update(['status' => 'completed']));
    }

    public function test_the_status_history_is_immutable(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        app(ComplianceAuditLifecycleService::class)->cancel($audit, $auditor, 'Opprinnelig begrunnelse.');
        $change = ComplianceAuditStatusChange::query()->where('audit_id', $audit->id)->sole();

        try {
            $change->update(['reason' => 'Omskrevet']);
            $this->fail('A status change must not be updatable through the model.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $change->delete();
            $this->fail('A status change must not be deletable through the model.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertRefused('rewriting history', fn () => DB::table('compliance_audit_status_changes')->where('id', $change->id)->update(['reason' => 'Omskrevet']));
        $this->assertRefused('moving history in time', fn () => DB::table('compliance_audit_status_changes')->where('id', $change->id)->update(['changed_at' => now()->subYear()]));
        $this->assertRefused('deleting history', fn () => DB::table('compliance_audit_status_changes')->where('id', $change->id)->delete());
        // Deleting the audit would cascade into its history, which is refused too.
        $this->assertRefused('deleting an audit with history', fn () => DB::table('compliance_audits')->where('id', $audit->id)->delete());

        $this->assertSame('Opprinnelig begrunnelse.', $change->fresh()->reason);

        // The author leaving nulls the reference and nothing else.
        $auditor->delete();
        $this->assertNull($change->fresh()->changed_by_user_id);
        $this->assertSame('Opprinnelig begrunnelse.', $change->fresh()->reason);
    }

    // ---------------------------------------------------------------------
    // Editing
    // ---------------------------------------------------------------------

    public function test_a_planned_audit_is_edited_in_full(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $other = $this->complianceReader($customer);
        $audit = $this->complianceAudit($customer, $auditor);

        $this->assertSame(['title', 'audit_type', 'responsible_user_id', 'auditor_name', 'planned_start_date', 'planned_end_date', 'scope_description'], $this->props($auditor, $audit)['editable_fields']);

        $this->actingAs($auditor)->patch($this->url($audit), [
            'title' => 'Ekstern revisjon',
            'audit_type' => ComplianceAudit::TYPE_EXTERNAL,
            'responsible_user_id' => $other->id,
            'auditor_name' => 'Revisjonsfirma AS',
            'planned_start_date' => '2027-01-10',
            'planned_end_date' => '2027-01-20',
            'scope_description' => 'Nytt scope.',
            // Not a planned audit's field, and never a form field: neither is read.
            'conclusion' => 'For tidlig',
            'status' => ComplianceAudit::STATUS_COMPLETED,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Revisjonen er oppdatert.');

        $fresh = $audit->fresh();
        $this->assertSame(['Ekstern revisjon', 'external', (int) $other->id, 'Revisjonsfirma AS', '2027-01-10', '2027-01-20', 'Nytt scope.'], [
            $fresh->title, $fresh->audit_type, (int) $fresh->responsible_user_id, $fresh->auditor_name,
            $fresh->planned_start_date->toDateString(), $fresh->planned_end_date->toDateString(), $fresh->scope_description,
        ]);
        $this->assertNull($fresh->conclusion);
        $this->assertSame(ComplianceAudit::STATUS_PLANNED, $fresh->status);
    }

    public function test_an_audit_in_progress_keeps_its_type_and_takes_a_conclusion(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        app(ComplianceAuditLifecycleService::class)->start($audit, $auditor);

        $this->assertNotContains('audit_type', $this->props($auditor, $audit)['editable_fields']);
        $this->assertContains('conclusion', $this->props($auditor, $audit)['editable_fields']);

        $this->actingAs($auditor)->patch($this->url($audit), [
            'audit_type' => ComplianceAudit::TYPE_EXTERNAL,
            'auditor_name' => 'Ny revisor',
            'scope_description' => 'Utvidet scope.',
            'conclusion' => 'Foreløpig konklusjon.',
        ] + $this->auditPayload($auditor))->assertSessionHasNoErrors();

        $fresh = $audit->fresh();
        $this->assertSame(ComplianceAudit::TYPE_INTERNAL, $fresh->audit_type);
        $this->assertSame('Ny revisor', $fresh->auditor_name);
        $this->assertSame('Utvidet scope.', $fresh->scope_description);
        $this->assertSame('Foreløpig konklusjon.', $fresh->conclusion);
        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $fresh->status);
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function test_only_a_planned_audit_that_never_moved_can_be_deleted_and_only_with_delete(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $deleter = $this->complianceAuditor($customer, [CustomerPermissionCatalog::COMPLIANCE_DELETE]);
        $mistake = $this->complianceAudit($customer, $auditor, 'Feilregistrert');
        $started = $this->complianceAudit($customer, $auditor, 'Startet');
        app(ComplianceAuditLifecycleService::class)->start($started, $auditor);

        $this->assertFalse($this->props($auditor, $mistake)['permissions']['can_delete']);
        $this->actingAs($auditor)->delete($this->url($mistake))->assertForbidden();

        $this->assertTrue($this->props($deleter, $mistake)['permissions']['can_delete']);
        $this->assertFalse($this->props($deleter, $started)['permissions']['can_delete']);
        $this->actingAs($deleter)->delete($this->url($started))
            ->assertSessionHas('error', 'Revisjonen har statushistorikk og kan ikke slettes. Avbryt den i stedet.');
        $this->assertNotNull($started->fresh());

        $this->actingAs($deleter)->delete($this->url($mistake))->assertRedirect('/app/compliance/audits')->assertSessionHas('success', 'Revisjonen er slettet.');
        $this->assertNull($mistake->fresh());
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_view_without_audit_reads_but_cannot_change_anything(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        // compliance.edit and compliance.assess are not audit permissions.
        $editor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $editor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $audit = $this->complianceAudit($customer, $reader);
        $running = $this->complianceAudit($customer, $reader, 'Under arbeid', ComplianceAudit::STATUS_IN_PROGRESS);
        $completed = $this->complianceAudit($customer, $reader, 'Fullført', ComplianceAudit::STATUS_COMPLETED);

        foreach ([$reader, $editor] as $user) {
            $index = $this->indexProps($user);
            $this->assertCount(3, $index['audits']);
            $this->assertFalse($index['permissions']['can_audit']);
            $this->assertSame([], $index['responsible_options']);

            $props = $this->props($user, $audit);
            $this->assertSame('Internrevisjon tilgangsstyring', $props['audit']['title']);
            $this->assertSame([], array_keys(array_filter($props['permissions'])));
            $this->assertSame([], $props['editable_fields']);
            $this->assertNull($props['requirement_options']);

            $this->actingAs($user)->post('/app/compliance/audits', $this->auditPayload($reader))->assertForbidden();
            $this->actingAs($user)->patch($this->url($audit), $this->auditPayload($reader, 'Endret'))->assertForbidden();
            $this->actingAs($user)->post($this->url($audit, 'start'))->assertForbidden();
            $this->actingAs($user)->post($this->url($audit, 'cancel'), ['reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->post($this->url($running, 'complete'), ['conclusion' => 'x'])->assertForbidden();
            $this->actingAs($user)->post($this->url($completed, 'reopen'), ['reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->delete($this->url($audit))->assertForbidden();
        }

        $this->assertSame(1, ComplianceAudit::query()->where('customer_id', $customer->id)->where('status', ComplianceAudit::STATUS_PLANNED)->count());
        $this->assertSame(0, ComplianceAuditStatusChange::query()->where('customer_id', $customer->id)->count());
    }

    public function test_audit_without_view_opens_nothing(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $responsible = $this->complianceReader($customer);
        $audit = $this->complianceAudit($customer, $responsible);
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::COMPLIANCE_AUDIT, CustomerPermissionCatalog::COMPLIANCE_DELETE]);

        $this->assertWholeAuditAreaForbidden($user, $audit);
        $this->assertFalse(app(ComplianceAccessService::class)->canAudit($user));
    }

    public function test_system_owner_without_an_explicit_role_reaches_no_audit(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $responsible = $this->complianceReader($customer);
        $audit = $this->complianceAudit($customer, $responsible);

        $this->assertWholeAuditAreaForbidden($owner, $audit);
        $this->assertSame(0, app(ComplianceAccessService::class)->visibleAudits($owner)->count());

        // With a role of their own, like anyone else.
        $this->complianceGrant($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_AUDIT]);
        $this->actingAs($owner)->post($this->url($audit, 'start'))->assertSessionHasNoErrors();
        $this->assertSame(ComplianceAudit::STATUS_IN_PROGRESS, $audit->fresh()->status);
    }

    public function test_another_customers_audit_is_a_404_for_read_and_every_write(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::COMPLIANCE_DELETE, CustomerPermissionCatalog::QUALITY_VIEW]);
        $foreignAuditor = $this->complianceAuditor($foreign);
        $foreignAudit = $this->complianceAudit($foreign, $foreignAuditor, 'Fremmed revisjon');

        $this->actingAs($auditor)->get($this->url($foreignAudit))->assertNotFound();
        $this->actingAs($auditor)->patch($this->url($foreignAudit), $this->auditPayload($auditor))->assertNotFound();
        $this->actingAs($auditor)->delete($this->url($foreignAudit))->assertNotFound();
        foreach (['start' => [], 'complete' => ['conclusion' => 'x'], 'cancel' => ['reason' => 'x'], 'reopen' => ['reason' => 'x']] as $action => $payload) {
            $this->actingAs($auditor)->post($this->url($foreignAudit, $action), $payload)->assertNotFound();
        }
        $this->actingAs($auditor)->post($this->url($foreignAudit, 'requirements'), ['requirement_ids' => [1]])->assertNotFound();
        $this->actingAs($auditor)->post($this->url($foreignAudit, 'requirements/from-source'), ['source_id' => 1])->assertNotFound();
        $this->actingAs($auditor)->delete($this->url($foreignAudit, 'requirements/1'))->assertNotFound();
        $this->actingAs($auditor)->post($this->url($foreignAudit, 'processes'), ['quality_process_id' => 1])->assertNotFound();
        $this->actingAs($auditor)->delete($this->url($foreignAudit, 'processes/1'))->assertNotFound();
        // The same answer as an id that does not exist.
        $this->actingAs($auditor)->get('/app/compliance/audits/999999999')->assertNotFound();

        $this->assertSame('Fremmed revisjon', $foreignAudit->fresh()->title);
        $this->assertSame(ComplianceAudit::STATUS_PLANNED, $foreignAudit->fresh()->status);
    }

    public function test_the_register_count_search_and_filters_are_tenant_safe(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $foreignAuditor = $this->complianceAuditor($foreign);
        $this->complianceAudit($customer, $auditor, 'Tilgangsrevisjon egen');
        $this->complianceAudit($customer, $auditor, 'Leverandørrevisjon', ComplianceAudit::STATUS_IN_PROGRESS, ComplianceAudit::TYPE_EXTERNAL);
        $this->complianceAudit($foreign, $foreignAuditor, 'Tilgangsrevisjon fremmed');
        $this->complianceAudit($foreign, $foreignAuditor, 'Fremmed ekstern', ComplianceAudit::STATUS_IN_PROGRESS, ComplianceAudit::TYPE_EXTERNAL);

        $props = $this->indexProps($auditor);
        $this->assertSame(2, $props['visible_count']);
        // Under arbeid first.
        $this->assertSame(['Leverandørrevisjon', 'Tilgangsrevisjon egen'], array_column($props['audits'], 'title'));

        $this->assertSame(['Tilgangsrevisjon egen'], array_column($this->indexProps($auditor, '?search=tilgangsrevisjon')['audits'], 'title'));
        $this->assertSame(['Leverandørrevisjon'], array_column($this->indexProps($auditor, '?type=external')['audits'], 'title'));
        $this->assertSame(['Leverandørrevisjon'], array_column($this->indexProps($auditor, '?status=in_progress')['audits'], 'title'));
        $this->assertSame([], $this->indexProps($auditor, '?search=fremmed')['audits']);
        // A search in the scope text finds it too; the count is the whole visible register, never the foreign one.
        $searched = $this->indexProps($auditor, '?search=brukeradministrasjon');
        $this->assertCount(2, $searched['audits']);
        $this->assertSame(2, $searched['visible_count']);
        // An unknown filter value is ignored, not answered.
        $this->assertSame('', $this->indexProps($auditor, '?status=archived&type=annual')['filters']['status']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function assertWholeAuditAreaForbidden(User $user, ComplianceAudit $audit): void
    {
        $this->actingAs($user)->get('/app/compliance/audits')->assertForbidden();
        $this->actingAs($user)->get($this->url($audit))->assertForbidden();
        $this->actingAs($user)->post('/app/compliance/audits', ['title' => 'x'])->assertForbidden();
        $this->actingAs($user)->patch($this->url($audit), ['title' => 'x'])->assertForbidden();
        $this->actingAs($user)->delete($this->url($audit))->assertForbidden();
        foreach (['start', 'complete', 'cancel', 'reopen', 'requirements', 'requirements/from-source', 'processes'] as $action) {
            $this->actingAs($user)->post($this->url($audit, $action), ['reason' => 'x'])->assertForbidden();
        }
        $this->actingAs($user)->delete($this->url($audit, 'requirements/1'))->assertForbidden();
        $this->actingAs($user)->delete($this->url($audit, 'processes/1'))->assertForbidden();

        $this->assertSame(ComplianceAudit::STATUS_PLANNED, $audit->fresh()->status);
    }

    private function runToCompletion(Customer $customer, User $auditor): ComplianceAudit
    {
        $audit = $this->complianceAudit($customer, $auditor);
        $lifecycle = app(ComplianceAuditLifecycleService::class);
        $lifecycle->start($audit, $auditor);
        $lifecycle->complete($audit, $auditor, 'Ingen avvik funnet.');

        return $audit->fresh();
    }

    private function assertRefused(string $what, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("The database must refuse {$what}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function url(ComplianceAudit $audit, ?string $action = null): string
    {
        return "/app/compliance/audits/{$audit->id}".($action !== null ? "/{$action}" : '');
    }

    /** @return array<string, mixed> */
    private function props(User $user, ComplianceAudit $audit): array
    {
        return $this->actingAs($user)->get($this->url($audit))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function indexProps(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/app/compliance/audits'.$query)->assertOk()->viewData('page')['props'];
    }
}
