<?php

namespace App\Services\Api;

use App\Exceptions\Ai\AiCostControlException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one error format of the external API (A10, docs/external-api-phase-1-decisions.md §8).
 *
 *   {"error": {"code": "…", "message": "…", "request_id": "…"}}
 *
 * The message is a fixed sentence per code. An exception's own message is never sent: the domain
 * services word their refusals for logs and for the web, and those words can name a module, a
 * record or a person (B10). The only exception is the field list of a 422, which names request
 * fields the client itself sent.
 *
 * Only requests under /api reach this. The web application keeps Laravel's own rendering.
 */
class ApiErrorResponder
{
    public const UNAUTHENTICATED = 'unauthenticated';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    public const CONFLICT = 'conflict';

    public const VALIDATION_FAILED = 'validation_failed';

    public const RATE_LIMITED = 'rate_limited';

    /** The customer's AI capacity, or an AI safety budget, does not cover the operation (D20). */
    public const AI_CAPACITY_EXHAUSTED = 'ai_capacity_exhausted';

    public const BAD_REQUEST = 'bad_request';

    public const REQUEST_ERROR = 'request_error';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    public const SERVER_ERROR = 'server_error';

    /** @var array<string, string> */
    public const MESSAGES = [
        self::UNAUTHENTICATED => 'Authentication is required.',
        self::FORBIDDEN => 'The request is not permitted.',
        self::NOT_FOUND => 'The resource was not found.',
        self::METHOD_NOT_ALLOWED => 'The method is not allowed for this resource.',
        self::CONFLICT => 'The request conflicts with the current state of the resource.',
        self::VALIDATION_FAILED => 'The request is invalid.',
        self::RATE_LIMITED => 'Too many requests.',
        self::AI_CAPACITY_EXHAUSTED => 'The AI capacity available to this customer does not cover the request.',
        self::BAD_REQUEST => 'The request could not be understood.',
        self::REQUEST_ERROR => 'The request could not be processed.',
        self::SERVICE_UNAVAILABLE => 'The service is temporarily unavailable.',
        self::SERVER_ERROR => 'An unexpected error occurred.',
    ];

    /** Status codes with a code of their own. Any other 4xx is a request_error, any 5xx a server_error. */
    private const STATUS_CODES = [
        400 => self::BAD_REQUEST,
        401 => self::UNAUTHENTICATED,
        403 => self::FORBIDDEN,
        404 => self::NOT_FOUND,
        405 => self::METHOD_NOT_ALLOWED,
        409 => self::CONFLICT,
        422 => self::VALIDATION_FAILED,
        429 => self::RATE_LIMITED,
        503 => self::SERVICE_UNAVAILABLE,
    ];

    /**
     * AI cost control refusals (AiCostControlException), by reason: what the customer has left is
     * used up (429), the customer is not allowed AI (403), or Procynia has stopped spending (503).
     * The reason itself is never sent.
     */
    private const AI_REFUSALS = [
        AiCostControlException::CAPACITY_EXHAUSTED => [429, self::AI_CAPACITY_EXHAUSTED],
        AiCostControlException::CAPACITY_INSUFFICIENT => [429, self::AI_CAPACITY_EXHAUSTED],
        AiCostControlException::QUOTA_EXHAUSTED => [429, self::AI_CAPACITY_EXHAUSTED],
        AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED => [429, self::AI_CAPACITY_EXHAUSTED],
        AiCostControlException::CUSTOMER_MONTHLY_BUDGET_EXHAUSTED => [429, self::AI_CAPACITY_EXHAUSTED],
        AiCostControlException::NOT_INCLUDED => [403, self::FORBIDDEN],
        AiCostControlException::CUSTOMER_SUSPENDED => [403, self::FORBIDDEN],
        AiCostControlException::PAYMENT_UNPAID => [403, self::FORBIDDEN],
        AiCostControlException::PAYMENT_INCOMPLETE => [403, self::FORBIDDEN],
    ];

    public function render(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof AiCostControlException) {
            // Any other reason is a platform stop: global stop and budgets, an unpriceable model.
            [$status, $code] = self::AI_REFUSALS[$e->reason] ?? [503, self::SERVICE_UNAVAILABLE];
        } else {
            $status = $this->statusFor($e);
            $code = self::STATUS_CODES[$status] ?? ($status >= 500 ? self::SERVER_ERROR : self::REQUEST_ERROR);
        }

        $error = [
            'code' => $code,
            'message' => self::MESSAGES[$code],
            'request_id' => ApiRequestId::for($request),
        ];

        if ($e instanceof ValidationException) {
            $error['fields'] = $e->errors();
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        if ($status === 401) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        $headers['X-Request-Id'] = $error['request_id'];

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    private function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof AuthenticationException => 401,
            // A policy may deny with a status of its own (denyAsNotFound); that status is the answer.
            $e instanceof AuthorizationException => $e->hasStatus() ? (int) $e->status() : 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof ValidationException => 422,
            $e instanceof ThrottleRequestsException => 429,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            // Its status is kept, its response is not: it may be a redirect or an HTML page.
            $e instanceof HttpResponseException => $e->getResponse()->getStatusCode(),
            default => 500,
        };
    }
}
