<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * An AI proposal is adopted only onto the working version it was made for, finding A22
 * (docs/external-api-phase-0-plan.md §2f).
 *
 * A proposal — an interpretation of the description, or a change to the flow — carries the
 * fingerprint of the working version it was made against. Adopting it compares that with the
 * working version as it is when written, under a lock, in the same transaction as the write. A flow
 * someone saved in between is never overwritten: the user is told, the newer flow stays, and a new
 * proposal can be asked for against it.
 */
class QualityFlowProposalVersionTest extends TestCase
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

    public function test_a_proposal_is_adopted_onto_the_version_it_was_made_for(): void
    {
        ['owner' => $owner, 'process' => $process] = $this->world();
        $madeFor = $this->versionOf($process);

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", $this->flow('Forslaget') + [
            'description' => 'Slik gjør vi det.',
            'base_hash' => $madeFor,
        ])->assertSessionHas('success')->assertSessionHasNoErrors();

        $blueprint = $this->blueprintOf($process);
        $this->assertSame(QualityProcessBlueprint::SOURCE_AI, $blueprint->source);
        $this->assertSame('Forslaget', collect($blueprint->nodes())->firstWhere('key', 'steg')['label']);
    }

    /** Someone saved the flow after the proposal was made: the proposal is refused, the newer flow kept. */
    public function test_a_proposal_never_overwrites_a_newer_flow(): void
    {
        ['customer' => $customer, 'owner' => $owner, 'process' => $process] = $this->world();
        $madeFor = $this->versionOf($process);

        // Meanwhile, by hand.
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Rettet for hånd'), QualityProcessBlueprint::SOURCE_MANUAL, $owner);

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", $this->flow('Forslaget') + [
            'description' => 'Slik gjør vi det.',
            'base_hash' => $madeFor,
        ])->assertSessionMissing('success');

        // Told why, with the description kept for a new proposal against the flow as it is now.
        $error = session('flow_error');
        $this->assertSame(__('procynia.quality.errors.flow_proposal_stale'), $error['message']);
        $this->assertSame('Slik gjør vi det.', $error['description']);

        $blueprint = $this->blueprintOf($process);
        $this->assertSame(QualityProcessBlueprint::SOURCE_MANUAL, $blueprint->source);
        $this->assertSame('Rettet for hånd', collect($blueprint->nodes())->firstWhere('key', 'steg')['label']);

        // Against the flow as it is now, the same proposal goes in.
        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", $this->flow('Forslaget') + [
            'description' => 'Slik gjør vi det.',
            'base_hash' => $this->versionOf($process),
        ])->assertSessionHas('success');
    }

    /** A proposal for an empty process is refused once somebody has given it a flow. */
    public function test_a_first_flow_proposal_is_refused_once_the_process_has_a_flow(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->world();
        $empty = $this->process($customer, 'Uten flyt');
        $madeFor = QualityProcessBlueprintService::versionHash(null);
        $this->assertSame($madeFor, $this->versionOf($empty));

        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $empty, $this->flow('Første flyt'), QualityProcessBlueprint::SOURCE_MANUAL, $owner);

        $this->expectException(ProcessFlowInterpretationException::class);
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $empty, $this->flow('Forslaget'), QualityProcessBlueprint::SOURCE_AI, $owner, basedOn: $madeFor);
    }

    /**
     * «Godta endringer»: the proposer compares before it applies the operations, and the write
     * compares again under the lock — the flow may move in between.
     */
    public function test_an_accepted_change_is_checked_again_where_it_is_written(): void
    {
        ['customer' => $customer, 'owner' => $owner, 'process' => $process] = $this->world();
        $madeFor = $this->versionOf($process);
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Endret i mellomtiden'), QualityProcessBlueprint::SOURCE_MANUAL, $owner);

        try {
            app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Endringen'), QualityProcessBlueprint::SOURCE_AI, $owner, basedOn: $madeFor);
            $this->fail('a stale change is refused');
        } catch (ProcessFlowInterpretationException $e) {
            $this->assertSame(__('procynia.quality.errors.flow_proposal_stale'), $e->getMessage());
        }

        $this->assertSame('Endret i mellomtiden', collect($this->blueprintOf($process)->nodes())->firstWhere('key', 'steg')['label']);
    }

    /** An AI proposal without the version it was made for is not adopted at all. */
    public function test_an_ai_flow_without_a_version_is_refused(): void
    {
        ['customer' => $customer, 'owner' => $owner, 'process' => $process] = $this->world();

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", $this->flow('Forslaget'))
            ->assertSessionHasErrors('base_hash');

        $this->expectException(InvalidArgumentException::class);
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Forslaget'), QualityProcessBlueprint::SOURCE_AI, $owner);
    }

    /**
     * Manual editing (finding A27): the editor is opened on a version, a save sends it back, and a
     * flow someone else saved in between is not overwritten. The person is told and keeps the edits.
     */
    public function test_a_manual_save_never_overwrites_a_flow_saved_in_between(): void
    {
        ['owner' => $owner, 'process' => $process] = $this->world();

        $openedOn = $this->actingAs($owner)->get("/app/quality/items/{$process->id}?tab=flow")->viewData('page')['props']['blueprint']['version_hash'];
        $this->assertSame($this->versionOf($process), $openedOn);

        // Someone else saves first.
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->flow('Den andres endring') + ['base_hash' => $openedOn])
            ->assertSessionHasNoErrors();

        // This save was made on the version before it: refused, and the other change stays.
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->flow('Min endring') + ['base_hash' => $openedOn])
            ->assertSessionHasErrors(['base_hash' => __('procynia.quality.errors.flow_changed_since_opened')]);
        $this->assertSame('Den andres endring', collect($this->blueprintOf($process)->nodes())->firstWhere('key', 'steg')['label']);

        // On the version as it is now, it goes in.
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->flow('Min endring') + ['base_hash' => $this->versionOf($process)])
            ->assertSessionHasNoErrors();
        $this->assertSame('Min endring', collect($this->blueprintOf($process)->nodes())->firstWhere('key', 'steg')['label']);
    }

    /** A first flow is saved against «no flow yet», and refused once someone has saved one. */
    public function test_a_first_manual_flow_is_refused_once_someone_has_saved_one(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->world();
        $empty = $this->process($customer, 'Uten flyt');
        $noFlow = QualityProcessBlueprintService::versionHash(null);

        $this->actingAs($owner)->put("/app/quality/items/{$empty->id}/blueprint", $this->flow('Første') + ['base_hash' => $noFlow])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->put("/app/quality/items/{$empty->id}/blueprint", $this->flow('Også første') + ['base_hash' => $noFlow])
            ->assertSessionHasErrors('base_hash');
        $this->assertSame('Første', collect($this->blueprintOf($empty)->nodes())->firstWhere('key', 'steg')['label']);
    }

    private function versionOf(QualityItem $process): string
    {
        return QualityProcessBlueprintService::versionHash(QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->first());
    }

    private function blueprintOf(QualityItem $process): QualityProcessBlueprint
    {
        return QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Kvalitet Forslag AS',
            'slug' => 'kvalitet-forslag-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $process = $this->process($customer, 'Innkjøp');
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Opprinnelig'), QualityProcessBlueprint::SOURCE_MANUAL, $owner);

        return compact('customer', 'owner', 'process');
    }

    private function process(Customer $customer, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);
    }

    /** @return array{lanes: list<array<string, string>>, nodes: list<array<string, string>>, edges: list<array<string, string>>} */
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
}
