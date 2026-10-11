<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → why a requirement needs attention, and Etterlevelsesvurdering's
 * worklist across standards.
 *
 * What these tests defend:
 *
 *  - Four reasons, each read off the source that already decides it: the resolver's current status
 *    (Ikke vurdert, Ikke oppfylt, Delvis oppfylt) and the review schedule (Revurdering forfalt — due
 *    today is not overdue). Oppfylt and Ikke relevant raise nothing, and neither does a missing
 *    ansvarlig: the person is optional.
 *  - A requirement can have several reasons; nothing is stored, so putting it right clears them.
 *  - A retired requirement raises nothing at all and leaves the worklist.
 *  - The worklist counts each active requirement once, keeps Ikke relevant outside the figures,
 *    filters on status and standard, and the number of queries does not grow with it.
 *  - Everything is computed on the rows ComplianceAccessService reaches and on nothing else: another
 *    customer and a user without compliance.view move no count; System Owner needs an own role.
 */
class ComplianceAttentionTest extends TestCase
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
    // The five reasons
    // ---------------------------------------------------------------------

    public function test_an_active_requirement_never_assessed_is_not_assessed(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $owner = $this->complianceMember($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Aldri vurdert', $owner, null, 12);

        $this->assertSame([ComplianceAttentionService::NOT_ASSESSED], $this->reasons($requirement));
    }

    public function test_the_current_result_decides_the_status_reason(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $owner = $this->complianceMember($customer);
        $source = $this->complianceSource($customer);
        $expected = [
            'non_compliant' => [ComplianceAttentionService::NON_COMPLIANT],
            'partially_compliant' => [ComplianceAttentionService::PARTIALLY_COMPLIANT],
            'compliant' => [],
            'not_applicable' => [],
        ];

        foreach ($expected as $result => $reasons) {
            $requirement = $this->complianceRequirement($source, "Krav {$result}", $owner);
            $this->insertAssessment($requirement, $result, now()->subDay()->toDateTimeString());

            $this->assertSame($reasons, $this->reasons($requirement), $result);
        }
    }

    public function test_review_overdue_only_from_the_day_after_the_next_review_date(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $owner = $this->complianceMember($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Månedlig', $owner, null, 1);
        $this->insertAssessment($requirement, 'compliant', '2026-01-10 08:00:00');
        $service = app(ComplianceAttentionService::class);

        $this->assertSame([], $service->reasonsForRequirement($requirement, CarbonImmutable::parse('2026-02-10')), 'due today is not overdue');
        $this->assertSame([ComplianceAttentionService::REVIEW_OVERDUE], $service->reasonsForRequirement($requirement, CarbonImmutable::parse('2026-02-11')));

        // Through the pages, on the system's clock: the requirement itself and the worklist.
        $reader = $this->complianceReader($customer);
        $this->travelTo(CarbonImmutable::parse('2026-02-10 12:00:00'));
        $this->assertSame([], $this->show($reader, $requirement)['attention']);
        $this->assertFalse($this->worklistRow($reader, $requirement)['is_overdue']);
        $this->travelTo(CarbonImmutable::parse('2026-02-11 00:01:00'));
        $this->assertSame([ComplianceAttentionService::REVIEW_OVERDUE], $this->show($reader, $requirement)['attention']);
        $this->assertTrue($this->worklistRow($reader, $requirement)['is_overdue']);
        $this->assertSame(1, $this->worklist($reader)['stats']['overdue']);
        $this->assertSame([(int) $requirement->id], array_column($this->worklist($reader, ['status' => 'overdue'])['requirements'], 'id'));
    }

    public function test_a_requirement_without_an_owner_needs_no_attention_for_it(): void
    {
        // The ansvarlig is optional: responsibility follows the work (assessment, audit, Avvik), so a
        // standard activated with hundreds of unowned requirements raises nothing for that.
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Uten ansvarlig');
        $this->insertAssessment($requirement, 'compliant', now()->subDay()->toDateTimeString());

        $this->assertSame([], $this->reasons($requirement));
        $this->assertSame(['not_assessed', 'non_compliant', 'partially_compliant', 'review_overdue'], ComplianceAttentionService::REASONS);
    }

    public function test_several_reasons_at_once_in_display_order(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $source = $this->complianceSource($customer);

        $unassessed = $this->complianceRequirement($source, 'Ny og uten eier');
        $this->assertSame([ComplianceAttentionService::NOT_ASSESSED], $this->reasons($unassessed));

        $partial = $this->complianceRequirement($source, 'Delvis og forfalt', null, null, 3);
        $this->insertAssessment($partial, 'partially_compliant', now()->subMonths(4)->toDateTimeString());
        $this->assertSame([ComplianceAttentionService::PARTIALLY_COMPLIANT, ComplianceAttentionService::REVIEW_OVERDUE], $this->reasons($partial));
    }

    public function test_a_retired_requirement_needs_no_attention_but_keeps_its_history(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        // Everything that would flag an active requirement: non-compliant and long overdue.
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Utgått krav', null, null, 1);
        $this->insertAssessment($requirement, 'non_compliant', now()->subYear()->toDateTimeString());
        $this->assertCount(2, $this->reasons($requirement));

        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $editor, 'Standarden er erstattet');
        $requirement->refresh();

        $this->assertSame([], $this->reasons($requirement));
        $this->assertSame([], app(ComplianceAttentionService::class)->reasonsForRequirements(collect([$requirement]))[(int) $requirement->id]);

        $show = $this->show($editor, $requirement);
        $this->assertSame([], $show['attention']);
        $this->assertSame('non_compliant', $show['compliance']['status'], 'the history stays visible');
        // Out of the work: neither listed nor counted in Etterlevelsesvurdering.
        $worklist = $this->worklist($editor);
        $this->assertSame([], $worklist['requirements']);
        $this->assertSame(0, $worklist['stats']['non_compliant']);
    }

    // ---------------------------------------------------------------------
    // Putting it right clears the reason — nothing is stored
    // ---------------------------------------------------------------------

    public function test_new_assessments_clear_their_reasons(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [
            CustomerPermissionCatalog::COMPLIANCE_VIEW,
            CustomerPermissionCatalog::COMPLIANCE_EDIT,
            CustomerPermissionCatalog::COMPLIANCE_ASSESS,
        ]);
        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Tilgangsstyring', null, 'A.5.15', 12);

        $this->assertSame(['not_assessed'], $this->show($user, $requirement)['attention']);
        $this->assertSame('not_assessed', $this->worklistRow($user, $requirement)['group']);

        $this->actingAs($user)->post($this->assessUrl($requirement), ['result' => 'non_compliant', 'rationale' => 'Mangler rutine'])->assertSessionHasNoErrors();
        $this->assertSame(['non_compliant'], $this->show($user, $requirement)['attention']);
        $this->assertSame('non_compliant', $this->worklistRow($user, $requirement)['group']);

        $this->travel(1)->seconds();
        $this->actingAs($user)->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Rutinen er etablert'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->show($user, $requirement)['attention']);
        $this->assertSame('compliant', $this->worklistRow($user, $requirement)['group']);

        // Attention wrote nothing on the way: two assessments, the ones registered above.
        $this->assertSame(['non_compliant', 'compliant'], ComplianceAssessment::query()->where('requirement_id', $requirement->id)->orderBy('id')->pluck('result')->all());
        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $requirement->fresh()->status);
        $this->assertNull($requirement->fresh()->owner_user_id, 'no owner was needed at any point');
    }

    // ---------------------------------------------------------------------
    // Etterlevelsesvurdering: the worklist across standards
    // ---------------------------------------------------------------------

    public function test_the_worklist_counts_each_requirement_once_in_register_order(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $owner = $this->complianceMember($customer);
        $iso = $this->complianceSource($customer, 'ISO 27001');
        $gdpr = $this->complianceSource($customer, 'GDPR', null, 'law');

        $ok = $this->complianceRequirement($iso, 'Oppfylt', $owner, 'A.1');
        $this->insertAssessment($ok, 'compliant', now()->subDay()->toDateTimeString());
        $isoB = $this->complianceRequirement($iso, 'Ikke vurdert', $owner, 'A.2');
        $partial = $this->complianceRequirement($iso, 'Delvis', null, 'A.3');
        $this->insertAssessment($partial, 'partially_compliant', now()->subDay()->toDateTimeString());
        $gdprA = $this->complianceRequirement($gdpr, 'Ikke relevant', null, 'Art. 5');
        $this->insertAssessment($gdprA, 'not_applicable', now()->subDay()->toDateTimeString());
        $retired = $this->complianceRequirement($gdpr, 'Utgått', null, 'Art. 1');
        $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();

        $page = $this->worklist($reader);

        // Ikke relevant is outside the figures, never a gap; the retired requirement is nowhere.
        $this->assertSame(
            ['assessable' => 3, 'assessed' => 2, 'compliant' => 1, 'partially_compliant' => 1, 'non_compliant' => 0, 'not_assessed' => 1, 'overdue' => 0, 'not_applicable' => 1, 'relevance_open' => 0],
            $page['stats'],
        );
        // By default the vurderbare requirements, in the register's order (ISO 27001 by reference).
        $this->assertSame([(int) $ok->id, (int) $isoB->id, (int) $partial->id], array_column($page['requirements'], 'id'));
        $this->assertSame(['source' => 'ISO 27001 (2022)', 'reference' => 'A.2', 'title' => 'Ikke vurdert'], $page['requirements'][1]['origin']);
        $this->assertSame(route('app.compliance.requirements.show', ['requirementId' => $isoB->id, 'from' => 'assessments']), $page['requirements'][1]['url']);

        // Per source: the rows add up to the total — each requirement counted under exactly one.
        $perSource = collect($page['per_source'])->keyBy('label');
        $this->assertSame(['GDPR', 'ISO 27001 (2022)'], $perSource->keys()->all());
        foreach (['assessable', 'assessed', 'compliant', 'not_assessed', 'not_applicable'] as $key) {
            $this->assertSame($page['stats'][$key], $perSource->sum(fn (array $row): int => $row['stats'][$key]), $key);
        }

        // The filters: Mangler, Ikke vurdert, Ikke relevant, and Standard — which narrows the figures too.
        $this->assertSame([(int) $partial->id], array_column($this->worklist($reader, ['status' => 'gaps'])['requirements'], 'id'));
        $this->assertSame([(int) $isoB->id], array_column($this->worklist($reader, ['status' => 'not_assessed'])['requirements'], 'id'));
        $this->assertSame([(int) $gdprA->id], array_column($this->worklist($reader, ['status' => 'not_applicable'])['requirements'], 'id'));
        $gdprOnly = $this->worklist($reader, ['source' => $gdpr->id]);
        $this->assertSame([], $gdprOnly['requirements']);
        $this->assertSame(0, $gdprOnly['stats']['assessable']);
        $this->assertSame(1, $gdprOnly['stats']['not_applicable']);
        // A search narrows the list, never the figures.
        $searched = $this->worklist($reader, ['search' => 'delvis']);
        $this->assertSame([(int) $partial->id], array_column($searched['requirements'], 'id'));
        $this->assertSame(3, $searched['stats']['assessable']);
        // Unknown values are ignored rather than answered.
        $this->assertSame('', $this->worklist($reader, ['status' => 'bogus'])['filters']['status']);
    }

    public function test_only_an_assessor_is_offered_vurder(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');

        $reader = $this->complianceReader($customer);
        $this->assertNull($this->worklistRow($reader, $requirement)['assess_url']);
        $this->assertFalse($this->worklist($reader)['permissions']['can_assess']);

        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $this->assertSame(
            route('app.compliance.requirements.show', ['requirementId' => $requirement->id, 'from' => 'assessments', 'assess' => 1]),
            $this->worklistRow($assessor, $requirement)['assess_url'],
        );
    }

    public function test_the_number_of_queries_does_not_grow_with_the_worklist(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $source = $this->complianceSource($customer);
        $owner = $this->complianceMember($customer);

        $add = function (int $count) use ($source, $owner): void {
            for ($i = 0; $i < $count; $i++) {
                $requirement = $this->complianceRequirement($source, 'Krav '.uniqid(), $i % 2 === 0 ? $owner : null, null, 1);
                $this->insertAssessment($requirement, ComplianceAssessment::RESULTS[$i % 4], now()->subMonths(2)->toDateTimeString());
            }
        };

        $add(2);
        // Once to warm what the first request of a test loads only once.
        $this->worklist($reader);
        $small = $this->countQueries(fn () => $this->worklist($reader));
        $add(10);
        $large = $this->countQueries(fn () => $this->worklist($reader));

        $this->assertSame($small, $large);
        // Ten of the twelve are assessable (the two Ikke relevant are not), and every one is overdue.
        $this->assertSame(10, $this->worklist($reader)['stats']['overdue']);
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_another_customers_requirements_never_move_the_count(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $owner = $this->complianceMember($customer);

        $own = $this->complianceRequirement($this->complianceSource($customer), 'Eget', $owner);
        // Another customer's register is full of gaps.
        foreach (['non_compliant', 'partially_compliant'] as $result) {
            $foreign = $this->complianceRequirement($this->complianceSource($other, 'Fremmed '.$result), 'Fremmed hemmelig krav', null, null, 1);
            $this->insertAssessment($foreign, $result, now()->subYear()->toDateTimeString());
        }
        $foreignSource = $this->complianceSource($other, 'Fremmed ny');
        $this->complianceRequirement($foreignSource, 'Fremmed hemmelig krav');

        $page = $this->worklist($reader);
        $this->assertSame(1, $page['stats']['assessable']);
        $this->assertSame(0, $page['stats']['non_compliant'] + $page['stats']['partially_compliant']);
        $this->assertSame([(int) $own->id], array_column($page['requirements'], 'id'));
        $this->assertStringNotContainsString('Fremmed', json_encode($page));
        // Another customer's source is no filter: ignored, not answered.
        $this->assertNull($this->worklist($reader, ['source' => $foreignSource->id])['filters']['source']);
    }

    public function test_a_user_who_cannot_see_the_register_gets_no_worklist(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->complianceContext();
        $this->complianceRequirement($this->complianceSource($customer), 'Ikke vurdert');

        // No compliance.view: nothing at all, not even a count.
        $member = $this->complianceMember($customer);
        $this->actingAs($member)->get('/app/compliance/assessments')->assertForbidden();

        // An inactive role grants nothing either.
        $inactive = $this->complianceMember($customer);
        $this->complianceGrant($customer, $inactive, [CustomerPermissionCatalog::COMPLIANCE_VIEW], false);
        $this->actingAs($inactive)->get('/app/compliance/assessments')->assertForbidden();

        // System Owner without an own compliance role sees nothing.
        $this->actingAs($systemOwner)->get('/app/compliance/assessments')->assertForbidden();

        // With one, the same worklist as anyone else with that role.
        $this->complianceGrant($customer, $systemOwner, [CustomerPermissionCatalog::COMPLIANCE_VIEW]);
        $this->assertSame(1, $this->worklist($systemOwner)['stats']['not_assessed']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function reasons(ComplianceRequirement $requirement): array
    {
        return app(ComplianceAttentionService::class)->reasonsForRequirement($requirement->fresh());
    }

    /** @return array<string, mixed> */
    private function worklist(User $user, array $query = []): array
    {
        $url = '/app/compliance/assessments'.($query !== [] ? '?'.http_build_query($query) : '');

        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function worklistRow(User $user, ComplianceRequirement $requirement): array
    {
        return collect($this->worklist($user)['requirements'])->keyBy('id')->get((int) $requirement->id);
    }

    /** @return array<string, mixed> */
    private function show(User $user, ComplianceRequirement $requirement): array
    {
        return $this->actingAs($user)->get("/app/compliance/requirements/{$requirement->id}")->assertOk()->viewData('page')['props'];
    }

    private function assessUrl(ComplianceRequirement $requirement): string
    {
        return "/app/compliance/requirements/{$requirement->id}/assessments";
    }

    /** A row at a chosen moment, which the service never allows — for the schedule. */
    private function insertAssessment(ComplianceRequirement $requirement, string $result, string $assessedAt): void
    {
        $requirement->loadMissing('source');
        DB::table('compliance_assessments')->insert([
            'customer_id' => $requirement->customer_id,
            'requirement_id' => $requirement->id,
            'result' => $result,
            'rationale' => 'Begrunnelse',
            'assessed_at' => $assessedAt,
            'requirement_reference' => $requirement->reference,
            'requirement_title' => $requirement->title,
            'requirement_text' => $requirement->requirement_text,
            'source_name' => $requirement->source->name,
            'source_version' => $requirement->source->version,
        ]);
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $before = $count;
        $callback();

        return $count - $before;
    }
}
