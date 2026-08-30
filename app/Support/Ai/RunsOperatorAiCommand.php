<?php

namespace App\Support\Ai;

use App\Data\Ai\AiCallContext;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared cost-control plumbing for the manual Wiki operator commands.
 *
 * Two things every one of them needs. First, a classified AiCallContext: a command run from a
 * shell has no authenticated user, so without this its provider calls are attributed to nobody and
 * the customer kill switch cannot apply to them. Second, an explicit, audited escape hatch —
 * recovery is sometimes needed *because* a customer is suspended or out of quota, but that must be
 * a deliberate act with a named internal actor and a reason, never a silent default.
 *
 * The global emergency stop is deliberately outside this mechanism. It is not bypassable here or
 * anywhere else; a command run during a platform incident still stops at the provider boundary.
 */
trait RunsOperatorAiCommand
{
    /** The option block every operator command adds to its signature. Kept here as the one wording. */
    public const OVERRIDE_OPTIONS = <<<'TXT'
                            {--actor= : Internal Procynia super admin (id or e-mail) responsible for this run}
                            {--cost-control-override : Bypass entitlement, quota and customer suspension for a recovery run}
                            {--override-reason= : Why the override is justified. Required with --cost-control-override}
    TXT;

    /**
     * Build the classified context for this command, or return null when the operator's arguments
     * do not justify what they asked for (the reason is printed before returning).
     *
     * @param  array<string, mixed>  $attributes  customerId, feature, operation, resourceType, resourceId
     */
    protected function operatorAiCallContext(array $attributes): ?AiCallContext
    {
        $override = (bool) $this->option('cost-control-override');
        $reason = trim((string) $this->option('override-reason'));
        $actor = $this->resolveInternalActor();

        if ($actor === false) {
            return null;
        }

        if ($override) {
            if (! $actor instanceof User) {
                $this->error('[AI_COST_CONTROL] --cost-control-override requires --actor=<internal super admin id or e-mail>.');

                return null;
            }

            if ($reason === '') {
                $this->error('[AI_COST_CONTROL] --cost-control-override requires --override-reason="...".');

                return null;
            }

            $this->warn(sprintf(
                '[AI_COST_CONTROL] Override active for actor %d (%s). Entitlement, quota and customer suspension are bypassed; the global emergency stop is not.',
                $actor->id,
                $actor->email,
            ));

            Log::warning('[AI_COST_CONTROL] Operator command started with a cost-control override.', [
                'command' => $this->getName(),
                'actor_user_id' => $actor->id,
                'customer_id' => $attributes['customerId'] ?? null,
                'operation' => $attributes['operation'] ?? null,
            ]);
        }

        return new AiCallContext(
            customerId: $attributes['customerId'] ?? null,
            userId: $actor instanceof User ? $actor->id : null,
            feature: $attributes['feature'] ?? 'enterprise_wiki',
            operation: $attributes['operation'] ?? 'operator.unknown',
            resourceType: $attributes['resourceType'] ?? null,
            resourceId: $attributes['resourceId'] ?? null,
            requestCorrelationId: Str::limit('operator-'.$this->getName().'-'.Str::uuid(), 128, ''),
            savedNoticeId: $attributes['savedNoticeId'] ?? null,
            commercialCredit: (bool) ($attributes['commercialCredit'] ?? false),
            operatorOverride: $override,
            operatorActorUserId: $override && $actor instanceof User ? $actor->id : null,
            operatorOverrideReason: $override ? $reason : null,
        );
    }

    protected function withinOperatorAiCallContext(AiCallContext $context, Closure $callback): mixed
    {
        return app(AiCallContextScope::class)->within($context, $callback);
    }

    /**
     * Resolve --actor to an internal Procynia super admin.
     *
     * A customer's own user must never be usable as the actor behind an override: that would let a
     * customer-scoped identity authorise spending on their own account. Returns false when the
     * option was given but did not resolve to such a user, null when it was not given at all.
     */
    protected function resolveInternalActor(): User|false|null
    {
        $reference = trim((string) $this->option('actor'));

        if ($reference === '') {
            return null;
        }

        $actor = ctype_digit($reference)
            ? User::query()->find((int) $reference)
            : User::query()->where('email', $reference)->first();

        if (! $actor instanceof User) {
            $this->error("[AI_COST_CONTROL] --actor [{$reference}] did not match a user.");

            return false;
        }

        if (! $actor->isSuperAdmin() || $actor->customer_id !== null) {
            $this->error("[AI_COST_CONTROL] --actor [{$reference}] is not an internal Procynia super admin.");

            return false;
        }

        return $actor;
    }
}
