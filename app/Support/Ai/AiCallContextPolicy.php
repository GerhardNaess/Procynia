<?php

namespace App\Support\Ai;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCallContextException;
use App\Services\Ai\Operational\AiOperationalAlertService;
use Illuminate\Support\Facades\Log;

/**
 * The attribution rule every provider call passes before it may spend money.
 *
 *  - It names a well-formed `<feature>.<operation>` with a known feature. Always enforced: there is
 *    no silent `unclassified` any more.
 *  - It has a customer, unless it is explicitly marked as system work (AiCallContext::system()).
 *    In `strict` mode a call without one is refused; in `warn` mode it proceeds, is recorded as
 *    `unattributed` and raises an operational alert naming the operation.
 *
 * Called by OpenAiClient only, so it covers every path that can reach the provider.
 */
class AiCallContextPolicy
{
    public function __construct(private readonly AiOperationalAlertService $alerts) {}

    public function enforce(AiCallContext $context): AiCallContext
    {
        if (! AiOperationCatalog::isWellFormed($context->operation)) {
            throw new AiCallContextException(sprintf(
                'AI provider call has no valid operation (got [%s]); every call must name a registered <feature>.<operation>.',
                (string) ($context->operation ?? 'null'),
            ));
        }

        if ($context->feature !== AiOperationCatalog::featureFor((string) $context->operation)) {
            throw new AiCallContextException(sprintf(
                'AI provider call feature [%s] does not match its operation [%s].',
                (string) ($context->feature ?? 'null'),
                (string) $context->operation,
            ));
        }

        if ($context->attribution() === AiCallContext::ATTRIBUTION_UNATTRIBUTED) {
            if (AiOperationCatalog::enforcementIsStrict()) {
                throw new AiCallContextException(sprintf(
                    'AI provider call [%s] has no customer and is not marked as system work.',
                    (string) $context->operation,
                ));
            }

            Log::warning('[PROCYNIA][AI_CONTEXT] Customer-driven AI call without customer context.', [
                'operation' => $context->operation,
                'feature' => $context->feature,
                'model' => $context->model,
            ]);
            $this->alerts->reportUnattributedCall($context);
        }

        return $context;
    }
}
