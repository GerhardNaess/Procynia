<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseProcess;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Følg opp i Avvik og forbedringer»: a revisjonsfunn handed off to one ImprovementCase.
 *
 * What these tests defend:
 *
 *  - The case is registered exactly like one registered in Avvik og forbedringer: the same rules,
 *    area authority (improvement.edit in the chosen fagområde), owner rule and frist rule. The type
 *    follows the finding; title and description start as the finding's.
 *  - It takes compliance.audit as well, an audit in progress or completed, and a finding not yet
 *    handed off. A double submit makes one case.
 *  - The finding records which case, who and when; the case cannot be deleted from under it.
 *  - Neither side leaks the other: the audit links to the case only when the case is readable and
 *    never shows its status; the case shows where it came from only to someone who can read the
 *    audit in Etterlevelse og revisjon.
 */
class ComplianceAuditFindingHandoffTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesImprovementCaseScenarios;
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
    // Hand-off
    // ---------------------------------------------------------------------

    public function test_each_kind_of_finding_becomes_the_right_kind_of_case(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();

        foreach ([
            ComplianceAuditFinding::TYPE_NONCONFORMITY => ImprovementCase::TYPE_DEVIATION,
            ComplianceAuditFinding::TYPE_OBSERVATION => ImprovementCase::TYPE_IMPROVEMENT,
            ComplianceAuditFinding::TYPE_OPPORTUNITY => ImprovementCase::TYPE_IMPROVEMENT,
        ] as $findingType => $caseType) {
            $finding = $this->finding($audit, $findingType);
            // Whatever type the request names, the finding decides.
            $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor) + ['type' => 'improvement'])
                ->assertSessionHasNoErrors()->assertSessionHas('success', 'Funnet er overført til Avvik og forbedringer.');

            $finding->refresh();
            $case = ImprovementCase::query()->findOrFail($finding->improvement_case_id);
            $this->assertSame($caseType, $case->type, $findingType);
            $this->assertSame((int) $customer->id, (int) $case->customer_id);
        }
    }

    public function test_the_case_is_registered_from_the_finding_and_the_finding_records_the_hand_off(): void
    {
        ['area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);

        // The form starts from the finding: the server hands the page its text and the mapped type.
        $row = $this->props($auditor, $audit)['findings'][0];
        $this->assertSame([$finding->title, $finding->description, 'deviation'], [$row['title'], $row['description'], $row['improvement_type']]);

        $this->travelTo(now()->setTime(10, 30));
        $this->actingAs($auditor)->post($this->url($audit, $finding), [
            'title' => '  Fjern tilganger ved fratredelse  ',
            'description' => 'Fra revisjonen: tre av ti sluttede brukere hadde aktiv tilgang.',
            'business_area_id' => $area->id,
            'owner_user_id' => $auditor->id,
            'due_date' => '2030-03-31',
        ])->assertSessionHasNoErrors();

        $finding->refresh();
        $case = ImprovementCase::query()->findOrFail($finding->improvement_case_id);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->status);
        $this->assertSame('Fjern tilganger ved fratredelse', $case->title);
        $this->assertSame('Fra revisjonen: tre av ti sluttede brukere hadde aktiv tilgang.', $case->description);
        $this->assertSame([(int) $area->id, (int) $auditor->id, (int) $auditor->id, '2030-03-31'], [(int) $case->business_area_id, (int) $case->owner_user_id, (int) $case->reported_by_user_id, $case->due_date->format('Y-m-d')]);
        $this->assertNull($case->occurred_at);

        // The finding keeps its own text and records who handed it off, and when.
        $this->assertSame('Tilganger fjernes ikke', $finding->title);
        $this->assertSame((int) $auditor->id, (int) $finding->handed_off_by_user_id);
        $this->assertSame(now()->toDateTimeString(), $finding->handed_off_at->toDateTimeString());

        // No origin copied onto the case: the relation is the provenance.
        $this->assertSame([], array_values(array_filter(array_keys($case->getAttributes()), fn (string $column): bool => str_contains($column, 'audit') || str_contains($column, 'finding') || str_contains($column, 'origin'))));
    }

    public function test_area_owner_and_frist_follow_the_rules_of_avvik_og_forbedringer(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $payload = $this->handoffPayload($finding, $area, $auditor);

        // The area is chosen, never guessed.
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['business_area_id' => ''] + $payload)->assertSessionHasErrors('business_area_id');
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['owner_user_id' => ''] + $payload)->assertSessionHasErrors('owner_user_id');
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['title' => ''] + $payload)->assertSessionHasErrors('title');
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['due_date' => '31.03.2030'] + $payload)->assertSessionHasErrors('due_date');

        // An owner who cannot read cases in the area is no owner.
        $blind = $this->member($customer);
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['owner_user_id' => $blind->id] + $payload)
            ->assertSessionHasErrors(['owner_user_id' => __('procynia.improvements.validation.owner_not_allowed')]);
        $inactive = $this->member($customer);
        $this->grant($customer, $inactive, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['owner_user_id' => $inactive->id] + $payload)->assertSessionHasErrors('owner_user_id');

        $this->assertNull($finding->fresh()->improvement_case_id);
        $this->assertSame(0, ImprovementCase::query()->where('customer_id', $customer->id)->count());

        // A frist is optional, as it is for any case.
        $this->actingAs($auditor)->post($this->url($audit, $finding), ['due_date' => null] + $payload)->assertSessionHasNoErrors();
        $this->assertNull(ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id)->due_date);
    }

    public function test_hand_off_takes_compliance_audit(): void
    {
        ['customer' => $customer, 'area' => $area, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        // Full rights in Avvik og forbedringer, only reading in Etterlevelse og revisjon.
        $reader = $this->complianceReader($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);

        $this->actingAs($reader)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $reader))->assertForbidden();
        $this->assertNull($finding->fresh()->improvement_case_id);
        $this->assertNull($this->props($reader, $audit)['handoff']);
    }

    public function test_hand_off_takes_improvement_edit_in_the_chosen_area(): void
    {
        ['customer' => $customer, 'area' => $area, 'audit' => $audit] = $this->scenario();
        $other = $this->area($customer, 'Økonomi');
        $finding = $this->finding($audit);

        // compliance.audit, and in Avvik og forbedringer only reading in the area.
        $viewer = $this->complianceAuditor($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $this->actingAs($viewer)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $viewer))
            ->assertSessionHasErrors(['business_area_id' => __('procynia.improvements.validation.area_not_allowed')]);
        // The page offers no area to register in, and says so.
        $this->assertSame([], $this->props($viewer, $audit)['handoff']['area_options']);

        // Edit in one area does not reach another.
        $editor = $this->complianceAuditor($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$other]);
        $this->actingAs($editor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $editor))
            ->assertSessionHasErrors(['business_area_id' => __('procynia.improvements.validation.area_not_allowed')]);
        $this->assertSame([$other->id], array_column($this->props($editor, $audit)['handoff']['area_options'], 'id'));

        // No Avvik og forbedringer at all: the same answer, nothing more.
        $noModule = $this->complianceAuditor($customer);
        $this->actingAs($noModule)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $noModule))
            ->assertSessionHasErrors(['business_area_id' => __('procynia.improvements.validation.area_not_allowed')]);

        $this->assertNull($finding->fresh()->improvement_case_id);
        $this->assertSame(0, ImprovementCase::query()->where('customer_id', $customer->id)->count());
    }

    public function test_another_customers_area_or_owner_cannot_be_used(): void
    {
        ['area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        ['customer' => $foreign] = $this->context();
        $foreignArea = $this->area($foreign, 'Fremmed område');
        $foreignOwner = $this->editor($foreign, $foreignArea);
        $finding = $this->finding($audit);

        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $foreignArea, $auditor))->assertSessionHasErrors('business_area_id');
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $foreignOwner))->assertSessionHasErrors('owner_user_id');
        $this->assertNull($finding->fresh()->improvement_case_id);
    }

    public function test_a_double_submit_makes_one_case(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $payload = $this->handoffPayload($finding, $area, $auditor);

        $this->actingAs($auditor)->post($this->url($audit, $finding), $payload)->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit, $finding), $payload)
            ->assertSessionHasErrors(['finding' => 'Funnet er allerede overført til Avvik og forbedringer.']);

        $this->assertSame(1, ImprovementCase::query()->where('customer_id', $customer->id)->count());

        // The database holds it too: one case, one finding.
        $case = ImprovementCase::query()->where('customer_id', $customer->id)->firstOrFail();
        $second = $this->finding($audit);
        $this->assertRefused('a second finding on the same case', fn () => $second->forceFill(['improvement_case_id' => $case->id, 'handed_off_at' => now()])->save());
    }

    public function test_a_completed_audit_still_hands_off_but_planned_and_cancelled_do_not(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/complete", ['conclusion' => 'Ett avvik.'])->assertSessionHasNoErrors();

        $props = $this->props($auditor, $audit);
        $this->assertTrue($props['findings'][0]['permissions']['can_hand_off']);
        $this->assertSame([$area->id], array_column($props['handoff']['area_options'], 'id'));
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $this->assertNotNull($finding->fresh()->improvement_case_id);
        // Nothing left to hand off: no form data either.
        $this->assertNull($this->props($auditor, $audit)['handoff']);

        $running = $this->complianceAudit($customer, $auditor, 'Avbrutt revisjon', ComplianceAudit::STATUS_IN_PROGRESS);
        $stranded = $this->finding($running);
        $this->actingAs($auditor)->post("/app/compliance/audits/{$running->id}/cancel", ['reason' => 'Utsatt.'])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($running, $stranded), $this->handoffPayload($stranded, $area, $auditor))
            ->assertSessionHasErrors(['finding' => 'Funn kan bare følges opp mens revisjonen er under arbeid eller fullført.']);
        $this->assertNull($stranded->fresh()->improvement_case_id);
    }

    public function test_the_findings_process_is_proposed_and_can_be_left_out(): void
    {
        ['customer' => $customer, 'area' => $area, 'audit' => $audit] = $this->scenario();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $this->grant($customer, $auditor, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $process = QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Brukeradministrasjon', 'status' => QualityItem::STATUS_ACTIVE]);
        $kept = $this->finding($audit, extra: ['quality_process_id' => $process->id]);
        $dropped = $this->finding($audit, extra: ['quality_process_id' => $process->id]);

        $props = $this->props($auditor, $audit);
        $this->assertTrue($props['handoff']['can_link_process']);
        $this->assertSame('Brukeradministrasjon', $props['findings'][0]['quality_process']['title']);

        $this->actingAs($auditor)->post($this->url($audit, $kept), $this->handoffPayload($kept, $area, $auditor) + ['link_process' => true])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit, $dropped), $this->handoffPayload($dropped, $area, $auditor) + ['link_process' => false])->assertSessionHasNoErrors();

        $this->assertTrue(ImprovementCaseProcess::query()->where('improvement_case_id', $kept->fresh()->improvement_case_id)->where('quality_process_id', $process->id)->exists());
        $this->assertFalse(ImprovementCaseProcess::query()->where('improvement_case_id', $dropped->fresh()->improvement_case_id)->exists());
    }

    public function test_without_kvalitet_nothing_is_linked_whatever_the_request_says(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $process = QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Skjult', 'status' => QualityItem::STATUS_ACTIVE]);
        $finding = $this->finding($audit, extra: ['quality_process_id' => $process->id]);

        $this->assertFalse($this->props($auditor, $audit)['handoff']['can_link_process']);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor) + ['link_process' => true])->assertSessionHasNoErrors();

        $this->assertFalse(ImprovementCaseProcess::query()->where('improvement_case_id', $finding->fresh()->improvement_case_id)->exists());
    }

    public function test_a_case_created_from_a_finding_cannot_be_deleted(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id);
        $handler = $this->handler($customer, $area);

        $this->assertFalse($case->isDeletable());
        $this->assertFalse($this->caseProps($handler, $case)['permissions']['can_delete']);
        $this->actingAs($handler)->delete("/app/improvements/{$case->id}")->assertSessionHas('error');
        $this->assertNotNull($case->fresh());
        $this->assertRefused('deleting the case past the model', fn () => ImprovementCase::query()->whereKey($case->id)->delete());

        // Cancel is the way out, as for any case.
        $this->actingAs($handler)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Dekkes av annen sak.'])->assertSessionHasNoErrors();
        $this->assertSame(ImprovementCase::STATUS_CANCELLED, $case->fresh()->status);
        $this->assertSame((int) $case->id, (int) $finding->fresh()->improvement_case_id);
    }

    // ---------------------------------------------------------------------
    // Visibility
    // ---------------------------------------------------------------------

    public function test_the_audit_links_to_a_readable_case_and_never_shows_its_status(): void
    {
        ['area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id);

        $row = $this->props($auditor, $audit)['findings'][0];
        $this->assertTrue($row['handed_off']);
        $this->assertSame(['url' => route('app.improvements.show', ['caseId' => $case->id]), 'title' => $case->title], $row['case_link']);
        $this->assertSame($auditor->name, $row['handed_off_by_name']);
        // Only whether it was handed off — no case status, tiltak, verification or frist.
        $this->assertSame(
            ['id', 'finding_type', 'title', 'description', 'requirement', 'improvement_type', 'handed_off', 'handed_off_at', 'handed_off_by_name', 'case_link', 'permissions'],
            array_keys($row),
        );
    }

    public function test_a_case_the_person_cannot_read_is_shown_as_handed_off_without_link_title_or_status(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor, title: 'Hemmelig sakstittel'))->assertSessionHasNoErrors();

        // Reads the audit, but cannot reach the case's area — or Avvik og forbedringer at all.
        $elsewhere = $this->complianceReader($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$this->area($customer, 'Annet')]);
        $nowhere = $this->complianceReader($customer);

        foreach ([$elsewhere, $nowhere] as $user) {
            $props = $this->props($user, $audit);
            $row = $props['findings'][0];
            $this->assertTrue($row['handed_off']);
            $this->assertNull($row['case_link']);
            $json = json_encode($row);
            $this->assertStringNotContainsString('Hemmelig sakstittel', $json);
            $this->assertStringNotContainsString('/app/improvements', $json);
            $this->assertStringNotContainsString('"status"', $json);
        }
    }

    public function test_the_case_shows_its_audit_origin_only_to_someone_who_can_read_compliance(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id);

        $this->assertSame([
            'audit_title' => $audit->title,
            'audit_url' => route('app.compliance.audits.show', ['auditId' => $audit->id]).'#finding-'.$finding->id,
            'finding_title' => $finding->title,
        ], $this->caseProps($auditor, $case)['audit_origin']);

        // Full rights on the case, no compliance access: the case says nothing about the audit.
        $handler = $this->handler($customer, $area);
        $response = $this->actingAs($handler)->get("/app/improvements/{$case->id}")->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['audit_origin']);
        $pageData = json_encode([$props['case'], $props['audit_origin'], $props['status_history']]);
        $this->assertStringNotContainsString($audit->title, $pageData);
        $this->assertStringNotContainsString('/app/compliance', json_encode($props['case']));

        // A case never handed off has no origin either.
        $plain = $this->improvementCase($customer, $area, 'Vanlig sak', $auditor);
        $this->assertNull($this->caseProps($auditor, $plain)['audit_origin']);
    }

    public function test_a_customer_stepped_down_from_iso_shows_no_audit_origin_whatever_the_role_says(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id);

        // Etterlevelse og revisjon cancelled: Avvik og forbedringer stays with Basis. The auditor's
        // role still carries compliance.view.
        // Cancelling the option is all it takes.
        app(ModuleEntitlementService::class)->cancelOption($customer, 'compliance');

        $this->assertAuditOriginHidden($this->caseProps($auditor, $case), $audit, $finding);
    }

    public function test_system_owner_without_an_explicit_compliance_grant_sees_no_audit_origin(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id);

        $owner = User::query()->where('customer_id', $customer->id)->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)->firstOrFail();
        // Enough to open the case; nothing in Etterlevelse og revisjon.
        $this->grant($customer, $owner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);

        $this->assertAuditOriginHidden($this->caseProps($owner, $case), $audit, $finding);
    }

    public function test_another_customer_learns_nothing_about_the_case_or_its_audit(): void
    {
        ['area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post($this->url($audit, $finding), $this->handoffPayload($finding, $area, $auditor))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->findOrFail($finding->fresh()->improvement_case_id);

        // A full ISO auditor of their own customer, with Avvik og forbedringer across all its areas.
        ['customer' => $foreign] = $this->complianceContext();
        $stranger = $this->complianceAuditor($foreign);
        $this->grantAll($foreign, $stranger, [CustomerPermissionCatalog::IMPROVEMENT_VIEW]);

        $response = $this->actingAs($stranger)->get("/app/improvements/{$case->id}")->assertNotFound();
        $this->assertStringNotContainsString($audit->title, (string) $response->getContent());
        $this->assertStringNotContainsString('finding-'.$finding->id, (string) $response->getContent());
    }

    public function test_a_finding_or_audit_of_another_customer_cannot_be_handed_off(): void
    {
        ['area' => $area, 'auditor' => $auditor, 'audit' => $audit] = $this->scenario();
        ['customer' => $foreign] = $this->complianceContext();
        $foreignAudit = $this->complianceAudit($foreign, null, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $foreignFinding = $this->finding($foreignAudit);

        $this->actingAs($auditor)->post($this->url($foreignAudit, $foreignFinding), $this->handoffPayload($foreignFinding, $area, $auditor))->assertNotFound();
        $this->actingAs($auditor)->post($this->url($audit, $foreignFinding), $this->handoffPayload($foreignFinding, $area, $auditor))->assertNotFound();
        $this->assertNull($foreignFinding->fresh()->improvement_case_id);
    }

    // ---------------------------------------------------------------------
    // Regression: Avvik og forbedringer registers exactly as before
    // ---------------------------------------------------------------------

    public function test_registering_a_case_in_avvik_og_forbedringer_goes_through_the_same_creator(): void
    {
        ['customer' => $customer, 'area' => $area, 'auditor' => $auditor] = $this->scenario();
        $other = $this->area($customer, 'Annet');

        $this->actingAs($auditor)->post('/app/improvements', $this->payload($other, $auditor))
            ->assertSessionHasErrors(['business_area_id' => __('procynia.improvements.validation.area_not_allowed')]);
        $this->actingAs($auditor)->post('/app/improvements', $this->payload($area, $auditor, 'Registrert direkte'))->assertSessionHasNoErrors();

        $case = ImprovementCase::query()->where('title', 'Registrert direkte')->firstOrFail();
        $this->assertSame([(int) $auditor->id, ImprovementCase::STATUS_OPEN], [(int) $case->reported_by_user_id, $case->status]);
        $this->assertNull($this->caseProps($auditor, $case)['audit_origin']);
        $this->assertTrue($case->isDeletable());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * An ISO customer (Etterlevelse og revisjon and Avvik og forbedringer), one fagområde, and an
     * auditor who may also register cases there — with an audit in progress.
     *
     * @return array{customer: Customer, area: BusinessArea, auditor: User, audit: ComplianceAudit}
     */
    private function scenario(): array
    {
        ['customer' => $customer] = $this->complianceContext();
        $area = $this->area($customer, 'IT');
        $auditor = $this->complianceAuditor($customer);
        $this->grant($customer, $auditor, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);

        return ['customer' => $customer, 'area' => $area, 'auditor' => $auditor, 'audit' => $audit];
    }

    /** @param  array<string, mixed>  $extra */
    private function finding(ComplianceAudit $audit, string $type = ComplianceAuditFinding::TYPE_NONCONFORMITY, array $extra = []): ComplianceAuditFinding
    {
        return ComplianceAuditFinding::query()->create($extra + [
            'customer_id' => $audit->customer_id,
            'audit_id' => $audit->id,
            'finding_type' => $type,
            'title' => 'Tilganger fjernes ikke',
            'description' => 'Tre av ti sluttede brukere hadde fortsatt aktiv tilgang.',
        ]);
    }

    /** @return array<string, mixed> */
    private function handoffPayload(ComplianceAuditFinding $finding, BusinessArea $area, User $owner, ?string $title = null): array
    {
        return [
            'title' => $title ?? $finding->title,
            'description' => $finding->description,
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'due_date' => '2030-03-31',
        ];
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

    private function url(ComplianceAudit $audit, ComplianceAuditFinding $finding): string
    {
        return "/app/compliance/audits/{$audit->id}/findings/{$finding->id}/handoff";
    }

    /** @return array<string, mixed> */
    private function props(User $user, ComplianceAudit $audit): array
    {
        return $this->actingAs($user)->get("/app/compliance/audits/{$audit->id}")->assertOk()->viewData('page')['props'];
    }

    /** @param  array<string, mixed>  $props */
    private function assertAuditOriginHidden(array $props, ComplianceAudit $audit, ComplianceAuditFinding $finding): void
    {
        $this->assertNull($props['audit_origin']);

        // The page's own props: the shared ones (navigation, translations, ...) say nothing about a case.
        $pageData = json_encode(array_diff_key($props, array_flip(['errors', 'appName', 'locale', 'auth', 'entitlements', 'access', 'notifications', 'flash', 'translations'])));
        $this->assertStringNotContainsString($audit->title, $pageData);
        $this->assertStringNotContainsString('\/app\/compliance', $pageData);
        $this->assertStringNotContainsString('finding-'.$finding->id, $pageData);
        $this->assertStringNotContainsString('"audit_id"', $pageData);
        $this->assertStringNotContainsString('"finding_id"', $pageData);
    }

    /** @return array<string, mixed> */
    private function caseProps(User $user, ImprovementCase $case): array
    {
        return $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
    }
}
