<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunTerminalTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Arbeidspakke A1 (docs/enterprise-wiki-knowledge-integration-plan.md §9.1): one owner per
 * terminal transition, no overwrite of an already-terminal run, and an extension point that
 * fires exactly once for the owner.
 */
class EnterpriseWikiRunTerminalTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_terminal_status_is_reached_by_its_own_method(): void
    {
        $transition = app(EnterpriseWikiRunTerminalTransition::class);

        foreach ([
            'complete' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'escalate' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'fail' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'cancel' => EnterpriseWikiIngestRun::STATUS_CANCELLED,
        ] as $method => $expected) {
            $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_QA);

            $this->assertTrue($transition->{$method}($run->id));

            $run->refresh();
            $this->assertSame($expected, $run->status);
            $this->assertNotNull($run->finished_at);
        }
    }

    public function test_a_repeated_transition_is_a_no_op_that_reports_it_did_not_own_it(): void
    {
        $transition = app(EnterpriseWikiRunTerminalTransition::class);
        $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_QA);

        $this->assertTrue($transition->complete($run->id));
        $finishedAt = $run->fresh()->finished_at;

        $this->travel(5)->minutes();

        $this->assertFalse($transition->complete($run->id));
        $this->assertEquals($finishedAt, $run->fresh()->finished_at);
    }

    public function test_a_stale_attempt_never_overwrites_a_recorded_terminal_outcome(): void
    {
        $transition = app(EnterpriseWikiRunTerminalTransition::class);
        $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);

        $this->assertTrue($transition->fail($run->id, [
            'error_message' => 'original failure',
            'failed_phase' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'transient_failure' => false,
        ]));

        // A late job finishing out of order must not turn a failed run into a completed one,
        // nor replace the diagnostics that explain the real failure.
        $this->assertFalse($transition->complete($run->id, ['error_message' => null, 'failed_phase' => null]));
        $this->assertFalse($transition->escalate($run->id));
        $this->assertFalse($transition->fail($run->id, ['error_message' => 'later failure']));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertSame('original failure', $run->error_message);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $run->failed_phase);
        $this->assertFalse($run->transient_failure);
    }

    public function test_a_status_passed_in_attributes_cannot_bypass_the_requested_transition(): void
    {
        $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_QA);

        app(EnterpriseWikiRunTerminalTransition::class)->escalate($run->id, [
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
    }

    public function test_the_extension_point_fires_once_and_only_for_the_owning_caller(): void
    {
        $recorder = $this->bindRecordingTransition();
        $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_QA);

        app(EnterpriseWikiRunTerminalTransition::class)->escalate($run->id);
        app(EnterpriseWikiRunTerminalTransition::class)->escalate($run->id);
        app(EnterpriseWikiRunTerminalTransition::class)->complete($run->id);

        $this->assertSame([[$run->id, EnterpriseWikiIngestRun::STATUS_ESCALATED]], $recorder->calls);
    }

    public function test_a_competing_transition_racing_the_owner_loses(): void
    {
        // The owner's UPDATE has already taken the row when the competitor runs (the same
        // ordering Postgres enforces between two concurrent workers: the second UPDATE waits on
        // the row lock and then re-evaluates status NOT IN (terminal) against the committed row).
        $recorder = $this->bindRecordingTransition(competeWith: 'fail');
        $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_QA);

        $this->assertTrue(app(EnterpriseWikiRunTerminalTransition::class)->complete($run->id));

        $this->assertSame([false], $recorder->competitorResults);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertCount(1, $recorder->calls);
    }

    public function test_cancel_through_the_flow_service_keeps_its_message_and_owns_the_transition(): void
    {
        $recorder = $this->bindRecordingTransition();
        $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);
        $actor = $this->createUser($run->customer);

        $cancelled = app(EnterpriseWikiDocumentFlowService::class)->cancelRun($run, $actor, 'feil dokument');

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Kjøring avbrutt av '.$actor->name.': feil dokument', $cancelled->error_message);
        $this->assertSame([[$run->id, EnterpriseWikiIngestRun::STATUS_CANCELLED]], $recorder->calls);

        // Cancelling again, or a job failing afterwards, leaves the cancellation in place.
        app(EnterpriseWikiDocumentFlowService::class)->cancelRun($run, $actor, 'igjen');
        (new RunEnterpriseWikiDocumentFlow($run->id))->failed(new RuntimeException('late worker'));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $run->status);
        $this->assertSame('Kjøring avbrutt av '.$actor->name.': feil dokument', $run->error_message);
        $this->assertCount(1, $recorder->calls);
    }

    public function test_job_failure_handlers_fail_a_running_run_once(): void
    {
        $recorder = $this->bindRecordingTransition();

        foreach ([
            fn (int $id) => new RunEnterpriseWikiDocumentFlow($id),
            fn (int $id) => new ContinueEnterpriseWikiDocumentFlowAfterPages($id),
            fn (int $id) => new FinalizeEnterpriseWikiPageGeneration($id),
        ] as $makeJob) {
            $run = $this->createRun(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING);

            $makeJob($run->id)->failed(new RuntimeException('worker crashed'));
            $makeJob($run->id)->failed(new RuntimeException('worker crashed again'));

            $run->refresh();
            $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
            $this->assertSame('worker crashed', $run->error_message);
        }

        $this->assertCount(3, $recorder->calls);
    }

    private function bindRecordingTransition(?string $competeWith = null): RecordingEnterpriseWikiRunTerminalTransition
    {
        $recorder = new RecordingEnterpriseWikiRunTerminalTransition($competeWith);
        $this->app->instance(EnterpriseWikiRunTerminalTransition::class, $recorder);

        return $recorder;
    }

    private function createRun(string $status): EnterpriseWikiIngestRun
    {
        $customer = $this->createCustomer();

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Transition AS',
            'slug' => 'transition-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        // A person who may administer the run's document — the flow service checks that itself
        // (EnterpriseWikiSourceAuthority) — so these tests stay about the transition.
        return User::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Kari Nordmann',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);
    }
}

class RecordingEnterpriseWikiRunTerminalTransition extends EnterpriseWikiRunTerminalTransition
{
    /** @var list<array{0: int, 1: string}> */
    public array $calls = [];

    /** @var list<bool> */
    public array $competitorResults = [];

    public function __construct(private readonly ?string $competeWith = null) {}

    protected function afterTerminal(int $runId, string $status): void
    {
        $this->calls[] = [$runId, $status];

        if ($this->competeWith !== null) {
            $this->competitorResults[] = $this->{$this->competeWith}($runId, ['error_message' => 'competitor']);
        }
    }
}
