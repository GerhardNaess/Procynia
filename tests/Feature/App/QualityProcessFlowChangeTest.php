<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Ai\Quality\ProcessFlowChangeAiClient;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Asking for a change to an existing flow in plain language — the proposal half only.
 *
 * What these tests defend:
 *
 *  - A proposed change is a proposal. Nothing about the working version — payload, status,
 *    approval, source — moves when one is made.
 *  - The model is given the working version and answers in operations, never a regenerated flow;
 *    what it did not name is untouched.
 *  - The operations are held to the strict validator, but only for problems they introduce, with
 *    one repair and then a refusal that says why.
 *  - The user is shown a readable list of what would change, with steps named by label.
 *  - The same gates as every other write to a blueprint.
 *
 * No live provider call is made anywhere in this file.
 */
class QualityProcessFlowChangeTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const INSTRUCTION = 'Legg inn en sikkerhetskontroll før leverandøren godkjennes.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        config()->set('services.quality.flow_ai_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        // Http::fake() would otherwise answer Inertia's SSR call — see
        // QualityProcessFlowInterpretationTest::setUp().
        config()->set('inertia.ssr.enabled', false);

        Http::preventStrayRequests();

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

    public function test_a_change_is_proposed_as_operations_and_nothing_is_stored(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $blueprint = $this->storedFlow($customer, $process, $owner);
        app(QualityProcessBlueprintService::class)->approve((int) $customer->id, $process, $owner);
        $before = $blueprint->fresh();

        $this->fakeResponses([$this->securityCheckProposal()]);

        $props = $this->propose($owner, $process);

        $proposal = $props['flow_change_proposal'];
        $this->assertNotNull($proposal);
        $this->assertNull($props['flow_change_error']);

        // Nothing moved: the working version, its approval and its provenance are as they were.
        $after = $blueprint->fresh();
        $this->assertEquals($before->payload, $after->payload);
        $this->assertSame(QualityProcessBlueprint::STATUS_APPROVED, $after->status);
        $this->assertSame(QualityProcessBlueprint::SOURCE_MANUAL, $after->source);
        $this->assertEquals($before->updated_at, $after->updated_at);

        // The list, in the order given, with steps named by label rather than key. The role the
        // flow has never had comes first, where the step that needs it was proposed.
        $this->assertSame(
            ['add_role', 'add_step', 'remove_flow', 'add_flow', 'add_flow'],
            array_column($proposal['changes'], 'kind'),
        );
        $this->assertSame('Gjennomfør sikkerhetskontroll', $proposal['changes'][1]['label']);
        $this->assertSame('Sikkerhetsansvarlig', $proposal['changes'][1]['role']);
        $this->assertSame('Registrer leverandøren', $proposal['changes'][2]['from']);
        $this->assertSame('Godkjenn leverandøren', $proposal['changes'][2]['to']);

        // The resulting flow: the new step sits between the two it was put between, the old arrow
        // is gone, and every step the instruction did not mention is exactly as stored.
        $edges = array_map(static fn (array $edge): string => $edge['from'].'->'.$edge['to'], $proposal['edges']);
        $this->assertContains('registrer->sikkerhetskontroll', $edges);
        $this->assertContains('sikkerhetskontroll->godkjenn', $edges);
        $this->assertNotContains('registrer->godkjenn', $edges);

        // Key-sorted, because jsonb reorders object keys on the way back out.
        $sorted = static function (array $node): array {
            ksort($node);

            return $node;
        };
        $proposed = array_map($sorted, $proposal['nodes']);

        foreach ($before->payload['nodes'] as $node) {
            $this->assertContains($sorted($node), $proposed);
        }

        $this->assertSame(sha1((string) json_encode([
            $before->payload['lanes'], $before->payload['nodes'], $before->payload['edges'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $proposal['base_hash']);
    }

    public function test_the_model_is_given_the_working_version_and_asked_for_operations(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);

        $this->fakeResponses([$this->securityCheckProposal()]);
        $this->propose($owner, $process);

        $payload = $this->lastRequestPayload();
        $user = $payload['input'][1]['content'][0]['text'];

        $this->assertStringContainsString('"id":"registrer"', $user);
        $this->assertStringContainsString('"role":"Innkjøper"', $user);
        $this->assertStringContainsString(self::INSTRUCTION, $user);
        $this->assertTrue($payload['text']['format']['strict']);
        $this->assertSame(
            ProcessFlowChangeAiClient::OPS,
            $payload['text']['format']['schema']['properties']['operations']['items']['properties']['op']['enum'],
        );
        $this->assertStringContainsString('Never return the whole flow again', $payload['input'][0]['content'][0]['text']);
    }

    public function test_a_new_role_becomes_a_lane_and_is_listed(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);

        $this->fakeResponses([$this->securityCheckProposal()]);
        $proposal = $this->propose($owner, $process)['flow_change_proposal'];

        $this->assertContains('Sikkerhetsansvarlig', array_column($proposal['lanes'], 'label'));
        // A role the flow has never had is a change in its own right, so it is in the list too.
        $this->assertContains(['kind' => 'add_role', 'label' => 'Sikkerhetsansvarlig'], $proposal['changes']);
    }

    public function test_editing_and_removing_are_listed_with_what_they_were(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);

        $this->fakeResponses([[
            'summary' => 'Økonomi godkjenner, og arkiveringen fjernes.',
            'operations' => [
                $this->op('update_step', step: 'godkjenn', label: 'Godkjenn leverandøren i ERP', role: 'Økonomi'),
                $this->op('remove_step', step: 'arkiver'),
                $this->op('add_flow', from: 'godkjenn', to: 'end'),
            ],
            'questions' => [],
        ]]);

        $proposal = $this->propose($owner, $process)['flow_change_proposal'];

        $this->assertSame(['add_role', 'update_step', 'remove_step', 'add_flow'], array_column($proposal['changes'], 'kind'));
        $this->assertSame('Godkjenn leverandøren', $proposal['changes'][1]['previous_label']);
        $this->assertEqualsCanonicalizing(
            [
                ['field' => 'label', 'from' => 'Godkjenn leverandøren', 'to' => 'Godkjenn leverandøren i ERP'],
                ['field' => 'role', 'from' => 'Innkjøper', 'to' => 'Økonomi'],
            ],
            $proposal['changes'][1]['fields'],
        );
        $this->assertSame('Arkiver leverandøren', $proposal['changes'][2]['label']);

        // The removed step's connections went with it.
        $this->assertNotContains('arkiver', array_column($proposal['nodes'], 'key'));
        $this->assertSame([], array_values(array_filter(
            $proposal['edges'],
            static fn (array $edge): bool => $edge['from'] === 'arkiver' || $edge['to'] === 'arkiver',
        )));
    }

    public function test_an_operation_that_refers_to_nothing_is_repaired_once(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);

        $broken = $this->securityCheckProposal();
        $broken['operations'][1]['from'] = 'registrer_leverandor';

        $this->fakeResponses([$broken, $this->securityCheckProposal()]);

        $proposal = $this->propose($owner, $process)['flow_change_proposal'];

        $this->assertNotNull($proposal);
        Http::assertSentCount(2);
        $this->assertStringContainsString('registrer_leverandor', $this->lastRequestPayload()['input'][1]['content'][0]['text']);
    }

    public function test_a_change_that_breaks_the_flow_is_refused_after_one_repair(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $blueprint = $this->storedFlow($customer, $process, $owner);
        $before = $blueprint->fresh()->payload;

        // A decision with one way out, twice.
        $oneWay = [
            'summary' => 'Legger til en beslutning.',
            'operations' => [
                $this->op('add_step', step: 'kritisk', type: 'decision', label: 'Er leverandøren kritisk?'),
                $this->op('remove_flow', from: 'registrer', to: 'godkjenn'),
                $this->op('add_flow', from: 'registrer', to: 'kritisk'),
                $this->op('add_flow', from: 'kritisk', to: 'godkjenn', condition: 'Ja'),
            ],
            'questions' => [],
        ];

        $this->fakeResponses([$oneWay, $oneWay]);

        $props = $this->propose($owner, $process);

        Http::assertSentCount(2);
        $this->assertNull($props['flow_change_proposal']);
        $this->assertNotNull($props['flow_change_error']);
        $this->assertSame(self::INSTRUCTION, $props['flow_change_error']['instruction']);
        $this->assertNotEmpty($props['flow_change_error']['problems']);
        $this->assertStringContainsString('Er leverandøren kritisk?', implode(' ', $props['flow_change_error']['problems']));
        $this->assertEquals($before, $blueprint->fresh()->payload);
    }

    public function test_a_problem_the_flow_already_had_is_not_blamed_on_the_change(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);

        // Saved by hand, so held only to normalise(): "Arkiver" is a dead end.
        $this->storedFlow($customer, $process, $owner, deadEnd: true);

        $this->fakeResponses([$this->securityCheckProposal()]);

        $props = $this->propose($owner, $process);

        Http::assertSentCount(1);
        $this->assertNotNull($props['flow_change_proposal']);
    }

    public function test_the_start_cannot_be_removed(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);

        $removeStart = [
            'summary' => 'Fjerner starten.',
            'operations' => [$this->op('remove_step', step: 'start')],
            'questions' => [],
        ];

        $this->fakeResponses([$removeStart, $removeStart]);

        $props = $this->propose($owner, $process);

        $this->assertContains(
            __('procynia.quality.flow_problems.change_cannot_remove_start'),
            $props['flow_change_error']['problems'],
        );
    }

    public function test_an_unclear_instruction_returns_questions_and_no_changes(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);

        $this->fakeResponses([[
            'summary' => '',
            'operations' => [],
            'questions' => [['question' => 'Hvor i prosessen skal kontrollen ligge?']],
        ]]);

        $proposal = $this->propose($owner, $process)['flow_change_proposal'];

        $this->assertSame([], $proposal['changes']);
        $this->assertSame(['Hvor i prosessen skal kontrollen ligge?'], $proposal['questions']);
    }

    public function test_a_process_without_a_flow_has_nothing_to_change(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);

        Http::fake([]);

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/changes/propose", ['instruction' => self::INSTRUCTION])
            ->assertSessionHasErrors('instruction');

        Http::assertNothingSent();
    }

    public function test_proposing_a_change_passes_the_same_gates_as_every_other_flow_write(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer);
        $this->storedFlow($customer, $process, $owner);
        $contributor = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);

        ['customer' => $other, 'owner' => $otherOwner] = $this->context();
        $foreign = $this->process($other);
        $this->storedFlow($other, $foreign, $otherOwner);

        Http::fake([]);

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/changes/propose", ['instruction' => self::INSTRUCTION])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$foreign->id}/blueprint/changes/propose", ['instruction' => self::INSTRUCTION])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function propose(User $owner, QualityItem $item, string $instruction = self::INSTRUCTION): array
    {
        $this->actingAs($owner)
            ->post("/app/quality/items/{$item->id}/blueprint/changes/propose", ['instruction' => $instruction])
            ->assertRedirect();

        return $this->actingAs($owner)
            ->get("/app/quality/items/{$item->id}?tab=flow")
            ->viewData('page')['props'];
    }

    /** The worked example: one activity, by a new role, put between two steps that exist. */
    private function securityCheckProposal(): array
    {
        return [
            'summary' => 'Legger inn en sikkerhetskontroll før økonomi godkjenner leverandøren.',
            'operations' => [
                $this->op('add_step', step: 'sikkerhetskontroll', type: 'activity', label: 'Gjennomfør sikkerhetskontroll', role: 'Sikkerhetsansvarlig'),
                $this->op('remove_flow', from: 'registrer', to: 'godkjenn'),
                $this->op('add_flow', from: 'registrer', to: 'sikkerhetskontroll'),
                $this->op('add_flow', from: 'sikkerhetskontroll', to: 'godkjenn'),
            ],
            'questions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function op(
        string $op,
        ?string $step = null,
        ?string $type = null,
        ?string $role = null,
        ?string $label = null,
        ?string $description = null,
        ?string $from = null,
        ?string $to = null,
        ?string $condition = null,
    ): array {
        return compact('op', 'step', 'type', 'role', 'label', 'description', 'from', 'to', 'condition');
    }

    private function storedFlow(Customer $customer, QualityItem $process, User $owner, bool $deadEnd = false): QualityProcessBlueprint
    {
        $edges = [
            ['from' => 'start', 'to' => 'registrer'],
            ['from' => 'registrer', 'to' => 'godkjenn'],
            ['from' => 'godkjenn', 'to' => 'arkiver'],
        ];

        if (! $deadEnd) {
            $edges[] = ['from' => 'arkiver', 'to' => 'end'];
        } else {
            $edges[] = ['from' => 'godkjenn', 'to' => 'end'];
        }

        return app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            [
                'lanes' => [['key' => 'innkjop', 'label' => 'Innkjøper']],
                'nodes' => [
                    ['key' => 'start', 'lane' => 'innkjop', 'type' => 'start', 'label' => 'Ny leverandør'],
                    ['key' => 'registrer', 'lane' => 'innkjop', 'type' => 'step', 'label' => 'Registrer leverandøren'],
                    ['key' => 'godkjenn', 'lane' => 'innkjop', 'type' => 'step', 'label' => 'Godkjenn leverandøren'],
                    ['key' => 'arkiver', 'lane' => 'innkjop', 'type' => 'step', 'label' => 'Arkiver leverandøren'],
                    ['key' => 'end', 'lane' => 'innkjop', 'type' => 'end', 'label' => 'Leverandøren er godkjent'],
                ],
                'edges' => $edges,
            ],
            QualityProcessBlueprint::SOURCE_MANUAL,
            $owner,
            'Innkjøper registrerer leverandøren, godkjenner den og arkiverer den.',
        );
    }

    /** @return array<string, mixed> */
    private function lastRequestPayload(): array
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'No provider call was made.');

        /** @var Request $request */
        $request = $recorded[count($recorded) - 1][0];

        return $request->data();
    }

    /** @param list<array<string, mixed>> $responses */
    private function fakeResponses(array $responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->pushResponse(Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($response, JSON_UNESCAPED_UNICODE)]],
                ]],
                'usage' => ['input_tokens' => 400, 'output_tokens' => 200],
            ], 200));
        }

        Http::fake(['*' => $sequence]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(): array
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
            'name' => 'Kvalitet Endring AS',
            'slug' => 'kvalitet-endring-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 20,
        ])->save();

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'quality'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        return [
            'customer' => $customer,
            'owner' => $this->user($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
        ];
    }

    private function user(Customer $customer, string $role, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::upper(Str::random(4)),
            'email' => 'endring-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function process(Customer $customer): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Leverandøropprettelse',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }
}
