<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiSection;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiUsageReservation;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use App\Services\Ai\Wiki\WikiSectionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 enforcement: what the commercial quota, the reservation lifecycle and the two runtime
 * kill switches actually do at the provider boundary — including from a queue worker and the
 * scheduler, where no controller check can be trusted to have happened.
 */
class AiCostControlEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        config()->set('services.enterprise_wiki.ai_enabled', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Quota semantics
    // =========================================================================

    public function test_one_case_that_fans_out_into_parallel_calls_holds_exactly_one_credit(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(1);
        $case = $this->notice($customer, 'fanout');
        $other = $this->notice($customer, 'other');
        $service = app(AiCostControlService::class);

        // Two provider calls for the same case start before either has finished: both must be
        // allowed, because the case — not the call — is the unit a credit is spent on.
        $first = $service->authorize($this->caseContext($customer, $case));
        $second = $service->authorize($this->caseContext($customer, $case));

        $this->assertNotNull($first->reservationId);
        $this->assertNotNull($second->reservationId);

        // The single credit is nonetheless spent: a different case cannot start.
        try {
            $service->authorize($this->caseContext($customer, $other));
            $this->fail('The one remaining credit is already held by the first case.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::QUOTA_EXHAUSTED, $exception->reason);
        }

        $service->finalize($first);
        $service->finalize($second);

        $this->assertSame(1, CustomerAiCaseUsage::query()->where('customer_id', $customer->id)->count());
    }

    public function test_an_exhausted_customer_can_still_work_on_an_already_activated_case(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(1);
        $activated = $this->notice($customer, 'activated');
        $fresh = $this->notice($customer, 'fresh');
        $service = app(AiCostControlService::class);

        $service->finalize($service->authorize($this->caseContext($customer, $activated)));

        $again = $service->authorize($this->caseContext($customer, $activated));
        $this->assertNull($again->reservationId, 'A second call on an activated case must not reserve a new credit.');
        $this->assertSame(0, $again->remaining);

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED));
        $service->authorize($this->caseContext($customer, $fresh));
    }

    public function test_the_reservation_path_serialises_on_a_locked_period_row(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(3);
        $notice = $this->notice($customer, 'locking');
        $statements = [];
        \DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(AiCostControlService::class)->authorize($this->caseContext($customer, $notice));

        // Without the row lock, two workers could both read the same balance and both reserve the
        // final credit. The lock — not the count — is what makes the last credit atomic.
        $lockedPeriod = array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'customer_ai_quota_periods') && str_contains($sql, 'for update'),
        );
        $lockedCustomer = array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from "customers"') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty($lockedPeriod, 'The quota period row must be read FOR UPDATE.');
        $this->assertNotEmpty($lockedCustomer, 'The customer row must be read FOR UPDATE.');
    }

    // =========================================================================
    // Reservation lifecycle
    // =========================================================================

    public function test_a_certain_failure_releases_the_credit_and_a_timeout_does_not(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(2);
        $released = $this->notice($customer, 'released');
        $uncertain = $this->notice($customer, 'uncertain');
        $service = app(AiCostControlService::class);

        $service->failHttp($service->authorize($this->caseContext($customer, $released)), 400);
        $service->fail(
            $service->authorize($this->caseContext($customer, $uncertain)),
            new ConnectionException('cURL error 28: Operation timed out'),
        );

        $this->assertDatabaseHas('customer_ai_usage_reservations', [
            'saved_notice_id' => $released->id,
            'status' => CustomerAiUsageReservation::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('customer_ai_usage_reservations', [
            'saved_notice_id' => $uncertain->id,
            'status' => CustomerAiUsageReservation::STATUS_UNCERTAIN,
        ]);
        $this->assertDatabaseCount('customer_ai_case_usages', 0);

        // The released credit is available again; the uncertain one is not, because the provider
        // may well have done — and charged for — the work.
        $reusable = $this->notice($customer, 'reusable');
        $this->assertNotNull($service->authorize($this->caseContext($customer, $reusable))->reservationId);

        $blocked = $this->notice($customer, 'blocked');
        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED));
        $service->authorize($this->caseContext($customer, $blocked));
    }

    public function test_finalizing_the_same_reservation_twice_records_one_credit(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(3);
        $notice = $this->notice($customer, 'idempotent');
        $service = app(AiCostControlService::class);

        $decision = $service->authorize($this->caseContext($customer, $notice));
        $service->finalize($decision);
        $service->finalize($decision);

        $this->assertSame(1, CustomerAiCaseUsage::query()->where('saved_notice_id', $notice->id)->count());
        $this->assertDatabaseHas('customer_ai_usage_reservations', [
            'id' => $decision->reservationId,
            'status' => CustomerAiUsageReservation::STATUS_COMMITTED,
        ]);
    }

    // =========================================================================
    // Plan changes
    // =========================================================================

    public function test_a_plan_upgrade_frees_credits_inside_the_same_period(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(3);
        $service = app(AiCostControlService::class);

        foreach (['a', 'b', 'c'] as $key) {
            $service->finalize($service->authorize($this->caseContext($customer, $this->notice($customer, $key))));
        }

        try {
            $service->authorize($this->caseContext($customer, $this->notice($customer, 'blocked')));
            $this->fail('3 of 3 credits are spent.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::QUOTA_EXHAUSTED, $exception->reason);
        }

        $customer->update(['included_ai_credits' => 20]);

        $decision = $service->authorize($this->caseContext($customer->fresh(), $this->notice($customer, 'after-upgrade')));
        $this->assertSame(20, $decision->included);
        $this->assertSame(16, $decision->remaining);
    }

    public function test_a_downgrade_below_current_usage_blocks_new_cases_but_not_activated_ones(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(20, Customer::PLAN_MAX);
        $service = app(AiCostControlService::class);

        $activated = [];
        foreach (range(1, 10) as $index) {
            $notice = $this->notice($customer, 'case'.$index);
            $service->finalize($service->authorize($this->caseContext($customer, $notice)));
            $activated[] = $notice;
        }

        $customer->update(['subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 3]);
        $downgraded = $customer->fresh();

        $existing = $service->authorize($this->caseContext($downgraded, $activated[0]));
        $this->assertSame(10, $existing->used);
        $this->assertSame(0, $existing->remaining);
        $this->assertNull($existing->reservationId);

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED));
        $service->authorize($this->caseContext($downgraded, $this->notice($customer, 'after-downgrade')));
    }

    // =========================================================================
    // Runtime kill switches at the provider boundary
    // =========================================================================

    public function test_the_provider_boundary_blocks_a_suspended_customer_before_any_http_request(): void
    {
        $this->fakeCompletedResponse();
        $customer = $this->customer(3);
        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');

        $this->assertProviderBlocked($customer, AiCostControlException::CUSTOMER_SUSPENDED);

        app(AiRuntimeControlService::class)->setCustomerAccess($customer->fresh(), Customer::AI_ACCESS_ENABLED, reason: 'test');
        $this->assertProviderReached($customer);
    }

    public function test_the_provider_boundary_blocks_every_customer_while_the_global_stop_is_on(): void
    {
        $this->fakeCompletedResponse();
        $customer = $this->customer(3);
        app(AiRuntimeControlService::class)->setGlobalStop(true, reason: 'test');

        $this->assertProviderBlocked($customer, AiCostControlException::GLOBAL_STOP);

        app(AiRuntimeControlService::class)->setGlobalStop(false, reason: 'test');
        $this->assertProviderReached($customer);
    }

    public function test_an_exhausted_quota_blocks_a_new_case_before_any_http_request(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        Http::fake();
        $customer = $this->customer(1);
        $service = app(AiCostControlService::class);
        $service->finalize($service->authorize($this->caseContext($customer, $this->notice($customer, 'spent'))));

        $blocked = $this->notice($customer, 'blocked');

        try {
            app(AiCallContextScope::class)->within(
                $this->caseContext($customer, $blocked),
                fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
            );
            $this->fail('The provider boundary must refuse a new case once the quota is spent.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::QUOTA_EXHAUSTED, $exception->reason);
        }

        Http::assertNothingSent();
    }

    // =========================================================================
    // Queue: a job may not rely on a check made when it was dispatched
    // =========================================================================

    public function test_a_queue_job_re_checks_customer_suspension_before_calling_the_provider(): void
    {
        Queue::fake();
        Http::fake();
        ['customer' => $customer, 'section' => $section] = $this->wikiSectionScaffold();

        // The job was dispatched while the customer was allowed to use AI.
        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');

        $this->assertSectionJobBlocked($section, AiCostControlException::CUSTOMER_SUSPENDED);
    }

    public function test_a_queue_job_re_checks_the_global_stop_before_calling_the_provider(): void
    {
        Queue::fake();
        Http::fake();
        ['section' => $section] = $this->wikiSectionScaffold();

        app(AiRuntimeControlService::class)->setGlobalStop(true, reason: 'test');

        $this->assertSectionJobBlocked($section, AiCostControlException::GLOBAL_STOP);
    }

    public function test_the_same_queue_job_does_reach_the_provider_when_nothing_blocks_it(): void
    {
        Queue::fake();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        ['section' => $section] = $this->wikiSectionScaffold();

        try {
            $this->runSectionJob($section);
        } catch (\Throwable) {
            // The faked envelope is not a usable claims response; only the outbound call matters.
        }

        Http::assertSentCount(1);
    }

    // =========================================================================
    // Scheduler
    // =========================================================================

    public function test_scheduled_wiki_maintenance_cannot_call_the_provider_for_a_suspended_customer(): void
    {
        Queue::fake();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);

        $customer = $this->customer(3);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for maintenance tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ]);

        // Stand in for the QA work the scheduler retries: it makes a real provider call, so the
        // guard is exercised on the scheduler's own path rather than asserted about it.
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->andReturnUsing(fn (): mixed => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]));

        $enabled = app(EnterpriseWikiMaintenanceCycleService::class)->run();
        $this->assertSame(1, $enabled['retried']);
        Http::assertSentCount(1);

        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');
        $run->update(['maintenance_source_hash' => null, 'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);

        $suspended = app(EnterpriseWikiMaintenanceCycleService::class)->run();

        $this->assertSame(1, $suspended['failed']);
        Http::assertSentCount(1);
    }

    public function test_scheduled_post_ingest_qa_cannot_call_the_provider_for_a_suspended_customer(): void
    {
        Queue::fake();
        $this->fakeCompletedResponse();

        $customer = $this->customer(3);
        $this->appliedRunAwaitingQa($customer);

        // Stand in for QA's own AI work so the scheduled command's real path is exercised.
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('findPendingRuns')->andReturn(EnterpriseWikiIngestRun::query()->get())
            ->shouldReceive('runForRun')
            ->andReturnUsing(fn (): mixed => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]));

        $this->artisan('wiki:run-post-ingest-qa --all-pending')->assertExitCode(0);
        Http::assertSentCount(1);

        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');

        $this->artisan('wiki:run-post-ingest-qa --all-pending')->assertExitCode(0);
        Http::assertSentCount(1);
    }

    // =========================================================================
    // Audit trail
    // =========================================================================

    public function test_every_runtime_switch_change_is_audited_with_the_acting_user(): void
    {
        $customer = $this->customer(3);
        $actor = User::query()->create([
            'name' => 'Ops',
            'email' => 'ops-'.Str::lower(Str::random(6)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $controls = app(AiRuntimeControlService::class);

        $controls->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, $actor, 'runaway usage');
        $controls->setCustomerAccess($customer->fresh(), Customer::AI_ACCESS_ENABLED, $actor, 'resolved');
        $controls->setGlobalStop(true, $actor, 'provider incident');
        $controls->setGlobalStop(false, $actor, 'incident closed');

        foreach (['ai_customer_suspended', 'ai_customer_resumed', 'ai_global_stop_enabled', 'ai_global_stop_disabled'] as $eventType) {
            $this->assertDatabaseHas('billing_events', [
                'event_type' => $eventType,
                'source' => 'ai_cost_control',
                'user_id' => $actor->id,
            ]);
        }

        $this->assertSame(
            0,
            \DB::table('billing_events')->where('source', 'ai_cost_control')->whereNull('user_id')->count(),
            'A human administrative change must never be recorded without its actor.',
        );
        $this->assertSame(Customer::AI_ACCESS_ENABLED, $customer->fresh()->ai_access_status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeCompletedResponse(): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
    }

    private function assertProviderBlocked(Customer $customer, string $reason): void
    {
        try {
            app(AiCallContextScope::class)->within(
                new AiCallContext(customerId: $customer->id, feature: 'enterprise_wiki', operation: 'test.block'),
                fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
            );
            $this->fail('The provider boundary must refuse this call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        Http::assertNothingSent();
    }

    private function assertProviderReached(Customer $customer): void
    {
        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, feature: 'enterprise_wiki', operation: 'test.allow'),
            fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
        );

        Http::assertSentCount(1);
    }

    private function assertSectionJobBlocked(EnterpriseWikiIngestSection $section, string $reason): void
    {
        try {
            $this->runSectionJob($section);
            $this->fail('The queue job must refuse to call the provider.');
        } catch (AiCostControlException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        Http::assertNothingSent();
    }

    private function runSectionJob(EnterpriseWikiIngestSection $section): void
    {
        (new ProcessEnterpriseWikiSection($section->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
            app(WikiSectionAiClient::class),
        );
    }

    private function appliedRunAwaitingQa(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for QA tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => null,
        ]);
    }

    /** @return array{customer: Customer, run: EnterpriseWikiIngestRun, section: EnterpriseWikiIngestSection} */
    private function wikiSectionScaffold(): array
    {
        $customer = $this->customer(3);
        $item = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Test Document',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'ai_usage_enabled' => true,
        ]);
        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => "## Kompetanse\nVi leverer ISO 9001-sertifisert service.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
            'original_filename' => 'kompetanse.docx',
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'source_hash' => str_pad('hash', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
        ]);
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'wiki-draft-'.$run->id,
            'title' => 'Test Document',
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => $run->source_hash,
        ]);
        $run->update(['enterprise_wiki_page_id' => $page->id]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
        ]);
        $section = EnterpriseWikiIngestSection::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'section_index' => 0,
            'heading' => 'Kompetanse',
            'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
        ]);

        return ['customer' => $customer, 'run' => $run, 'section' => $section];
    }

    private function caseContext(Customer $customer, SavedNotice $notice): AiCallContext
    {
        return new AiCallContext(
            customerId: $customer->id,
            feature: 'saved_notice',
            operation: 'saved_notice.test',
            savedNoticeId: $notice->id,
            commercialCredit: true,
        );
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Enforcement '.Str::random(8),
            'slug' => 'enforcement-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }

    private function notice(Customer $customer, string $key): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'ENF-'.$key.'-'.Str::random(6),
            'title' => 'Enforcement notice',
            'buyer_name' => 'Procynia',
            'status' => 'ACTIVE',
        ]);
    }
}
