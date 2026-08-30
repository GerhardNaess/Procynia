<?php

namespace App\Console\Commands;

use App\Models\AiRuntimeControl;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

/**
 * Operator access to the two AI kill switches.
 *
 * Both switches are database-backed precisely so they can be flipped while the platform is running.
 * A command is what makes that reachable without a deploy, a restart, or an API-key change; the
 * richer admin surface belongs to a later phase. Every change here is written to `billing_events`
 * with its actor, so an emergency stop is never an untraceable action.
 */
#[AsCommand(name: 'ai:runtime-control')]
class AiRuntimeControlCommand extends Command
{
    protected $signature = 'ai:runtime-control
        {action : status|global-stop|global-resume|suspend-customer|resume-customer}
        {--customer= : Customer id or slug, required for suspend-customer and resume-customer}
        {--actor= : Id or email of the user performing the change, recorded in the audit event}
        {--reason= : Short operational reason recorded in the audit event}';

    protected $description = 'Inspect or change the global AI stop and a customer AI suspension at runtime.';

    public function handle(AiRuntimeControlService $controls): int
    {
        $action = (string) $this->argument('action');
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;
        $actor = $this->resolveActor();

        return match ($action) {
            'status' => $this->showStatus(),
            'global-stop' => $this->applyGlobalStop($controls, true, $actor, $reason),
            'global-resume' => $this->applyGlobalStop($controls, false, $actor, $reason),
            'suspend-customer' => $this->applyCustomerAccess($controls, Customer::AI_ACCESS_SUSPENDED, $actor, $reason),
            'resume-customer' => $this->applyCustomerAccess($controls, Customer::AI_ACCESS_ENABLED, $actor, $reason),
            default => $this->invalidAction($action),
        };
    }

    private function showStatus(): int
    {
        $control = AiRuntimeControl::query()->orderBy('id')->first();

        $this->line('[AI_RUNTIME_CONTROL] global_ai_stop: '.($control?->global_ai_stop ? 'ON (all AI blocked)' : 'off'));

        if (! $control instanceof AiRuntimeControl) {
            $this->warn('[AI_RUNTIME_CONTROL] No control row exists. The guard fails closed until the migration is run.');
        }

        $suspended = Customer::query()
            ->where('ai_access_status', Customer::AI_ACCESS_SUSPENDED)
            ->orderBy('id')
            ->get(['id', 'slug']);

        $this->line('[AI_RUNTIME_CONTROL] suspended customers: '.($suspended->isEmpty()
            ? 'none'
            : $suspended->map(fn (Customer $customer): string => $customer->id.':'.$customer->slug)->implode(', ')));

        return self::SUCCESS;
    }

    private function applyGlobalStop(AiRuntimeControlService $controls, bool $enabled, ?User $actor, ?string $reason): int
    {
        $controls->setGlobalStop($enabled, $actor, $reason);

        $this->line('[AI_RUNTIME_CONTROL] global_ai_stop is now '.($enabled ? 'ON' : 'off').'.');

        return self::SUCCESS;
    }

    private function applyCustomerAccess(AiRuntimeControlService $controls, string $status, ?User $actor, ?string $reason): int
    {
        $customer = $this->resolveCustomer();

        if (! $customer instanceof Customer) {
            $this->error('[AI_RUNTIME_CONTROL] --customer must name an existing customer by id or slug.');

            return self::FAILURE;
        }

        $controls->setCustomerAccess($customer, $status, $actor, $reason);

        $this->line("[AI_RUNTIME_CONTROL] customer {$customer->id}:{$customer->slug} ai_access_status is now {$status}.");

        return self::SUCCESS;
    }

    private function resolveCustomer(): ?Customer
    {
        $reference = trim((string) $this->option('customer'));

        if ($reference === '') {
            return null;
        }

        return ctype_digit($reference)
            ? Customer::query()->find((int) $reference)
            : Customer::query()->where('slug', $reference)->first();
    }

    private function resolveActor(): ?User
    {
        $reference = trim((string) $this->option('actor'));

        if ($reference === '') {
            return null;
        }

        $actor = ctype_digit($reference)
            ? User::query()->find((int) $reference)
            : User::query()->where('email', $reference)->first();

        if (! $actor instanceof User) {
            $this->warn('[AI_RUNTIME_CONTROL] --actor did not match a user; the audit event will record no actor.');

            return null;
        }

        // A customer-scoped identity must never appear as the actor behind a platform control:
        // the audit trail would then name someone who could not have been authorised to do it.
        if (! $actor->isSuperAdmin() || $actor->customer_id !== null) {
            $this->warn('[AI_RUNTIME_CONTROL] --actor is not an internal Procynia super admin; the audit event will record no actor.');

            return null;
        }

        return $actor;
    }

    private function invalidAction(string $action): int
    {
        $this->error("[AI_RUNTIME_CONTROL] Unknown action [{$action}].");

        return self::FAILURE;
    }
}
