<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP-level coverage for WikiController::retryMaintainerDecision() — the "Prøv beslutningsfasen
 * på nytt" action (Wiki run-592/run-593). Authorization mirrors cancelRun() exactly (whoever could
 * delete the source document may retry its runs); eligibility itself is entirely
 * EnterpriseWikiMaintainerDecisionFailureRecoveryService's decision (called with
 * allowManualOverride=true, since this is always an explicit human click), never re-implemented
 * here.
 */
class WikiRetryMaintainerDecisionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_owner_can_retry_a_transiently_failed_run(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createTransientlyFailedRun($customer);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs']);

        $response->assertRedirect('/app/wiki?tab=runs');
        $response->assertSessionHas('success');

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->status);
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_document_owner_contributor_can_retry_their_own_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $run = $this->createTransientlyFailedRun($customer, ownerUserId: $owner->id);

        $response = $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs']);

        $response->assertRedirect('/app/wiki?tab=runs');
        $response->assertSessionHas('success');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->fresh()->status);
    }

    public function test_contributor_without_ownership_cannot_retry(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $otherOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $run = $this->createTransientlyFailedRun($customer, ownerUserId: $otherOwner->id);

        $response = $this->actingAs($contributor)->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs']);

        $response->assertForbidden();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_run_belonging_to_another_customer_is_not_found(): void
    {
        Queue::fake();

        $ownCustomer = $this->createCustomer('Own AS');
        $otherCustomer = $this->createCustomer('Other AS');
        $user = $this->createUser($ownCustomer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createTransientlyFailedRun($otherCustomer);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs']);

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    // Wiki run-593: retryMaintainerDecision() always passes allowManualOverride=true — an explicit
    // human click on this HTTP action may resume a non-transient failure too, as long as the run
    // is otherwise still safe to resume (no persisted decision/pages, document + source text
    // present).
    public function test_non_transient_failure_can_now_be_manually_retried_via_http(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['transient_failure' => false]);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs']);

        $response->assertRedirect('/app/wiki?tab=runs');
        $response->assertSessionHas('success');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->fresh()->status);
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, fn ($job) => $job->runId === $run->id);
    }

    // The manual-override widening is scoped to the transient-failure requirement only — a run
    // that already has an applied decision is still refused via HTTP, exactly as before.
    public function test_non_transient_failure_with_applied_decision_is_still_rejected_via_http(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createTransientlyFailedRun($customer);
        $run->update([
            'transient_failure' => false,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs']);

        $response->assertRedirect('/app/wiki?tab=runs');
        $response->assertSessionHas('error');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_double_click_only_dispatches_one_job(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createTransientlyFailedRun($customer);

        // Session flash assertions must happen immediately after each call — both requests share
        // the same test session store, so asserting on $first after $second has already run would
        // read the (by-then-overwritten) post-second-request session state instead of a snapshot.
        $this->actingAs($user)
            ->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs'])
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->patch("/app/wiki/runs/{$run->id}/retry-maintainer-decision", ['tab' => 'runs'])
            ->assertSessionHas('error');

        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, 1);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Retry Maintainer Decision Tester',
            'email' => Str::lower(Str::random(8)).'@retry-maintainer-decision-controller-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, ?int $ownerUserId = null): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $ownerUserId,
            'original_filename' => 'run-592-source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Extracted source text for the run-592 recovery fixture.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createTransientlyFailedRun(Customer $customer, ?int $ownerUserId = null): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer, $ownerUserId);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'failed_phase' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
            'transient_failure' => true,
            'maintainer_decision_attempt_count' => 1,
            'error_message' => 'cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received',
            'finished_at' => now(),
        ]);
    }
}
