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
 * Etterlevelse og revisjon → «Trenger oppmerksomhet» on the Krav register.
 *
 * What these tests defend:
 *
 *  - Five reasons, each read off the source that already decides it: the resolver's current status
 *    (Ikke vurdert, Ikke oppfylt, Delvis oppfylt), the review schedule (Revurdering forfalt — due
 *    today is not overdue) and a missing owner. Oppfylt and Ikke relevant raise nothing.
 *  - A requirement can have several reasons; nothing is stored, so putting it right clears them.
 *  - A retired requirement raises nothing at all.
 *  - Attention is computed on the rows ComplianceAccessService reaches and on nothing else: another
 *    customer and a user without compliance.view move no count; System Owner needs an own role.
 *  - The register's filter shows only flagged requirements, in the register's order, and the
 *    number of queries does not grow with the number of requirements.
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

        // Through the page, on the system's clock.
        $reader = $this->complianceReader($customer);
        $this->travelTo(CarbonImmutable::parse('2026-02-10 12:00:00'));
        $this->assertSame([], $this->registerRow($reader, $requirement)['attention']);
        $this->travelTo(CarbonImmutable::parse('2026-02-11 00:01:00'));
        $this->assertSame([ComplianceAttentionService::REVIEW_OVERDUE], $this->registerRow($reader, $requirement)['attention']);
    }

    public function test_a_requirement_without_an_owner_is_missing_an_owner(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Uten ansvarlig');
        $this->insertAssessment($requirement, 'compliant', now()->subDay()->toDateTimeString());

        $this->assertSame([ComplianceAttentionService::MISSING_OWNER], $this->reasons($requirement));
    }

    public function test_several_reasons_at_once_in_display_order(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $source = $this->complianceSource($customer);

        $unassessed = $this->complianceRequirement($source, 'Ny og uten eier');
        $this->assertSame([ComplianceAttentionService::NOT_ASSESSED, ComplianceAttentionService::MISSING_OWNER], $this->reasons($unassessed));

        $partial = $this->complianceRequirement($source, 'Delvis og forfalt', null, null, 3);
        $this->insertAssessment($partial, 'partially_compliant', now()->subMonths(4)->toDateTimeString());
        $this->assertSame(
            [ComplianceAttentionService::PARTIALLY_COMPLIANT, ComplianceAttentionService::REVIEW_OVERDUE, ComplianceAttentionService::MISSING_OWNER],
            $this->reasons($partial),
        );
    }

    public function test_a_retired_requirement_needs_no_attention_but_keeps_its_history(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        // Everything that would flag an active requirement: unowned, non-compliant, long overdue.
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Utgått krav', null, null, 1);
        $this->insertAssessment($requirement, 'non_compliant', now()->subYear()->toDateTimeString());
        $this->assertCount(3, $this->reasons($requirement));

        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $editor, 'Standarden er erstattet');
        $requirement->refresh();

        $this->assertSame([], $this->reasons($requirement));
        $this->assertSame([], app(ComplianceAttentionService::class)->reasonsForRequirements(collect([$requirement]))[(int) $requirement->id]);

        $page = $this->register($editor);
        $this->assertSame(0, $page['attention']['total']);
        $this->assertSame([], $page['attention']['requirements']);
        $this->assertSame([], $page['requirements'][0]['attention']);
        $this->assertSame('non_compliant', $page['requirements'][0]['compliance']['status'], 'the history stays visible');
        $this->assertSame([], $this->register($editor, ['attention' => 1])['requirements']);
        $this->assertSame([], $this->show($editor, $requirement)['attention']);
    }

    // ---------------------------------------------------------------------
    // Putting it right clears the reason — nothing is stored
    // ---------------------------------------------------------------------

    public function test_new_assessments_and_an_owner_clear_their_reasons(): void
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

        $this->assertSame(['not_assessed', 'missing_owner'], $this->registerRow($user, $requirement)['attention']);

        $this->actingAs($user)->post($this->assessUrl($requirement), ['result' => 'non_compliant', 'rationale' => 'Mangler rutine'])->assertSessionHasNoErrors();
        $this->assertSame(['non_compliant', 'missing_owner'], $this->registerRow($user, $requirement)['attention']);

        $this->travel(1)->seconds();
        $this->actingAs($user)->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Rutinen er etablert'])->assertSessionHasNoErrors();
        $this->assertSame(['missing_owner'], $this->registerRow($user, $requirement)['attention']);

        $this->actingAs($user)
            ->patch("/app/compliance/requirements/{$requirement->id}", $this->requirementPayload($source, $user))
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->registerRow($user, $requirement)['attention']);
        $this->assertSame([], $this->show($user, $requirement)['attention']);

        // Attention wrote nothing on the way: two assessments, the ones registered above.
        $this->assertSame(['non_compliant', 'compliant'], ComplianceAssessment::query()->where('requirement_id', $requirement->id)->orderBy('id')->pluck('result')->all());
        $this->assertSame(ComplianceRequirement::STATUS_ACTIVE, $requirement->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // The register
    // ---------------------------------------------------------------------

    public function test_the_register_counts_and_lists_flagged_requirements_in_register_order(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $owner = $this->complianceMember($customer);
        $iso = $this->complianceSource($customer, 'ISO 27001');
        $gdpr = $this->complianceSource($customer, 'GDPR', null, 'law');

        $ok = $this->complianceRequirement($iso, 'Oppfylt', $owner, 'A.1');
        $this->insertAssessment($ok, 'compliant', now()->subDay()->toDateTimeString());
        $isoB = $this->complianceRequirement($iso, 'Ikke vurdert', $owner, 'A.2');
        $gdprA = $this->complianceRequirement($gdpr, 'Uten eier', null, 'Art. 5');
        $this->insertAssessment($gdprA, 'not_applicable', now()->subDay()->toDateTimeString());
        $retired = $this->complianceRequirement($gdpr, 'Utgått', null, 'Art. 1');
        $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();

        $page = $this->register($reader);

        // GDPR before ISO 27001: the register's order (source name, then reference).
        $this->assertSame(2, $page['attention']['total']);
        $this->assertSame([(int) $gdprA->id, (int) $isoB->id], array_column($page['attention']['requirements'], 'id'));
        $this->assertSame([['missing_owner'], ['not_assessed']], array_column($page['attention']['requirements'], 'reasons'));
        $this->assertSame(route('app.compliance.requirements.show', ['requirementId' => $isoB->id]), $page['attention']['requirements'][1]['url']);
        $this->assertSame(ComplianceAttentionService::REASONS, $page['attention_reasons']);

        // Without the filter: every row, active first, each with its own reasons.
        $this->assertSame([(int) $gdprA->id, (int) $ok->id, (int) $isoB->id, (int) $retired->id], array_column($page['requirements'], 'id'));
        $this->assertSame([['missing_owner'], [], ['not_assessed'], []], array_column($page['requirements'], 'attention'));
        $this->assertFalse($page['filters']['attention']);

        // With it: only the flagged ones, in the same order.
        $filtered = $this->register($reader, ['attention' => 1]);
        $this->assertTrue($filtered['filters']['attention']);
        $this->assertSame([(int) $gdprA->id, (int) $isoB->id], array_column($filtered['requirements'], 'id'));
        // The filter narrows the rows, never the worklist or the register's count.
        $this->assertSame(2, $filtered['attention']['total']);
        $this->assertSame(4, $filtered['visible_count']);

        // Combined with a search, and a search never shrinks the worklist.
        $searched = $this->register($reader, ['attention' => 1, 'source' => $iso->id]);
        $this->assertSame([(int) $isoB->id], array_column($searched['requirements'], 'id'));
        $this->assertSame(2, $searched['attention']['total']);
    }

    public function test_nothing_needs_attention_gives_an_empty_worklist(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Oppfylt', $reader);
        $this->insertAssessment($requirement, 'compliant', now()->subDay()->toDateTimeString());

        $page = $this->register($reader);
        $this->assertSame(['total' => 0, 'requirements' => []], $page['attention']);
        $this->assertSame([], $this->register($reader, ['attention' => 1])['requirements']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_register(): void
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
        $this->register($reader);
        $small = $this->countQueries(fn () => $this->register($reader));
        $add(10);
        $large = $this->countQueries(fn () => $this->register($reader));

        $this->assertSame($small, $large);
        $this->assertSame(12, $this->register($reader)['attention']['total'], 'every one is overdue');
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
        // Another customer's register is full of reasons.
        foreach (['non_compliant', 'partially_compliant'] as $result) {
            $foreign = $this->complianceRequirement($this->complianceSource($other, 'Fremmed '.$result), 'Fremmed hemmelig krav', null, null, 1);
            $this->insertAssessment($foreign, $result, now()->subYear()->toDateTimeString());
        }
        $this->complianceRequirement($this->complianceSource($other, 'Fremmed ny'), 'Fremmed hemmelig krav');

        $page = $this->register($reader);
        $this->assertSame(1, $page['attention']['total']);
        $this->assertSame([(int) $own->id], array_column($page['attention']['requirements'], 'id'));
        $this->assertSame([(int) $own->id], array_column($this->register($reader, ['attention' => 1])['requirements'], 'id'));
        $this->assertStringNotContainsString('Fremmed hemmelig krav', json_encode($page));
    }

    public function test_a_user_who_cannot_see_the_register_gets_no_attention(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->complianceContext();
        $this->complianceRequirement($this->complianceSource($customer), 'Ikke vurdert');

        // No compliance.view: nothing at all, not even a count.
        $member = $this->complianceMember($customer);
        $this->actingAs($member)->get('/app/compliance/requirements?attention=1')->assertForbidden();

        // An inactive role grants nothing either.
        $inactive = $this->complianceMember($customer);
        $this->complianceGrant($customer, $inactive, [CustomerPermissionCatalog::COMPLIANCE_VIEW], false);
        $this->actingAs($inactive)->get('/app/compliance/requirements')->assertForbidden();

        // System Owner without an own compliance role sees nothing.
        $this->actingAs($systemOwner)->get('/app/compliance/requirements')->assertForbidden();

        // With one, the same worklist as anyone else with that role.
        $this->complianceGrant($customer, $systemOwner, [CustomerPermissionCatalog::COMPLIANCE_VIEW]);
        $this->assertSame(1, $this->register($systemOwner)['attention']['total']);
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
    private function register(User $user, array $query = []): array
    {
        $url = '/app/compliance/requirements'.($query !== [] ? '?'.http_build_query($query) : '');

        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function registerRow(User $user, ComplianceRequirement $requirement): array
    {
        $rows = collect($this->register($user)['requirements'])->keyBy('id');

        return $rows->get((int) $requirement->id);
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
