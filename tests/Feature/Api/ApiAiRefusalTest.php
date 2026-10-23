<?php

namespace Tests\Feature\Api;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCallContextException;
use App\Support\Ai\AiCallContextPolicy;
use App\Support\Ai\AiOperationCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D11: no Procynia AI call is made from the external API.
 *
 * The refusal sits in AiCallContextPolicy, which OpenAiClient asks before every provider call, and
 * it keys on the request path — so it holds before authentication, for every operation, and for
 * any code path an endpoint might reach in future.
 */
class ApiAiRefusalTest extends TestCase
{
    public function test_every_registered_ai_operation_is_refused_under_api(): void
    {
        $operations = AiOperationCatalog::registeredOperations();
        $this->assertNotEmpty($operations);

        Route::middleware('api')->get('api/_test/ai', function () use ($operations) {
            $refused = [];

            foreach ($operations as $operation) {
                try {
                    app(AiCallContextPolicy::class)->enforce($this->context($operation));
                } catch (AiCallContextException) {
                    $refused[] = $operation;
                }
            }

            return ['refused' => $refused];
        });

        $this->get('/api/_test/ai')->assertOk()->assertJson(['refused' => $operations]);
    }

    public function test_a_refused_call_surfaces_as_a_plain_server_error(): void
    {
        $operation = AiOperationCatalog::registeredOperations()[0];

        Route::middleware('api')->get('api/_test/ai-call', fn () => app(AiCallContextPolicy::class)->enforce($this->context($operation)));

        $response = $this->get('/api/_test/ai-call');

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'server_error');
        $this->assertStringNotContainsString($operation, (string) $response->getContent());
    }

    public function test_the_same_call_is_allowed_outside_the_api(): void
    {
        $operation = AiOperationCatalog::registeredOperations()[0];

        $context = app(AiCallContextPolicy::class)->enforce($this->context($operation));

        $this->assertSame($operation, $context->operation);
    }

    private function context(string $operation): AiCallContext
    {
        return (new AiCallContext(customerId: 1, userId: 1))->withOperation($operation);
    }
}
