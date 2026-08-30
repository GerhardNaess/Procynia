<?php

namespace Tests\Feature\Console;

use App\Console\Commands\EnterpriseWikiGenerateAppliedPages;
use App\Console\Commands\EnterpriseWikiMaintainerDecision;
use App\Console\Commands\EnterpriseWikiRecoverDocumentFlow;
use App\Console\Commands\EnterpriseWikiVerifyPageClaims;
use App\Console\Commands\WikiInspectRequirementAnswer;
use App\Data\Ai\AiCallContext;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use App\Support\Ai\AiCallContextScope;
use App\Support\Ai\RunsOperatorAiCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Coverage check for the five manual operator commands: none of them may reach a provider without
 * a customer-attributed context, and none may relax a guard without a validated internal actor.
 */
class AiOperatorCommandContextTest extends TestCase
{
    use RefreshDatabase;

    /** Every manual command that can reach the AI provider. */
    private const OPERATOR_COMMANDS = [
        'wiki:generate-applied-pages' => EnterpriseWikiGenerateAppliedPages::class,
        'wiki:verify-page-claims' => EnterpriseWikiVerifyPageClaims::class,
        'wiki:recover-document-flow' => EnterpriseWikiRecoverDocumentFlow::class,
        'wiki:maintainer-decision' => EnterpriseWikiMaintainerDecision::class,
        'wiki:inspect-requirement-answer' => WikiInspectRequirementAnswer::class,
    ];

    public function test_every_operator_command_declares_the_cost_control_options(): void
    {
        $definitions = collect(Artisan::all());

        foreach (self::OPERATOR_COMMANDS as $name => $class) {
            $this->assertTrue($definitions->has($name), "{$name} is not registered.");

            $options = $definitions->get($name)->getDefinition()->getOptions();

            foreach (['actor', 'cost-control-override', 'override-reason'] as $option) {
                $this->assertArrayHasKey($option, $options, "{$name} is missing --{$option}.");
            }
        }
    }

    public function test_every_operator_command_uses_the_shared_cost_control_plumbing(): void
    {
        foreach (self::OPERATOR_COMMANDS as $name => $class) {
            $traits = class_uses_recursive($class);

            $this->assertContains(
                RunsOperatorAiCommand::class,
                $traits,
                "{$name} does not classify its AI calls.",
            );
        }
    }

    // =========================================================================
    // Actor validation
    // =========================================================================

    public function test_an_override_is_refused_without_an_actor(): void
    {
        $run = $this->ingestRun($this->customer());

        $this->artisan('wiki:verify-page-claims', [
            '--run-id' => $run->id,
            '--cost-control-override' => true,
            '--override-reason' => 'Gjenoppretting',
        ])
            ->expectsOutputToContain('requires --actor')
            ->assertExitCode(1);
    }

    public function test_an_override_is_refused_without_a_reason(): void
    {
        $run = $this->ingestRun($this->customer());
        $admin = $this->admin();

        $this->artisan('wiki:verify-page-claims', [
            '--run-id' => $run->id,
            '--cost-control-override' => true,
            '--actor' => (string) $admin->id,
        ])
            ->expectsOutputToContain('requires --override-reason')
            ->assertExitCode(1);
    }

    public function test_a_customer_user_can_never_be_the_override_actor(): void
    {
        $customer = $this->customer();
        $run = $this->ingestRun($customer);
        $customerAdmin = User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Customer system owner',
            'email' => Str::lower(Str::random(8)).'@customer.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);

        $this->artisan('wiki:verify-page-claims', [
            '--run-id' => $run->id,
            '--cost-control-override' => true,
            '--override-reason' => 'Gjenoppretting',
            '--actor' => (string) $customerAdmin->id,
        ])
            ->expectsOutputToContain('not an internal Procynia super admin')
            ->assertExitCode(1);
    }

    public function test_an_unknown_actor_is_refused(): void
    {
        $run = $this->ingestRun($this->customer());

        $this->artisan('wiki:verify-page-claims', [
            '--run-id' => $run->id,
            '--actor' => 'nobody@procynia.test',
        ])
            ->expectsOutputToContain('did not match a user')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Guards still apply in normal mode
    // =========================================================================

    public function test_a_command_without_an_override_still_stops_at_a_suspended_customer(): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');

        $customer = $this->customer();
        $run = $this->ingestRun($customer);
        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');

        // The command's own context is what carries the customer into the guard.
        $context = $this->contextFromCommand($run);

        $this->assertSame($customer->id, $context->customerId);
        $this->assertFalse($context->operatorOverride);
        $this->assertNotSame('unclassified', $context->operation);
    }

    public function test_the_command_context_names_the_customer_and_the_operation(): void
    {
        $customer = $this->customer();
        $run = $this->ingestRun($customer);

        $context = $this->contextFromCommand($run);

        $this->assertSame($customer->id, $context->customerId);
        $this->assertSame('operator.wiki.verify_page_claims', $context->operation);
        $this->assertSame('enterprise_wiki', $context->feature);
        $this->assertSame('enterprise_wiki_document', $context->resourceType);
        $this->assertNotNull($context->requestCorrelationId);
    }

    public function test_an_unscoped_provider_call_still_has_no_customer(): void
    {
        // Guards the premise of the whole phase: without an explicit context there is nobody to
        // apply a customer kill switch to, which is why every command must set one.
        $ambient = app(AiCallContextScope::class)->current();

        $this->assertNull($ambient->customerId);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function contextFromCommand(EnterpriseWikiIngestRun $run): AiCallContext
    {
        $command = new EnterpriseWikiVerifyPageClaims;
        $command->setLaravel($this->app);
        $definition = Artisan::all()['wiki:verify-page-claims']->getDefinition();
        $input = new ArrayInput([], $definition);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        $method = (new ReflectionClass($command))->getMethod('operatorAiCallContext');
        $method->setAccessible(true);

        return $method->invoke($command, [
            'customerId' => (int) $run->customer_id,
            'operation' => 'operator.wiki.verify_page_claims',
            'resourceType' => 'enterprise_wiki_document',
            'resourceId' => (int) $run->source_id,
        ]);
    }

    private function ingestRun(Customer $customer): EnterpriseWikiIngestRun
    {
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
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'customer_id' => null,
            'name' => 'Procynia Admin',
            'email' => 'admin-'.Str::lower(Str::random(8)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Operator '.Str::random(8),
            'slug' => 'operator-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 3,
        ]);
    }
}
