<?php

namespace Tests\Feature\App;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementStatusChange;
use App\Models\ComplianceSource;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Support\CustomerPermissionCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → Krav, from database to page.
 *
 * What these tests defend:
 *
 *  - Access is customer-wide and comes only from the customer's own roles: compliance.view reads,
 *    compliance.edit registers, changes, retires and reopens, compliance.delete deletes. System
 *    Owner holds none of it without a role of their own (explicit-grant domain).
 *  - Another customer's requirement or source is undiscoverable — not listed, searched, filtered or
 *    counted, and a 404 by URL for read and for every write.
 *  - The owner is an active person of the same customer who can read requirements.
 *  - A reference is unique within its source, ignoring case.
 *  - Status is never a form field. Retire and reopen need a reason and write one immutable history
 *    row each; a requirement with history cannot be deleted, a source in use cannot be deleted.
 *  - The database holds the same lines: CHECKs, composite tenant keys, and the history trigger.
 */
class ComplianceRequirementTest extends TestCase
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
    // Access
    // ---------------------------------------------------------------------

    public function test_without_compliance_view_every_route_is_a_403(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Krav');
        $user = $this->complianceMember($customer);
        // Other domains' permissions open nothing here, nor does an inactive role with view.
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::IMPROVEMENT_VIEW]);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT], active: false);

        $this->assertWholeModuleForbidden($user, $requirement, $source);
    }

    public function test_system_owner_without_an_explicit_role_reaches_no_compliance_data(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Skjult for System Owner');

        $this->assertWholeModuleForbidden($owner, $requirement, $source);

        $access = app(ComplianceAccessService::class);
        $this->assertSame(0, $access->visibleRequirements($owner)->count());
        $this->assertSame(0, $access->visibleSources($owner)->count());
        $this->assertNull($access->findVisibleRequirement($owner, (int) $requirement->id));

        // Not offered by the rail either: the shared permissions carry no compliance key.
        $shared = $this->actingAs($owner)->get('/app/dashboard')->viewData('page')['props']['access']['permissions'];
        $this->assertNotContains(CustomerPermissionCatalog::COMPLIANCE_VIEW, $shared);
        $this->assertContains(CustomerPermissionCatalog::QUALITY_VIEW, $shared);
    }

    public function test_system_owner_with_an_explicit_role_works_like_anyone_with_that_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $source = $this->complianceSource($customer);
        $this->complianceGrant($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT]);

        $this->actingAs($owner)->get('/app/compliance')->assertRedirect('/app/compliance/requirements');
        $props = $this->actingAs($owner)->get('/app/compliance/requirements')->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_delete']);
        // Holding the role makes System Owner a possible owner, through it.
        $this->assertContains((int) $owner->id, array_column($props['owner_options'], 'id'));

        $this->actingAs($owner)->post('/app/compliance/requirements', $this->requirementPayload($source, $owner))->assertSessionHasNoErrors();
        $requirement = ComplianceRequirement::query()->where('customer_id', $customer->id)->sole();

        // Delete is not in the role, and System Owner gets no implicit delete either.
        $this->actingAs($owner)->delete("/app/compliance/requirements/{$requirement->id}")->assertForbidden();
    }

    public function test_a_reader_sees_the_register_and_the_requirement_but_cannot_change_anything(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $source = $this->complianceSource($customer);
        $owner = $this->complianceReader($customer);
        $requirement = $this->complianceRequirement($source, 'Lesbart krav', $owner, 'A.1');
        $reader = $this->complianceReader($customer);

        $index = $this->actingAs($reader)->get('/app/compliance/requirements')->assertOk()->viewData('page');
        $this->assertSame('App/Compliance/Requirements/Index', $index['component']);
        $this->assertSame(['Lesbart krav'], array_column($index['props']['requirements'], 'title'));
        $this->assertSame(['can_edit' => false, 'can_delete' => false], $index['props']['permissions']);
        $this->assertSame([], $index['props']['owner_options']);
        $this->assertFalse($index['props']['sources'][0]['can_delete']);

        $show = $this->actingAs($reader)->get("/app/compliance/requirements/{$requirement->id}")->assertOk()->viewData('page');
        $this->assertSame('App/Compliance/Requirements/Show', $show['component']);
        $this->assertSame(['can_edit' => false, 'can_retire' => false, 'can_reopen' => false, 'can_delete' => false, 'can_assess' => false, 'can_manage_quality_links' => false], $show['props']['permissions']);

        $this->actingAs($reader)->post('/app/compliance/requirements', $this->requirementPayload($source, $owner))->assertForbidden();
        $this->actingAs($reader)->patch("/app/compliance/requirements/{$requirement->id}", $this->requirementPayload($source, $owner))->assertForbidden();
        $this->actingAs($reader)->post("/app/compliance/requirements/{$requirement->id}/retire", ['reason' => 'Nei'])->assertForbidden();
        $this->actingAs($reader)->delete("/app/compliance/requirements/{$requirement->id}")->assertForbidden();
        $this->actingAs($reader)->post('/app/compliance/sources', ['name' => 'Ny', 'kind' => 'law'])->assertForbidden();
        $this->actingAs($reader)->patch("/app/compliance/sources/{$source->id}", ['name' => 'Ny', 'kind' => 'law'])->assertForbidden();
        $this->actingAs($reader)->delete("/app/compliance/sources/{$source->id}")->assertForbidden();

        $this->assertSame('Lesbart krav', $requirement->fresh()->title);
        $this->assertSame(1, ComplianceSource::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_customer_without_the_module_is_sent_home(): void
    {
        // Styring carries every governance module but Etterlevelse og revisjon, which starts at ISO.
        ['customer' => $customer] = $this->complianceContext('governance');
        $user = $this->complianceReader($customer);

        $this->actingAs($user)->get('/app/compliance/requirements')->assertRedirect(route('app.dashboard'));
        $this->actingAs($user)->get('/app/compliance')->assertRedirect(route('app.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Tenant isolation
    // ---------------------------------------------------------------------

    public function test_another_customers_requirements_and_sources_are_absent_and_404_by_url(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $manager = $this->complianceManager($customer);
        $own = $this->complianceRequirement($this->complianceSource($customer, 'Egen kilde'), 'Eget krav', $manager);
        $foreignSource = $this->complianceSource($other, 'Fremmed kilde');
        $foreignOwner = $this->complianceReader($other);
        $foreign = $this->complianceRequirement($foreignSource, 'Fremmed krav', $foreignOwner, 'X.1');

        $props = $this->actingAs($manager)->get('/app/compliance/requirements')->viewData('page')['props'];
        $this->assertSame(['Eget krav'], array_column($props['requirements'], 'title'));
        $this->assertSame(1, $props['visible_count']);
        $this->assertSame(['Egen kilde'], array_column($props['sources'], 'name'));
        $this->assertNotContains((int) $foreignOwner->id, array_column($props['owner_options'], 'id'));

        // A foreign source as filter is ignored, not answered; a search never reaches across.
        $filtered = $this->actingAs($manager)->get("/app/compliance/requirements?source={$foreignSource->id}")->viewData('page')['props'];
        $this->assertNull($filtered['filters']['source']);
        $this->assertSame(['Eget krav'], array_column($filtered['requirements'], 'title'));
        $searched = $this->actingAs($manager)->get('/app/compliance/requirements?search=Fremmed')->viewData('page')['props'];
        $this->assertSame([], $searched['requirements']);

        $url = "/app/compliance/requirements/{$foreign->id}";
        $this->actingAs($manager)->get($url)->assertNotFound();
        $this->actingAs($manager)->patch($url, $this->requirementPayload($foreignSource, $foreignOwner))->assertNotFound();
        $this->actingAs($manager)->delete($url)->assertNotFound();
        $this->actingAs($manager)->post("{$url}/retire", ['reason' => 'Forsøk'])->assertNotFound();
        $this->actingAs($manager)->post("{$url}/reopen", ['reason' => 'Forsøk'])->assertNotFound();
        $this->actingAs($manager)->patch("/app/compliance/sources/{$foreignSource->id}", ['name' => 'Kapret', 'kind' => 'law'])->assertNotFound();
        $this->actingAs($manager)->delete("/app/compliance/sources/{$foreignSource->id}")->assertNotFound();

        // Nor can a form point a requirement at another customer's source or person.
        $this->actingAs($manager)->post('/app/compliance/requirements', $this->requirementPayload($foreignSource, $manager))->assertSessionHasErrors('source_id');
        $this->actingAs($manager)->patch("/app/compliance/requirements/{$own->id}", $this->requirementPayload($own->source, $foreignOwner))->assertSessionHasErrors('owner_user_id');

        $this->assertSame('Fremmed krav', $foreign->fresh()->title);
        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $foreign->fresh()->status);
        $this->assertSame('Fremmed kilde', $foreignSource->fresh()->name);
        $this->assertSame(0, ComplianceRequirementStatusChange::query()->where('requirement_id', $foreign->id)->count());
    }

    public function test_the_database_refuses_a_requirement_or_history_row_across_customers(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $foreignSource = $this->complianceSource($other);
        $own = $this->complianceRequirement($this->complianceSource($customer), 'Eget krav');

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->insert([
            'customer_id' => $customer->id,
            'source_id' => $foreignSource->id,
            'title' => 'Krysser kundegrensen',
            'requirement_text' => 'Tekst',
            'status' => 'active',
        ]), 'a requirement under another customer\'s source');

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->where('id', $own->id)->update(['source_id' => $foreignSource->id]), 'moving a requirement to another customer\'s source');

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_status_changes')->insert([
            'customer_id' => $other->id,
            'requirement_id' => $own->id,
            'from_status' => 'active',
            'to_status' => 'retired',
            'note' => 'Krysser kundegrensen',
            'changed_at' => now(),
        ]), 'a history row naming another customer');
    }

    // ---------------------------------------------------------------------
    // Register
    // ---------------------------------------------------------------------

    public function test_the_register_shows_its_columns_lists_active_first_and_filters(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $owner = $this->complianceReader($customer);
        $manager = $this->complianceManager($customer);
        $iso = $this->complianceSource($customer, 'ISO 27001', '2022');
        $law = $this->complianceSource($customer, 'Arbeidsmiljøloven', null, ComplianceSource::KIND_LAW);
        $retired = $this->complianceRequirement($iso, 'Gammel kontroll', $owner, 'A.1');
        app(ComplianceRequirementLifecycleService::class)->retire($retired, $manager, 'Erstattet');
        $this->complianceRequirement($iso, 'Tilgangsstyring', $owner, 'A.5.15', 12);
        $this->complianceRequirement($iso, 'Uten referanse', null);
        $this->complianceRequirement($law, 'Arbeidstid', $owner, '§ 10-4', 3);

        $props = $this->actingAs($manager)->get('/app/compliance/requirements')->viewData('page')['props'];

        // Active first; then by source name, reference (none last) and title.
        $this->assertSame(['Arbeidstid', 'Tilgangsstyring', 'Uten referanse', 'Gammel kontroll'], array_column($props['requirements'], 'title'));
        $row = $props['requirements'][1];
        $this->assertSame('A.5.15', $row['reference']);
        $this->assertSame('ISO 27001 (2022)', $row['source_label']);
        $this->assertSame($owner->name, $row['owner_name']);
        $this->assertSame(12, $row['review_interval_months']);
        $this->assertSame('active', $row['status']);
        $this->assertNull($props['requirements'][2]['owner_name']);
        $this->assertSame('Arbeidsmiljøloven', $props['requirements'][0]['source_label']);
        $this->assertSame(4, $props['visible_count']);
        $this->assertSame([1, 3, 6, 12], $props['review_intervals']);
        $this->assertSame(['standard', 'law', 'contract', 'internal', 'other'], $props['kinds']);

        $byStatus = $this->actingAs($manager)->get('/app/compliance/requirements?status=retired')->viewData('page')['props'];
        $this->assertSame(['Gammel kontroll'], array_column($byStatus['requirements'], 'title'));
        // The count is the whole register, not the filtered list.
        $this->assertSame(4, $byStatus['visible_count']);

        $bySource = $this->actingAs($manager)->get("/app/compliance/requirements?source={$law->id}")->viewData('page')['props'];
        $this->assertSame(['Arbeidstid'], array_column($bySource['requirements'], 'title'));

        foreach (['a.5.15' => 'Tilgangsstyring', 'TILGANG' => 'Tilgangsstyring', 'kravtekst for arbeidstid' => 'Arbeidstid'] as $needle => $title) {
            $found = $this->actingAs($manager)->get('/app/compliance/requirements?search='.urlencode($needle))->viewData('page')['props'];
            $this->assertSame([$title], array_column($found['requirements'], 'title'), $needle);
        }

        // A source with requirements cannot be deleted; one without could be.
        $sources = collect($props['sources'])->keyBy('name');
        $this->assertSame(3, $sources['ISO 27001']['requirement_count']);
        $this->assertFalse($sources['ISO 27001']['can_delete']);
    }

    // ---------------------------------------------------------------------
    // Requirements
    // ---------------------------------------------------------------------

    public function test_a_new_requirement_is_active_whatever_the_form_says(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $owner = $this->complianceReader($customer);
        $source = $this->complianceSource($customer);

        $response = $this->actingAs($editor)->post('/app/compliance/requirements', [
            'status' => ComplianceRequirement::STATUS_RETIRED,
            'customer_id' => 999999,
            'created_by' => $owner->id,
            'reference' => '  A.5.15  ',
        ] + $this->requirementPayload($source, $owner));

        $requirement = ComplianceRequirement::query()->where('customer_id', $customer->id)->sole();
        $response->assertRedirect("/app/compliance/requirements/{$requirement->id}")->assertSessionHas('success', 'Kravet er registrert.');

        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $requirement->status);
        $this->assertSame('A.5.15', $requirement->reference);
        $this->assertSame(12, $requirement->review_interval_months);
        $this->assertSame((int) $owner->id, (int) $requirement->owner_user_id);
        $this->assertSame((int) $editor->id, (int) $requirement->created_by);
        $this->assertSame(0, ComplianceRequirementStatusChange::query()->where('requirement_id', $requirement->id)->count());

        $props = $this->actingAs($editor)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props'];
        $this->assertSame('Regler for fysisk og logisk tilgang skal etableres.', $props['requirement']['requirement_text']);
        $this->assertSame('ISO 27001 (2022)', $props['requirement']['source_label']);
        $this->assertSame([], $props['status_history']);
        $this->assertSame(['can_edit' => true, 'can_retire' => true, 'can_reopen' => false, 'can_delete' => false, 'can_assess' => false, 'can_manage_quality_links' => false], $props['permissions']);

        // No fixed interval and no reference are both fine.
        $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($source, $owner, 'Uten', null, null))->assertSessionHasNoErrors();
        $bare = ComplianceRequirement::query()->where('title', 'Uten')->sole();
        $this->assertNull($bare->reference);
        $this->assertNull($bare->review_interval_months);
    }

    public function test_required_fields_and_review_interval_are_validated_in_norwegian(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $source = $this->complianceSource($customer);

        $this->actingAs($editor)->post('/app/compliance/requirements', [])
            ->assertSessionHasErrors([
                'source_id' => 'Kravkilde må fylles ut.',
                'title' => 'Tittel må fylles ut.',
                'requirement_text' => 'Kravtekst må fylles ut.',
                'owner_user_id' => 'Ansvarlig må fylles ut.',
            ]);

        foreach ([2, 24, 0] as $interval) {
            $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($source, $editor, 'Krav', null, $interval))
                ->assertSessionHasErrors('review_interval_months');
        }

        $this->assertSame(0, ComplianceRequirement::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_owner_is_an_active_person_of_the_customer_who_can_read_requirements(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $source = $this->complianceSource($customer);

        $inactive = $this->complianceReader($customer);
        $inactive->forceFill(['is_active' => false])->save();
        $withoutView = $this->complianceMember($customer);
        $this->complianceGrant($customer, $withoutView, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $foreign = $this->complianceReader($other);

        foreach (['inactive' => $inactive, 'without view' => $withoutView, 'other customer' => $foreign, 'System Owner without role' => $systemOwner] as $label => $candidate) {
            $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($source, $candidate, "Krav {$label}"))
                ->assertSessionHasErrors(['owner_user_id' => 'Velg en aktiv person med tilgang til Etterlevelse og revisjon.']);
        }

        $options = array_column($this->actingAs($editor)->get('/app/compliance/requirements')->viewData('page')['props']['owner_options'], 'id');
        $this->assertContains((int) $editor->id, $options);
        foreach ([$inactive, $withoutView, $foreign, $systemOwner] as $excluded) {
            $this->assertNotContains((int) $excluded->id, $options);
        }

        $this->assertSame(0, ComplianceRequirement::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_reference_is_unique_within_its_source_ignoring_case(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $iso = $this->complianceSource($customer, 'ISO 27001');
        $nis = $this->complianceSource($customer, 'NIS2', null, ComplianceSource::KIND_LAW);
        $existing = $this->complianceRequirement($iso, 'Første', $editor, 'A.5.1');

        $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($iso, $editor, 'Duplikat', 'a.5.1'))
            ->assertSessionHasErrors(['reference' => 'Kravkilden har allerede et krav med denne referansen.']);

        // The same reference under another source is a different requirement.
        $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($nis, $editor, 'Annen kilde', 'A.5.1'))->assertSessionHasNoErrors();
        // Any number without a reference.
        $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($iso, $editor, 'Uten 1', ''))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post('/app/compliance/requirements', $this->requirementPayload($iso, $editor, 'Uten 2', null))->assertSessionHasNoErrors();
        // Saving a requirement with its own reference is no conflict.
        $this->actingAs($editor)->patch("/app/compliance/requirements/{$existing->id}", $this->requirementPayload($iso, $editor, 'Første, endret', 'A.5.1'))->assertSessionHasNoErrors();
        // Moving another requirement into the source onto the taken reference is.
        $moved = ComplianceRequirement::query()->where('title', 'Annen kilde')->sole();
        $this->actingAs($editor)->patch("/app/compliance/requirements/{$moved->id}", $this->requirementPayload($iso, $editor, 'Annen kilde', 'A.5.1'))->assertSessionHasErrors('reference');

        $this->assertSame(2, ComplianceRequirement::query()->where('source_id', $iso->id)->whereNull('reference')->count());

        // The database holds the same rule.
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->insert([
            'customer_id' => $customer->id, 'source_id' => $iso->id, 'reference' => 'A.5.1', 'title' => 'Rå', 'requirement_text' => 'Rå', 'status' => 'active',
        ]), 'a duplicate reference in one source');
    }

    public function test_an_active_requirement_is_edited_but_its_status_never_through_the_form(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $newOwner = $this->complianceReader($customer);
        $iso = $this->complianceSource($customer);
        $law = $this->complianceSource($customer, 'Personopplysningsloven', null, ComplianceSource::KIND_LAW);
        $requirement = $this->complianceRequirement($iso, 'Før', $editor, 'A.1', 6);

        $this->actingAs($editor)->patch("/app/compliance/requirements/{$requirement->id}", [
            'status' => ComplianceRequirement::STATUS_RETIRED,
        ] + $this->requirementPayload($law, $newOwner, 'Etter', '§ 1', 1))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Kravet er oppdatert.');

        $fresh = $requirement->fresh();
        $this->assertSame('Etter', $fresh->title);
        $this->assertSame((int) $law->id, (int) $fresh->source_id);
        $this->assertSame('§ 1', $fresh->reference);
        $this->assertSame(1, $fresh->review_interval_months);
        $this->assertSame((int) $newOwner->id, (int) $fresh->owner_user_id);
        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $fresh->status);
        $this->assertSame((int) $editor->id, (int) $fresh->updated_by);
    }

    public function test_retire_and_reopen_need_a_reason_and_leave_an_immutable_history(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav', $editor, 'A.1');
        $url = "/app/compliance/requirements/{$requirement->id}";

        $this->actingAs($editor)->post("{$url}/retire", ['reason' => '   '])->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $requirement->fresh()->status);

        $this->actingAs($editor)->post("{$url}/retire", ['reason' => 'Standarden er erstattet.'])->assertSessionHas('success', 'Kravet er satt som utgått.');
        $this->assertSame(ComplianceRequirement::STATUS_RETIRED, $requirement->fresh()->status);

        // A retired requirement is reopened, not retired again, and not edited.
        $this->actingAs($editor)->post("{$url}/retire", ['reason' => 'Igjen'])->assertSessionHasErrors('reason');
        $this->actingAs($editor)->patch($url, $this->requirementPayload($requirement->source, $editor, 'Endret mens utgått'))
            ->assertSessionHas('error', 'Kravet er utgått. Gjenåpne det før du endrer det.');
        $this->assertSame('Krav', $requirement->fresh()->title);

        $props = $this->actingAs($editor)->get($url)->viewData('page')['props'];
        $this->assertSame(['can_edit' => false, 'can_retire' => false, 'can_reopen' => true, 'can_delete' => false, 'can_assess' => false, 'can_manage_quality_links' => false], $props['permissions']);
        $this->assertSame([], $props['source_options']);

        $this->actingAs($editor)->post("{$url}/reopen", [])->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->actingAs($editor)->post("{$url}/reopen", ['reason' => 'Kontrakten ble forlenget.'])->assertSessionHas('success', 'Kravet er gjenåpnet.');
        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $requirement->fresh()->status);

        $history = $this->actingAs($editor)->get($url)->viewData('page')['props']['status_history'];
        $this->assertSame([['retired', 'active', 'Kontrakten ble forlenget.'], ['active', 'retired', 'Standarden er erstattet.']],
            array_map(fn (array $entry): array => [$entry['from_status'], $entry['to_status'], $entry['note']], $history));
        $this->assertSame([$editor->name, $editor->name], array_column($history, 'changed_by_name'));
    }

    public function test_the_lifecycle_checks_the_status_again_inside_the_lock(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        $lifecycle = app(ComplianceRequirementLifecycleService::class);

        // Two people holding the same stale page: the second retirement writes nothing.
        $stale = $requirement->fresh();
        $lifecycle->retire($requirement, $editor, 'Første');

        try {
            $lifecycle->retire($stale, $editor, 'Andre');
            $this->fail('A second retirement must be refused.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, ComplianceRequirementStatusChange::query()->where('requirement_id', $requirement->id)->count());
    }

    // ---------------------------------------------------------------------
    // Deleting
    // ---------------------------------------------------------------------

    public function test_only_a_requirement_without_history_can_be_deleted_and_only_with_delete(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $manager = $this->complianceManager($customer);
        $source = $this->complianceSource($customer);
        $mistake = $this->complianceRequirement($source, 'Feilregistrert');
        $handled = $this->complianceRequirement($source, 'Behandlet');

        $this->actingAs($editor)->delete("/app/compliance/requirements/{$mistake->id}")->assertForbidden();

        $props = $this->actingAs($manager)->get("/app/compliance/requirements/{$mistake->id}")->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_delete']);
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$mistake->id}")
            ->assertRedirect('/app/compliance/requirements')
            ->assertSessionHas('success', 'Kravet er slettet.');
        $this->assertNull(ComplianceRequirement::query()->find($mistake->id));

        // Once retired — even if reopened since — it has a history and stays.
        $lifecycle = app(ComplianceRequirementLifecycleService::class);
        $lifecycle->retire($handled, $manager, 'Utgått');
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$handled->id}")->assertSessionHas('error');
        $lifecycle->reopen($handled->fresh(), $manager, 'Gjelder igjen');
        $this->assertFalse($this->actingAs($manager)->get("/app/compliance/requirements/{$handled->id}")->viewData('page')['props']['permissions']['can_delete']);
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$handled->id}")
            ->assertSessionHas('error', 'Kravet har statushistorikk, etterlevelsesvurderinger eller inngår i en revisjon og kan ikke slettes. Sett det som utgått i stedet.');
        $this->assertNotNull(ComplianceRequirement::query()->find($handled->id));

        // Below the domain rule, the database refuses it as well.
        $this->assertDatabaseRefuses(fn () => ComplianceRequirement::query()->whereKey($handled->id)->delete(), 'deleting a requirement with history');
        $this->assertSame(2, ComplianceRequirementStatusChange::query()->where('requirement_id', $handled->id)->count());
    }

    // ---------------------------------------------------------------------
    // Sources
    // ---------------------------------------------------------------------

    public function test_sources_are_created_changed_and_deleted_only_while_unused(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $manager = $this->complianceManager($customer);

        $this->actingAs($editor)->post('/app/compliance/sources', [])
            ->assertSessionHasErrors(['name' => 'Navn må fylles ut.', 'kind' => 'Type må fylles ut.']);
        $this->actingAs($editor)->post('/app/compliance/sources', ['name' => 'Ukjent', 'kind' => 'guideline'])->assertSessionHasErrors('kind');

        $this->actingAs($editor)->post('/app/compliance/sources', ['name' => ' ISO 9001 ', 'version' => ' ', 'kind' => 'standard', 'description' => ''])
            ->assertSessionHas('success', 'Kravkilden er opprettet.');
        $source = ComplianceSource::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(['ISO 9001', null, 'standard', null, (int) $editor->id], [$source->name, $source->version, $source->kind, $source->description, (int) $source->created_by]);

        $this->actingAs($editor)->patch("/app/compliance/sources/{$source->id}", ['name' => 'ISO 9001', 'version' => '2015', 'kind' => 'standard', 'description' => 'Kvalitetsledelse'])
            ->assertSessionHas('success', 'Kravkilden er oppdatert.');
        $this->assertSame(['2015', 'Kvalitetsledelse'], [$source->fresh()->version, $source->fresh()->description]);

        // In use: refused, with its requirement untouched.
        $requirement = $this->complianceRequirement($source, 'Bruker kilden');
        $this->actingAs($editor)->delete("/app/compliance/sources/{$source->id}")->assertForbidden();
        $this->actingAs($manager)->delete("/app/compliance/sources/{$source->id}")
            ->assertSessionHas('error', 'Kravkilden er i bruk og kan ikke slettes. Flytt eller slett kravene som bruker den først.');
        $this->assertNotNull($source->fresh());
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_sources')->where('id', $source->id)->delete(), 'deleting a source in use');

        // Unused again: deleted.
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$requirement->id}")->assertSessionHas('success');
        $this->actingAs($manager)->delete("/app/compliance/sources/{$source->id}")->assertSessionHas('success', 'Kravkilden er slettet.');
        $this->assertNull($source->fresh());
    }

    // ---------------------------------------------------------------------
    // Model and database
    // ---------------------------------------------------------------------

    public function test_the_model_and_the_database_know_only_the_allowed_values(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Krav');

        $this->assertSame(['active', 'retired'], ComplianceRequirement::STATUSES);
        $this->assertSame([1, 3, 6, 12], ComplianceRequirement::REVIEW_INTERVALS);
        $this->assertSame(['standard', 'law', 'contract', 'internal', 'other'], ComplianceSource::KINDS);

        foreach ([['status', 'compliant'], ['review_interval_months', 2]] as [$column, $value]) {
            $fresh = $requirement->fresh();
            $fresh->{$column} = $value;

            try {
                $fresh->save();
                $this->fail("An unknown {$column} must not be saved.");
            } catch (DomainException) {
            }

            $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->where('id', $requirement->id)->update([$column => $value]), "requirement {$column} = {$value}");
        }

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->where('id', $requirement->id)->update(['reference' => '  ']), 'a blank reference');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_sources')->where('id', $source->id)->update(['kind' => 'guideline']), 'an unknown source kind');

        try {
            $source->fresh()->forceFill(['kind' => 'guideline'])->save();
            $this->fail('An unknown source kind must not be saved.');
        } catch (DomainException) {
        }

        // No compliance verdict lives on the requirement.
        foreach (['compliance_status', 'current_assessment_status', 'next_review_date', 'next_review_at'] as $column) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('compliance_requirements', $column), $column);
        }
    }

    public function test_history_cannot_be_changed_or_deleted_by_the_application_or_the_database(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $editor, 'Utgått');
        $change = ComplianceRequirementStatusChange::query()->where('requirement_id', $requirement->id)->sole();

        foreach (['update' => fn () => $change->update(['note' => 'Omskrevet']), 'delete' => fn () => $change->delete()] as $label => $attempt) {
            try {
                $attempt();
                $this->fail("The model must refuse {$label}.");
            } catch (LogicException) {
            }
        }

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_status_changes')->where('id', $change->id)->update(['note' => 'Omskrevet']), 'rewriting a history note');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_status_changes')->where('id', $change->id)->update(['changed_by_user_id' => $this->complianceReader($customer)->id]), 'changing a history author');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_status_changes')->where('id', $change->id)->delete(), 'deleting a history row');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_status_changes')->insert([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'from_status' => 'active', 'to_status' => 'active', 'note' => 'Umulig', 'changed_at' => now(),
        ]), 'an impossible transition');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirement_status_changes')->insert([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'from_status' => 'retired', 'to_status' => 'active', 'note' => ' ', 'changed_at' => now(),
        ]), 'a change without a reason');

        $this->assertSame('Utgått', $change->fresh()->note);
    }

    public function test_a_deleted_person_leaves_history_and_requirement_without_an_author_or_owner(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $owner = $this->complianceReader($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav', $owner);
        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $editor, 'Utgått');

        $owner->delete();
        $editor->delete();

        $this->assertNull($requirement->fresh()->owner_user_id);
        $change = ComplianceRequirementStatusChange::query()->where('requirement_id', $requirement->id)->sole();
        $this->assertNull($change->changed_by_user_id);
        $this->assertSame('Utgått', $change->note);

        $reader = $this->complianceReader($customer);
        $props = $this->actingAs($reader)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props'];
        $this->assertNull($props['requirement']['owner_name']);
        $this->assertNull($props['status_history'][0]['changed_by_name']);
    }

    public function test_a_customer_going_takes_its_sources_requirements_and_history_with_it(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $editor, 'Utgått');
        $kept = $this->complianceRequirement($this->complianceSource($other), 'Annen kundes krav');

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertSame(0, DB::table('compliance_sources')->where('customer_id', $customer->id)->count());
        $this->assertSame(0, DB::table('compliance_requirements')->where('customer_id', $customer->id)->count());
        $this->assertSame(0, DB::table('compliance_requirement_status_changes')->where('customer_id', $customer->id)->count());
        $this->assertNotNull($kept->fresh());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function assertWholeModuleForbidden($user, ComplianceRequirement $requirement, ComplianceSource $source): void
    {
        $url = "/app/compliance/requirements/{$requirement->id}";

        $this->actingAs($user)->get('/app/compliance')->assertForbidden();
        $this->actingAs($user)->get('/app/compliance/requirements')->assertForbidden();
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->actingAs($user)->post('/app/compliance/requirements', $this->requirementPayload($source, $user))->assertForbidden();
        $this->actingAs($user)->patch($url, $this->requirementPayload($source, $user))->assertForbidden();
        $this->actingAs($user)->post("{$url}/retire", ['reason' => 'Forsøk'])->assertForbidden();
        $this->actingAs($user)->delete($url)->assertForbidden();
        $this->actingAs($user)->post('/app/compliance/sources', ['name' => 'Forsøk', 'kind' => 'law'])->assertForbidden();
        $this->actingAs($user)->delete("/app/compliance/sources/{$source->id}")->assertForbidden();

        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $requirement->fresh()->status);
        $this->assertNotNull($source->fresh());
    }

    /** Runs the write in a savepoint, so the refusal does not poison the test's transaction. */
    private function assertDatabaseRefuses(callable $write, string $what): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("The database must refuse {$what}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
