<?php

namespace Tests\Feature\Services;

use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `ai:usage-integrity` as the deploy/operational gate for the usage ledger and for switching on
 * AI_CONTEXT_ENFORCEMENT=strict: exit 0 only when every check holds.
 */
class AiUsageIntegrityGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_clean_ledger_passes(): void
    {
        // No system work exists today; when it does, it is registered like any operation.
        // (Operation keys contain dots, so the registry is set as a whole, never by dotted path.)
        config()->set('ai_operations.operations', config('ai_operations.operations') + ['system.price_probe' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 10, 'output_tokens' => 10]]]);
        $customer = $this->customer();
        $this->attempt($customer, 'tender.requirement_answer');
        $this->attempt(null, 'system.price_probe', ['attribution' => 'system', 'feature' => 'system']);
        // Legacy rows are outside the gate.
        $this->attempt(null, 'unclassified', ['ledger_version' => null, 'attribution' => null, 'feature' => 'unclassified', 'settlement_status' => null]);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('integrity OK')->assertExitCode(0);
    }

    public function test_an_unattributed_call_fails_the_gate(): void
    {
        $this->attempt(null, 'wiki.verify_claim', ['attribution' => 'unattributed']);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('1 unattributed AI call(s)')->assertExitCode(1);
    }

    public function test_a_customer_call_without_its_customer_fails_the_gate(): void
    {
        $this->attempt(null, 'wiki.verify_claim', ['attribution' => 'customer']);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('1 without customer_id')->assertExitCode(1);
    }

    public function test_a_call_without_feature_fails_the_gate(): void
    {
        $this->attempt($this->customer(), 'wiki.verify_claim', ['feature' => 'unclassified']);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('1 without feature')->assertExitCode(1);
    }

    public function test_a_call_without_a_registered_operation_fails_the_gate(): void
    {
        $this->attempt($this->customer(), 'unclassified');

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('unclassified ×1')->assertExitCode(1);
    }

    public function test_system_work_outside_system_operations_fails_the_gate(): void
    {
        $this->attempt(null, 'wiki.verify_claim', ['attribution' => 'system']);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('1 system call(s) outside system.*')->assertExitCode(1);
    }

    public function test_a_call_without_settlement_fails_the_gate(): void
    {
        $this->attempt($this->customer(), 'wiki.verify_claim', ['settlement_status' => null]);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('1 without settlement_status')->assertExitCode(1);
    }

    public function test_problems_outside_the_window_do_not_fail_it(): void
    {
        $this->attempt(null, 'wiki.verify_claim', ['attribution' => 'unattributed', 'started_at' => now()->subDays(10)]);

        $this->artisan('ai:usage-integrity --days=7')->assertExitCode(0);
        $this->artisan('ai:usage-integrity --days=0')->assertExitCode(1);
    }

    public function test_the_strict_gate_requires_fourteen_days_of_traffic_with_anbud_extraction_and_wiki_work(): void
    {
        $customer = $this->customer();
        $this->attempt($customer, 'wiki.verify_claim', ['started_at' => now()->subDays(3)]);

        // Too short a window, too little history, no extraction.
        $this->artisan('ai:usage-integrity --days=7 --gate')->assertExitCode(1);
        $this->artisan('ai:usage-integrity --days=14 --gate')->assertExitCode(1);

        $this->attempt($customer, 'wiki.verify_claim', ['started_at' => now()->subDays(15)]);
        $this->artisan('ai:usage-integrity --days=14 --gate')->expectsOutputToContain('0 extraction call(s)')->assertExitCode(1);

        $this->attempt($customer, 'tender.requirement_extraction.segment', ['feature' => 'tender', 'started_at' => now()->subDays(2)]);
        $this->artisan('ai:usage-integrity --days=14 --gate')
            ->expectsOutputToContain('wiki:maintenance-cycle')
            ->expectsOutputToContain('integrity OK')
            ->assertExitCode(0);
    }

    /** @param array<string, mixed> $overrides */
    private function attempt(?Customer $customer, string $operation, array $overrides = []): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer?->id,
            'attribution' => $customer !== null ? 'customer' : 'system',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => strstr($operation, '.', true) ?: $operation,
            'operation_key' => $operation,
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15,
            'cost_status' => 'known', 'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED, 'cost_nok' => 0.01,
            'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        ], $overrides));
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Gate '.Str::random(8),
            'slug' => 'gate-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
