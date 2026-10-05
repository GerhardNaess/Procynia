<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseActivity;
use App\Models\ImprovementCaseProcess;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Avvik / forbedring → gjelder → Kvalitet-prosess / -aktivitet. The same small reuse as KPI → måler.
 *
 * What these tests defend:
 *
 *  - Only ids are stored; names are read live from Kvalitet. The database refuses a link across
 *    customers and a duplicate.
 *  - Changing links takes improvement.edit in the case's area AND Kvalitet read, with the case open
 *    or in progress. Without Kvalitet read the page says nothing about context at all.
 *  - Only a process of the case's own customer, and only a step in its flow, is accepted.
 *  - A step leaving the flow, or the process going, removes the link through the shared hook.
 *  - Nothing about the link reaches Kvalitet.
 */
class ImprovementCaseQualityContextTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const EDITOR = [
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_EDIT,
        CustomerPermissionCatalog::QUALITY_VIEW,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Bus::fake();
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

    public function test_a_case_concerns_processes_and_activities_read_live(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $it, 'Hendelse ikke klassifisert');
        $incident = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse', 'Klassifiser alvorlighet']);
        $backup = $this->process($customer, $owner, 'Backup', ['Test gjenoppretting']);
        $user = $this->member($customer);
        $this->grant($customer, $user, self::EDITOR, [$it]);

        $props = $this->caseProps($user, $case);
        $this->assertSame([], $props['quality_context']);
        $this->assertTrue($props['permissions']['can_link_context']);
        $this->assertSame(['Backup', 'Incidenthåndtering'], array_column($props['quality_context_options'], 'title'));

        $this->actingAs($user)->put($this->url($case, $incident), ['whole_process' => false, 'activity_keys' => ['klassifiser-alvorlighet']])
            ->assertRedirect()->assertSessionHas('success', 'Koblingen er oppdatert.');
        $this->actingAs($user)->put($this->url($case, $backup), ['whole_process' => true])->assertRedirect();

        $this->assertSame(1, ImprovementCaseProcess::query()->where('improvement_case_id', $case->id)->count());
        $this->assertDatabaseHas('improvement_case_activities', ['improvement_case_id' => $case->id, 'quality_process_id' => $incident->id, 'activity_key' => 'klassifiser-alvorlighet', 'customer_id' => $customer->id, 'created_by' => $user->id]);
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'improvement_case_id', 'quality_process_id', 'activity_key', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('improvement_case_activities'),
        );

        $incident->update(['title' => 'Hendelseshåndtering']);
        [$backupRow, $incidentRow] = $this->caseProps($user, $case)['quality_context'];
        $this->assertSame('Backup', $backupRow['title']);
        $this->assertTrue($backupRow['whole_process']);
        $this->assertSame('Hendelseshåndtering', $incidentRow['title']);
        $this->assertSame(['Klassifiser alvorlighet'], array_column($incidentRow['activities'], 'label'));

        // Clearing every box removes the process from the case.
        $this->actingAs($user)->put($this->url($case, $backup), ['whole_process' => false])->assertRedirect();
        $this->assertSame(['Hendelseshåndtering'], array_column($this->caseProps($user, $case)['quality_context'], 'title'));

        // A link is not history: the fresh case can still be deleted, and takes its links along.
        $this->assertTrue($case->fresh()->isDeletable());
    }

    public function test_the_database_refuses_links_across_customers_and_duplicates(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $other, 'owner' => $otherOwner] = $this->context();
        $case = $this->improvementCase($customer, $this->area($customer, 'IT'), 'Sak');
        $foreign = $this->process($other, $otherOwner, 'Fremmed prosess', ['Steg']);
        $own = $this->process($customer, $owner, 'Egen prosess', ['Steg']);

        $this->assertTrue($this->refused(fn () => ImprovementCaseProcess::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $foreign->id])));
        $this->assertTrue($this->refused(fn () => ImprovementCaseProcess::query()->create(['customer_id' => $other->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $foreign->id])));
        $this->assertTrue($this->refused(fn () => ImprovementCaseActivity::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $foreign->id, 'activity_key' => 'steg'])));

        ImprovementCaseProcess::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $own->id]);
        $this->assertTrue($this->refused(fn () => ImprovementCaseProcess::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $own->id])));
    }

    public function test_only_a_process_of_the_own_customer_and_a_step_in_its_flow_is_accepted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $other, 'owner' => $otherOwner] = $this->context();
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $it, 'Sak');
        $own = $this->process($customer, $owner, 'Egen prosess', ['Steg']);
        $foreign = $this->process($other, $otherOwner, 'Fremmed prosess', ['Steg']);
        $user = $this->member($customer);
        $this->grant($customer, $user, self::EDITOR, [$it]);

        $this->actingAs($user)->put($this->url($case, $foreign), ['whole_process' => true])
            ->assertSessionHasErrors(['quality_process_id' => 'Velg en prosess fra listen.']);
        $this->actingAs($user)->put($this->url($case, $own), ['activity_keys' => ['finnes-ikke']])
            ->assertSessionHasErrors(['activity_keys' => 'Velg aktiviteter fra prosessens flyt.']);

        $this->assertSame(0, ImprovementCaseProcess::query()->where('improvement_case_id', $case->id)->count() + ImprovementCaseActivity::query()->where('improvement_case_id', $case->id)->count());
    }

    public function test_edit_without_quality_view_reveals_nothing_and_cannot_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $it, 'Sak');
        $process = $this->process($customer, $owner, 'Konfidensiell prosess', ['Steg']);
        ImprovementCaseProcess::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $process->id]);

        $user = $this->editor($customer, $it);

        $response = $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['quality_context']);
        $this->assertSame([], $props['quality_context_options']);
        $this->assertFalse($props['permissions']['can_link_context']);
        $this->assertStringNotContainsString('Konfidensiell prosess', $response->getContent());

        $this->actingAs($user)->put($this->url($case, $process), ['whole_process' => false])->assertForbidden();
        $this->assertSame(1, ImprovementCaseProcess::query()->where('improvement_case_id', $case->id)->count());

        // Kvalitet read without improvement.edit reads but cannot change.
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);
        $props = $this->caseProps($reader, $case);
        $this->assertSame(['Konfidensiell prosess'], array_column($props['quality_context'], 'title'));
        $this->assertFalse($props['permissions']['can_link_context']);
        $this->actingAs($reader)->put($this->url($case, $process), ['whole_process' => false])->assertForbidden();
    }

    public function test_the_wrong_area_and_another_customer_are_a_404_and_an_ended_case_is_not_relinked(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $other] = $this->context();
        $it = $this->area($customer, 'IT');
        $hr = $this->area($customer, 'HR');
        $case = $this->improvementCase($customer, $it, 'Sak');
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);

        $wrongArea = $this->member($customer);
        $this->grant($customer, $wrongArea, self::EDITOR, [$hr]);
        $this->actingAs($wrongArea)->put($this->url($case, $process), ['whole_process' => true])->assertNotFound();

        $foreigner = $this->member($other);
        $this->grantAll($other, $foreigner, [...self::EDITOR, CustomerPermissionCatalog::IMPROVEMENT_CLOSE]);
        $this->actingAs($foreigner)->put($this->url($case, $process), ['whole_process' => true])->assertNotFound();

        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::EDITOR, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$it]);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig']);
        $this->assertFalse($this->caseProps($user, $case)['permissions']['can_link_context']);
        $this->actingAs($user)->put($this->url($case, $process), ['whole_process' => true])
            ->assertRedirect()->assertSessionHas('error', 'Gjenåpne saken før du endrer den.');

        $this->assertSame(0, ImprovementCaseProcess::query()->where('improvement_case_id', $case->id)->count());
    }

    public function test_removing_a_step_or_the_process_removes_the_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $it, 'Sak');
        $incident = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse', 'Klassifiser alvorlighet']);
        ImprovementCaseProcess::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $incident->id]);
        foreach (['registrer-hendelse', 'klassifiser-alvorlighet'] as $key) {
            ImprovementCaseActivity::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $incident->id, 'activity_key' => $key]);
        }

        // A Kvalitet user who knows nothing about the case.
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT, CustomerPermissionCatalog::QUALITY_DELETE]);

        $this->actingAs($editor)->put("/app/quality/items/{$incident->id}/blueprint", $this->flow(['Klassifiser alvorlighet']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['klassifiser-alvorlighet'], ImprovementCaseActivity::query()->where('improvement_case_id', $case->id)->pluck('activity_key')->all());

        $this->actingAs($editor)->delete("/app/quality/items/{$incident->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, ImprovementCaseProcess::query()->where('improvement_case_id', $case->id)->count() + ImprovementCaseActivity::query()->where('improvement_case_id', $case->id)->count());
        $this->assertNotNull($case->fresh());
    }

    public function test_nothing_about_the_link_reaches_kvalitet(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $it, 'Konfidensiell avvikstittel');
        $process = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);
        ImprovementCaseActivity::query()->create(['customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'quality_process_id' => $process->id, 'activity_key' => 'registrer-hendelse']);

        $user = $this->member($customer);
        $this->grant($customer, $user, self::EDITOR, [$it]);

        foreach ([
            "/app/quality/items/{$process->id}",
            "/app/quality/items/{$process->id}?tab=flow&activity=registrer-hendelse",
            '/app/quality?tab=overview',
            '/app/quality?tab=processes&search=avvikstittel',
        ] as $url) {
            $content = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Konfidensiell', $content, $url);
            $this->assertStringNotContainsString("/improvements/{$case->id}", $content, $url);
        }
    }

    private function refused(callable $write): bool
    {
        try {
            DB::transaction(fn () => $write());

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    /** @param  list<string>  $steps */
    private function process(Customer $customer, User $actor, string $title, array $steps): QualityItem
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);

        app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            $this->flow($steps),
            QualityProcessBlueprint::SOURCE_MANUAL,
            $actor,
        );

        return $process;
    }

    /**
     * start → the given steps → end, all in one lane.
     *
     * @param  list<string>  $steps
     * @return array<string, list<array<string, string>>>
     */
    private function flow(array $steps): array
    {
        $nodes = [['key' => 'start', 'lane' => 'drift', 'type' => 'start', 'label' => 'Start']];

        foreach ($steps as $step) {
            $nodes[] = ['key' => Str::slug($step), 'lane' => 'drift', 'type' => 'step', 'label' => $step];
        }

        $nodes[] = ['key' => 'slutt', 'lane' => 'drift', 'type' => 'end', 'label' => 'Slutt'];
        $edges = [];

        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['from' => $nodes[$i - 1]['key'], 'to' => $nodes[$i]['key']];
        }

        return ['lanes' => [['key' => 'drift', 'label' => 'Driftsansvarlig']], 'nodes' => $nodes, 'edges' => $edges];
    }

    private function url(ImprovementCase $case, QualityItem $process): string
    {
        return "/app/improvements/{$case->id}/processes/{$process->id}";
    }

    /** @return array<string, mixed> */
    private function caseProps(User $user, ImprovementCase $case): array
    {
        return $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
    }
}
