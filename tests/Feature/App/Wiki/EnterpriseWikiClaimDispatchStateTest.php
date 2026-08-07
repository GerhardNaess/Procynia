<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiClaimVerification;
use App\Jobs\EnterpriseWiki\VerifyEnterpriseWikiClaim;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Durable execution-state for claim verification dispatch (Wiki AI capacity work item):
 * verification_dispatched_at closes the gap where a claim enqueued in Redis but not yet leased
 * looked identical to one never dispatched at all — see the migration
 * 2026_08_07_000002_add_verification_dispatch_state_to_enterprise_wiki_claims_table and
 * EnterpriseWikiVerifyPageClaimsService::reserveClaimForDispatch()/redispatchable recovery logic
 * in EnterpriseWikiDocumentFlowService::dispatchClaimVerificationWork().
 *
 * Tests 2, 7/9, and 8 open a second, fully independent raw PDO connection (never a second named
 * Laravel connection) so the compare-and-swap is proven to serialize concurrent dispatchers at
 * the real PostgreSQL level, exactly like two queue workers or two recovery sentinels racing the
 * same claim — mirroring EnterpriseWikiPageVersionConcurrencyTest's approach.
 */
class EnterpriseWikiClaimDispatchStateTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    // === Test 1: first dispatch — pending -> dispatched, one job ===

    public function test_first_dispatch_marks_claim_dispatched_and_reserves_exclusively(): void
    {
        $claim = $this->makeClaim();

        $this->assertTrue(app(EnterpriseWikiVerifyPageClaimsService::class)->reserveClaimForDispatch($claim->id));

        $fresh = $claim->fresh();
        $this->assertNotNull($fresh->verification_dispatched_at);
        $this->assertNull($fresh->verified_at);
        $this->assertNull($fresh->verification_claimed_at);

        // A second attempt for the same still-fresh dispatch must not win again.
        $this->assertFalse(app(EnterpriseWikiVerifyPageClaimsService::class)->reserveClaimForDispatch($claim->id));
    }

    // === Test 2 & 7/9: concurrent dispatch — only one wins (never-dispatched, and stale-dispatched) ===

    public function test_concurrent_dispatch_of_a_never_dispatched_claim_only_one_wins(): void
    {
        $this->assertConcurrentDispatchOnlyOneWins($this->makeClaim());
    }

    public function test_concurrent_recovery_dispatch_of_a_stale_dispatched_claim_only_one_wins(): void
    {
        $claim = $this->makeClaim(['verification_dispatched_at' => now()->subDays(1)]);

        $this->assertConcurrentDispatchOnlyOneWins($claim);
    }

    private function assertConcurrentDispatchOnlyOneWins(EnterpriseWikiClaim $claim): void
    {
        DB::commit();

        $pdoA = DB::connection()->getPdo();
        $pdoB = $this->openIndependentConnection();

        $sql = 'UPDATE enterprise_wiki_claims SET verification_dispatched_at = now() '.
            'WHERE id = ? AND verified_at IS NULL '.
            "AND (verification_claimed_at IS NULL OR verification_claimed_at < now() - interval '1200 seconds') ".
            "AND (verification_dispatched_at IS NULL OR verification_dispatched_at < now() - interval '90 seconds')";

        $stmtA = $pdoA->prepare($sql);
        $stmtA->execute([$claim->id]);
        $stmtB = $pdoB->prepare($sql);
        $stmtB->execute([$claim->id]);

        $this->assertSame(1, $stmtA->rowCount() + $stmtB->rowCount(), 'Exactly one of the two racing dispatchers must win the compare-and-swap.');

        DB::table('enterprise_wiki_claims')->where('id', $claim->id)->delete();
    }

    // === Test 8: stale lease can be reclaimed, exactly once, in a controlled way ===

    public function test_concurrent_reclaim_of_a_stale_lease_only_one_wins(): void
    {
        $claim = $this->makeClaim([
            'verification_claimed_at' => now()->subDays(1),
            'verification_claim_token' => 'dead-worker-token',
        ]);

        DB::commit();

        $pdoA = DB::connection()->getPdo();
        $pdoB = $this->openIndependentConnection();

        $sql = 'UPDATE enterprise_wiki_claims SET verification_claimed_at = now(), verification_claim_token = ? '.
            "WHERE id = ? AND verified_at IS NULL AND (verification_claimed_at IS NULL OR verification_claimed_at < now() - interval '1200 seconds')";

        $stmtA = $pdoA->prepare($sql);
        $stmtA->execute(['worker-a', $claim->id]);
        $stmtB = $pdoB->prepare($sql);
        $stmtB->execute(['worker-b', $claim->id]);

        $this->assertSame(1, $stmtA->rowCount() + $stmtB->rowCount(), 'Exactly one worker may reclaim a stale lease.');

        DB::table('enterprise_wiki_claims')->where('id', $claim->id)->delete();
    }

    private function openIndependentConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // === Test 3: dispatched within the valid queue-wait window — recovery does not redispatch ===

    public function test_recently_dispatched_claim_is_not_redispatched_by_recovery_sentinel(): void
    {
        Queue::fake();
        [$run, $version] = $this->runWithVersion();
        $this->makeClaim(['verification_dispatched_at' => now()->subSeconds(5)], $run, $version);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);

        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id, true);

        Queue::assertNotPushed(VerifyEnterpriseWikiClaim::class);
    }

    public function test_stale_dispatched_claim_is_redispatched_by_recovery_sentinel(): void
    {
        Queue::fake();
        [$run, $version] = $this->runWithVersion();
        $claim = $this->makeClaim(['verification_dispatched_at' => now()->subDays(1)], $run, $version);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);

        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id, true);

        Queue::assertPushed(VerifyEnterpriseWikiClaim::class, fn (VerifyEnterpriseWikiClaim $job) => $job->claimId === $claim->id);
        $this->assertNotNull($claim->fresh()->verification_dispatched_at);
    }

    // === Test 4: worker starts — dispatched -> running/leased, with the right token, before AI ===

    public function test_worker_reserves_lease_with_a_token_before_calling_ai(): void
    {
        [$run, $version] = $this->runWithVersion();
        $claim = $this->makeClaim(['verification_dispatched_at' => now()], null, $version);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturnUsing(function () use ($claim) {
                $mid = $claim->fresh();
                $this->assertNotNull($mid->verification_claimed_at, 'Lease must be taken before the AI call.');
                $this->assertNotNull($mid->verification_claim_token);
                $this->assertNull($mid->verified_at);

                return $this->verificationResult(supportingSourceElementKeys: []);
            });

        app(EnterpriseWikiVerifyPageClaimsService::class)->verifyClaimForRun($run, $claim->id);

        $final = $claim->fresh();
        $this->assertNotNull($final->verified_at);
        $this->assertNull($final->verification_claimed_at);
        $this->assertNull($final->verification_claim_token);
        $this->assertNull($final->verification_dispatched_at);
    }

    // === Test 5: duplicate job lacking the right state/token — no-op before AI ===

    public function test_duplicate_job_with_an_active_foreign_lease_is_a_noop_before_ai(): void
    {
        [$run, $version] = $this->runWithVersion();
        $claim = $this->makeClaim([
            'verification_claimed_at' => now(),
            'verification_claim_token' => 'other-worker-token',
        ], null, $version);

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        $outcome = app(EnterpriseWikiVerifyPageClaimsService::class)->verifyClaimForRun($run, $claim->id);

        $this->assertSame(1, $outcome['busy']);
        $this->assertNull($claim->fresh()->verified_at);
    }

    // === Test 6: completed — cannot be redispatched ===

    public function test_completed_claim_cannot_be_dispatched_or_reverified(): void
    {
        [$run, $version] = $this->runWithVersion();
        $claim = $this->makeClaim(['verified_at' => now()], null, $version);

        $this->assertFalse(app(EnterpriseWikiVerifyPageClaimsService::class)->reserveClaimForDispatch($claim->id));

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
        $outcome = app(EnterpriseWikiVerifyPageClaimsService::class)->verifyClaimForRun($run, $claim->id);

        $this->assertSame(1, $outcome['skipped']);
    }

    // Requirement 3: a real AI-call failure must return the claim to a freely re-dispatchable
    // state — never permanently stuck looking "dispatched".
    public function test_real_ai_failure_releases_the_claim_back_to_a_redispatchable_state(): void
    {
        [$run, $version] = $this->runWithVersion();
        $claim = $this->makeClaim(['verification_dispatched_at' => now()], null, $version);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andThrow(new RuntimeException('AI unavailable'));

        try {
            app(EnterpriseWikiVerifyPageClaimsService::class)->verifyClaimForRun($run, $claim->id);
            $this->fail('Expected the AI failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI unavailable', $e->getMessage());
        }

        $fresh = $claim->fresh();
        $this->assertNull($fresh->verification_dispatched_at);
        $this->assertNull($fresh->verification_claimed_at);
        $this->assertNull($fresh->verification_claim_token);
        $this->assertNull($fresh->verified_at);
        $this->assertTrue(app(EnterpriseWikiVerifyPageClaimsService::class)->reserveClaimForDispatch($claim->id));
    }

    // === Test 9 (sequential, application-level view): two sentinel passes only ever dispatch once ===

    public function test_two_sequential_recovery_sentinel_passes_only_dispatch_once(): void
    {
        Queue::fake();
        [$run, $version] = $this->runWithVersion();
        $claim = $this->makeClaim(['verification_dispatched_at' => now()->subDays(1)], $run, $version);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);

        $flow = app(EnterpriseWikiDocumentFlowService::class);
        $flow->continueAfterClaimVerification($run->id, true);
        $flow->continueAfterClaimVerification($run->id, true);

        Queue::assertPushed(VerifyEnterpriseWikiClaim::class, 1);
        Queue::assertPushed(FinalizeEnterpriseWikiClaimVerification::class, 2);
        $this->assertNotNull($claim->fresh()->verification_dispatched_at);
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPageVersion} */
    private function runWithVersion(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create(['name' => 'Dispatch Test', 'slug' => 'dispatch-test-'.Str::lower(Str::random(8)), 'language_id' => $language->id, 'nationality_id' => $nationality->id, 'billing_interval' => Customer::BILLING_MONTHLY, 'is_active' => true]);
        $document = EnterpriseWikiDocument::query()->create(['customer_id' => $customer->id, 'original_filename' => 'source.pdf', 'file_path' => 'test/source.pdf', 'file_hash_sha256' => hash('sha256', Str::random(16)), 'extracted_text' => 'Kildetekst.', 'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED]);
        $run = EnterpriseWikiIngestRun::query()->create(['uuid' => Str::uuid()->toString(), 'customer_id' => $customer->id, 'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL, 'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 'source_id' => $document->id, 'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, 'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);
        $page = EnterpriseWikiPage::query()->create(['customer_id' => $customer->id, 'slug' => 'dispatch-page-'.Str::lower(Str::random(8)), 'title' => 'Dispatch page', 'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'status' => EnterpriseWikiPage::STATUS_DRAFT, 'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB, 'last_source_hash' => str_pad('hash', 64, '0')]);
        EnterpriseWikiIngestRunPage::query()->create(['enterprise_wiki_ingest_run_id' => $run->id, 'enterprise_wiki_page_id' => $page->id, 'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED]);
        $version = EnterpriseWikiPageVersion::query()->create(['enterprise_wiki_page_id' => $page->id, 'version_number' => 1, 'is_current' => true, 'content_markdown' => "# Side\n\nEn påstand."]);

        return [$run, $version];
    }

    private function makeClaim(array $overrides = [], ?EnterpriseWikiIngestRun $run = null, ?EnterpriseWikiPageVersion $version = null): EnterpriseWikiClaim
    {
        if ($version === null) {
            [$builtRun, $version] = $this->runWithVersion();
            $run ??= $builtRun;
        }

        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'En påstand.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $overrides));
    }
}
