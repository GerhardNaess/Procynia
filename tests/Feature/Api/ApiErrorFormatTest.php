<?php

namespace Tests\Feature\Api;

use App\Exceptions\HumanDecisionRefused;
use App\Exceptions\RiskHistoryProtected;
use App\Models\Risk;
use App\Services\Api\ApiErrorResponder;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A10: every failure under /api has one format, a fixed message per code, and never the
 * exception's own words — they can name a module, a record or a person (B10).
 */
class ApiErrorFormatTest extends TestCase
{
    private const SECRET = 'Kari Nordmann may not see risk 4812 in Beredskap';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/_test')->group(function (): void {
            Route::get('server-error', fn () => throw new RuntimeException(self::SECRET));
            Route::get('forbidden', fn () => throw new AuthorizationException(self::SECRET));
            Route::get('human-decision', fn () => throw new HumanDecisionRefused(HumanDecision::RISK_ACCEPT, 'api'));
            Route::get('conflict', fn () => throw new RiskHistoryProtected);
            Route::get('missing-model', fn () => throw (new ModelNotFoundException)->setModel(Risk::class, [4812]));
            Route::get('unauthenticated', fn () => throw new AuthenticationException(self::SECRET));
            Route::get('throttled', fn () => throw new ThrottleRequestsException(self::SECRET, null, ['Retry-After' => 17]));
            Route::get('teapot', fn () => abort(418, self::SECRET));
            Route::get('unavailable', fn () => abort(503, self::SECRET));
            Route::get('validated', fn (Request $request) => $request->validate(['limit' => ['integer', 'max:200']]));
        });
    }

    public function test_an_unexpected_error_is_a_500_without_the_exception_message(): void
    {
        $response = $this->get('/api/_test/server-error');

        $this->assertError($response, 500, ApiErrorResponder::SERVER_ERROR);
    }

    public function test_an_authorization_refusal_is_a_403_without_its_reason(): void
    {
        $this->assertError($this->get('/api/_test/forbidden'), 403, ApiErrorResponder::FORBIDDEN);
    }

    public function test_a_human_decision_refusal_is_a_plain_403(): void
    {
        $response = $this->get('/api/_test/human-decision');

        $this->assertError($response, 403, ApiErrorResponder::FORBIDDEN);
        $this->assertStringNotContainsString('risk.accept', (string) $response->getContent());
        $this->assertStringNotContainsString('reserved for a person', (string) $response->getContent());
    }

    public function test_a_domain_conflict_is_a_409_without_the_domain_wording(): void
    {
        $response = $this->get('/api/_test/conflict');

        $this->assertError($response, 409, ApiErrorResponder::CONFLICT);
        $this->assertStringNotContainsString((string) __('procynia.risk.flash.delete_blocked'), (string) $response->getContent());
    }

    public function test_a_missing_model_is_a_404_that_names_neither_model_nor_id(): void
    {
        $response = $this->get('/api/_test/missing-model');

        $this->assertError($response, 404, ApiErrorResponder::NOT_FOUND);
        $this->assertStringNotContainsString('Risk', (string) $response->getContent());
        $this->assertStringNotContainsString('4812', (string) $response->getContent());
    }

    public function test_an_unknown_api_path_is_a_404_in_the_api_format(): void
    {
        $this->assertError($this->get('/api/v1/does-not-exist'), 404, ApiErrorResponder::NOT_FOUND);
    }

    public function test_a_wrong_method_is_a_405_and_keeps_the_allow_header(): void
    {
        $response = $this->post('/api/_test/forbidden');

        $this->assertError($response, 405, ApiErrorResponder::METHOD_NOT_ALLOWED);
        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    }

    public function test_unauthenticated_is_a_401_with_a_bearer_challenge(): void
    {
        $response = $this->get('/api/_test/unauthenticated');

        $this->assertError($response, 401, ApiErrorResponder::UNAUTHENTICATED);
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_throttling_is_a_429_and_keeps_retry_after(): void
    {
        $response = $this->get('/api/_test/throttled');

        $this->assertError($response, 429, ApiErrorResponder::RATE_LIMITED);
        $response->assertHeader('Retry-After', '17');
    }

    public function test_other_http_statuses_map_to_a_generic_code(): void
    {
        $this->assertError($this->get('/api/_test/teapot'), 418, ApiErrorResponder::REQUEST_ERROR);
        $this->assertError($this->get('/api/_test/unavailable'), 503, ApiErrorResponder::SERVICE_UNAVAILABLE);
    }

    public function test_validation_names_the_fields_the_client_sent(): void
    {
        $response = $this->get('/api/_test/validated?limit=500');

        $this->assertError($response, 422, ApiErrorResponder::VALIDATION_FAILED);
        $response->assertJsonStructure(['error' => ['fields' => ['limit']]]);
    }

    public function test_the_format_does_not_depend_on_the_accept_header(): void
    {
        $response = $this->get('/api/_test/forbidden', ['Accept' => 'text/html']);

        $this->assertError($response, 403, ApiErrorResponder::FORBIDDEN);
    }

    public function test_the_web_application_keeps_its_own_error_rendering(): void
    {
        $response = $this->getJson('/this-page-does-not-exist');

        $response->assertNotFound();
        $response->assertJsonMissingPath('error.code');
    }

    private function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJsonStructure(['error' => array_values(array_filter(
            ['code', 'message', 'request_id', $code === ApiErrorResponder::VALIDATION_FAILED ? 'fields' : null],
        ))]);
        $response->assertJsonPath('error.code', $code);
        $response->assertJsonPath('error.message', ApiErrorResponder::MESSAGES[$code]);

        $requestId = (string) $response->json('error.request_id');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestId);
        $response->assertHeader('X-Request-Id', $requestId);

        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $this->assertStringNotContainsString('Kari', (string) $response->getContent());
        $this->assertStringNotContainsString('exception', strtolower((string) $response->getContent()));
        $this->assertStringNotContainsString('trace', strtolower((string) $response->getContent()));
    }
}
