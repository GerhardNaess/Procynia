<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

/**
 * Stripe webhook signature verification — security finding F-02.
 *
 * The endpoint is unauthenticated and CSRF-exempt by design: Stripe has no Laravel session, so the
 * Stripe signature is the *only* thing separating a real Stripe event from a forged one.
 *
 * Cashier registers that verification conditionally:
 *
 *     if (config('cashier.webhook.secret')) {
 *         $this->middleware(VerifyWebhookSignature::class);
 *     }
 *
 * A missing or empty secret therefore removed the check entirely rather than blocking the request —
 * the endpoint failed open. These tests pin the opposite behaviour: no verified signature, no
 * processing, in every configuration.
 *
 * Nothing is mocked. Signatures are computed with real HMAC-SHA256 in Stripe's documented format and
 * verified by Stripe's own SDK through Cashier's middleware.
 */
class StripeWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_procynia';

    /** Appears in the payload so a test can prove the raw body never reaches the log. */
    private const PAYLOAD_MARKER = 'payload-marker-must-never-be-logged';

    // -----------------------------------------------------------------------
    // Valid signature — the flow must still work
    // -----------------------------------------------------------------------

    public function test_a_validly_signed_webhook_is_accepted_and_reaches_the_controller(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $payload = $this->payload('customer.subscription.updated');

        $response = $this->postSignedWebhook($payload, self::SECRET);

        $response->assertOk();
        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(WebhookHandled::class);
    }

    /**
     * An event type the controller has no handler for still proves the request passed verification:
     * it reaches Cashier's dispatcher, which falls through to missingMethod().
     */
    public function test_a_validly_signed_unhandled_event_still_passes_verification(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class]);

        $response = $this->postSignedWebhook($this->payload('some.unhandled.event'), self::SECRET);

        $response->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }

    // -----------------------------------------------------------------------
    // Missing secret — the actual finding
    // -----------------------------------------------------------------------

    public function test_a_missing_webhook_secret_rejects_the_request(): void
    {
        Config::set('cashier.webhook.secret', null);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        // Signed with something, because an attacker would sign with whatever they like.
        $response = $this->postSignedWebhook($this->payload(), 'any-secret-an-attacker-picks');

        $response->assertStatus(503);
        $this->assertWebhookWasNotProcessed();
    }

    public function test_an_empty_webhook_secret_rejects_the_request(): void
    {
        Config::set('cashier.webhook.secret', '');
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postSignedWebhook($this->payload(), '');

        $response->assertStatus(503);
        $this->assertWebhookWasNotProcessed();
    }

    /**
     * A whitespace-only value is a configuration mistake, not a secret.
     */
    public function test_a_blank_webhook_secret_rejects_the_request(): void
    {
        Config::set('cashier.webhook.secret', '   ');
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postSignedWebhook($this->payload(), '   ');

        $response->assertStatus(503);
        $this->assertWebhookWasNotProcessed();
    }

    /**
     * Without a secret an attacker does not even need to guess one — the request must be refused
     * before any signature is considered.
     */
    public function test_an_unsigned_request_is_rejected_when_no_secret_is_configured(): void
    {
        Config::set('cashier.webhook.secret', null);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postRawWebhook($this->payload(), []);

        $response->assertStatus(503);
        $this->assertWebhookWasNotProcessed();
    }

    // -----------------------------------------------------------------------
    // Signature failures
    // -----------------------------------------------------------------------

    public function test_a_missing_signature_header_is_rejected(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postRawWebhook($this->payload(), []);

        $response->assertStatus(403);
        $this->assertWebhookWasNotProcessed();
    }

    public function test_a_malformed_signature_header_is_rejected(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postRawWebhook($this->payload(), ['Stripe-Signature' => 'not-a-signature']);

        $response->assertStatus(403);
        $this->assertWebhookWasNotProcessed();
    }

    public function test_a_signature_made_with_the_wrong_secret_is_rejected(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postSignedWebhook($this->payload(), 'whsec_a_different_secret');

        $response->assertStatus(403);
        $this->assertWebhookWasNotProcessed();
    }

    /**
     * A signature that was valid for a different body must not validate this one.
     */
    public function test_a_signature_for_a_different_payload_is_rejected(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $signedBody = $this->payload('customer.subscription.deleted');
        $sentBody = $this->payload('customer.subscription.updated');

        $response = $this->postRawWebhook($sentBody, [
            'Stripe-Signature' => $this->stripeSignature($signedBody, self::SECRET),
        ]);

        $response->assertStatus(403);
        $this->assertWebhookWasNotProcessed();
    }

    // -----------------------------------------------------------------------
    // Business logic must not run on rejection
    // -----------------------------------------------------------------------

    /**
     * The consequence that matters: a forged subscription-deleted event must not be able to close a
     * customer's billing.
     */
    public function test_a_forged_subscription_event_never_reaches_the_business_logic(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postSignedWebhook(
            $this->payload('customer.subscription.deleted'),
            'attacker-chosen-secret',
        );

        $response->assertStatus(403);
        $this->assertWebhookWasNotProcessed();
    }

    // -----------------------------------------------------------------------
    // Logging hygiene
    // -----------------------------------------------------------------------

    public function test_a_rejected_webhook_is_logged_without_sensitive_values(): void
    {
        Config::set('cashier.webhook.secret', self::SECRET);

        $captured = $this->captureLogLines();

        $payload = $this->payload();
        $signature = $this->stripeSignature($payload, 'whsec_a_different_secret');

        $this->postRawWebhook($payload, ['Stripe-Signature' => $signature]);

        $joined = implode("\n", $captured->getArrayCopy());

        $this->assertStringContainsString('stripe_webhook_rejected', $joined);
        $this->assertStringContainsString('invalid_signature', $joined);

        $this->assertStringNotContainsString(self::SECRET, $joined, 'The webhook secret must never be logged.');
        $this->assertStringNotContainsString(self::PAYLOAD_MARKER, $joined, 'The raw payload must never be logged.');
        $this->assertStringNotContainsString($signature, $joined, 'The Stripe-Signature header must never be logged.');
    }

    public function test_a_missing_secret_is_logged_as_a_configuration_problem(): void
    {
        Config::set('cashier.webhook.secret', null);

        $captured = $this->captureLogLines();

        $this->postSignedWebhook($this->payload(), 'irrelevant');

        $joined = implode("\n", $captured->getArrayCopy());

        $this->assertStringContainsString('stripe_webhook_rejected', $joined);
        $this->assertStringContainsString('missing_webhook_secret', $joined);
        $this->assertStringNotContainsString(self::PAYLOAD_MARKER, $joined);
    }

    // -----------------------------------------------------------------------
    // Route contract
    // -----------------------------------------------------------------------

    /**
     * CSRF must stay disabled for this route. Stripe has no Laravel session, so requiring a CSRF
     * token would break every real webhook. The signature is what authenticates the caller — the fix
     * for F-02 must not be "add CSRF".
     */
    public function test_the_webhook_route_remains_csrf_exempt(): void
    {
        $this->assertStringContainsString(
            "'stripe/webhook'",
            file_get_contents(base_path('bootstrap/app.php')),
            'The Stripe webhook must stay excluded from CSRF; the Stripe signature is its authentication.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function payload(string $type = 'customer.subscription.updated'): string
    {
        return (string) json_encode([
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => $type,
            'marker' => self::PAYLOAD_MARKER,
            'data' => [
                'object' => [
                    'id' => 'sub_test_'.bin2hex(random_bytes(4)),
                    // Deliberately an id no customer in the database has, so a validly signed event
                    // reaches the handler without mutating anything.
                    'customer' => 'cus_does_not_exist_'.bin2hex(random_bytes(4)),
                ],
            ],
        ]);
    }

    /**
     * Build a real Stripe signature header: t=<timestamp>,v1=<hmac_sha256("<timestamp>.<payload>")>.
     */
    private function stripeSignature(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        );
    }

    private function postSignedWebhook(string $payload, string $secret)
    {
        return $this->postRawWebhook($payload, [
            'Stripe-Signature' => $this->stripeSignature($payload, $secret),
        ]);
    }

    /** @param array<string, string> $headers */
    private function postRawWebhook(string $payload, array $headers)
    {
        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            $this->transformHeadersToServerVars(array_merge(['Content-Type' => 'application/json'], $headers)),
            $payload,
        );
    }

    private function assertWebhookWasNotProcessed(): void
    {
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(WebhookHandled::class);
    }

    /**
     * Collect everything written to the log.
     *
     * Returns an ArrayObject rather than an array: a plain array would be copied on return, and the
     * listener would then append to a different variable than the caller inspects — a silently empty
     * assertion instead of a real one.
     */
    private function captureLogLines(): \ArrayObject
    {
        $captured = new \ArrayObject;

        Log::listen(static function ($message) use ($captured): void {
            $captured->append($message->message.' '.json_encode($message->context));
        });

        return $captured;
    }
}
