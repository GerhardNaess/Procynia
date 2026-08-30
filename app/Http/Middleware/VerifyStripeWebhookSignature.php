<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature as CashierVerifyWebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Fail-closed Stripe webhook signature verification (security finding F-02).
 *
 * The webhook endpoint is unauthenticated and CSRF-exempt by necessity — Stripe has no Laravel
 * session — so the Stripe signature is the only thing separating a real event from a forged one.
 *
 * Cashier registers its verification middleware conditionally:
 *
 *     if (config('cashier.webhook.secret')) {
 *         $this->middleware(VerifyWebhookSignature::class);
 *     }
 *
 * A missing or empty secret therefore removed the check rather than blocking the request, and the
 * endpoint accepted arbitrary payloads. This middleware inverts that: no usable secret means no
 * processing, ever.
 *
 * Delegation is deliberate. Stripe's own SDK performs the actual HMAC comparison through Cashier's
 * middleware; re-implementing signature verification would be a worse risk than the one being fixed.
 *
 * Status codes follow EnsureHealthToken, the project's existing precedent for this distinction:
 * 503 when the control is not configured, 403 when the presented credential is wrong.
 */
class VerifyStripeWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = trim((string) config('cashier.webhook.secret', ''));

        if ($secret === '') {
            // A server-side misconfiguration, not a bad caller. 503 also lets Stripe keep retrying,
            // so events are not silently lost between the deploy and the secret being set.
            Log::error('[PROCYNIA][BILLING] Stripe webhook rejected.', [
                'event' => 'stripe_webhook_rejected',
                'reason' => 'missing_webhook_secret',
            ]);

            return response('Stripe webhook signature verification is not configured.', 503);
        }

        try {
            return app(CashierVerifyWebhookSignature::class)->handle($request, $next);
        } catch (AccessDeniedHttpException $exception) {
            // The exception message can echo back parts of the signature header, so only the fact of
            // the failure is recorded — never the message, the header or the payload.
            Log::warning('[PROCYNIA][BILLING] Stripe webhook rejected.', [
                'event' => 'stripe_webhook_rejected',
                'reason' => 'invalid_signature',
            ]);

            throw $exception;
        }
    }
}
