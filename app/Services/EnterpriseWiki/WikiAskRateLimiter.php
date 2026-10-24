<?php

namespace App\Services\EnterpriseWiki;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The server-side rate limit on «Spør Wiki»: per asker and per customer, in one window, with the
 * limits in config/procynia.php (procynia.ai.wiki_ask).
 *
 * People in the app and integrations through the API (D20) count in separate buckets with the same
 * limits, so a busy integration can never use up the customer's people's questions, nor they its.
 * An integration's asker is its identity, which is one per client grant.
 */
class WikiAskRateLimiter
{
    public const CHANNEL_APP = 'app';

    public const CHANNEL_API = 'api';

    public function hit(int $customerId, ?int $userId, string $channel = self::CHANNEL_APP): void
    {
        $windowSeconds = max(60, (int) config('procynia.ai.wiki_ask.window_seconds', 900));
        $userLimit = max(1, (int) config('procynia.ai.wiki_ask.user_attempts', 10));
        $customerLimit = max(1, (int) config('procynia.ai.wiki_ask.customer_attempts', 60));

        foreach ([[self::userKey($customerId, $userId, $channel), $userLimit, 'user'], [self::customerKey($customerId, $channel), $customerLimit, 'customer']] as [$key, $limit, $scope]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                Log::warning('[WIKI_ASK] AI request rate limited.', [
                    'reason' => 'rate_limit_'.$scope,
                    'channel' => $channel,
                    'customer_id' => $customerId,
                    'user_id' => $userId,
                    'operation' => 'wiki.ask',
                ]);

                abort(429, __('procynia.wiki.ask_rate_limited'));
            }
        }

        RateLimiter::hit(self::userKey($customerId, $userId, $channel), $windowSeconds);
        RateLimiter::hit(self::customerKey($customerId, $channel), $windowSeconds);
    }

    public static function userKey(int $customerId, ?int $userId, string $channel = self::CHANNEL_APP): string
    {
        return sprintf('ai:wiki-ask:%suser:%d:customer:%d', self::prefix($channel), (int) $userId, $customerId);
    }

    public static function customerKey(int $customerId, string $channel = self::CHANNEL_APP): string
    {
        return sprintf('ai:wiki-ask:%scustomer:%d', self::prefix($channel), $customerId);
    }

    /** The app keeps the keys it always had; the API's are its own. */
    private static function prefix(string $channel): string
    {
        return $channel === self::CHANNEL_APP ? '' : $channel.':';
    }
}
