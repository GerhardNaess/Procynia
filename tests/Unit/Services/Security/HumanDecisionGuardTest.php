<?php

namespace Tests\Unit\Services\Security;

use App\Exceptions\HumanDecisionRefused;
use App\Models\User;
use App\Services\Security\ActorChannel;
use App\Services\Security\HumanDecisionGuard;
use App\Services\Security\InteractiveActorChannel;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HumanDecisionGuardTest extends TestCase
{
    public function test_the_web_application_is_an_interactive_channel(): void
    {
        $this->assertInstanceOf(InteractiveActorChannel::class, app(ActorChannel::class));
        $this->assertFalse(app(ActorChannel::class)->isApi());
    }

    /** @return array<string, array{0: ?string}> */
    public static function personRoles(): array
    {
        return [
            'customer user' => [User::ROLE_USER],
            'customer admin' => [User::ROLE_CUSTOMER_ADMIN],
            'super admin' => [User::ROLE_SUPER_ADMIN],
            // The column is nullable and older rows carry NULL: still a person.
            'no role recorded' => [null],
        ];
    }

    #[DataProvider('personRoles')]
    public function test_an_active_person_passes_and_is_returned(?string $role): void
    {
        $person = $this->user($role);

        $this->assertSame($person, $this->guard()->assert($person, HumanDecision::WIKI_PAGE_APPROVE));
        $this->assertTrue($this->guard()->allows($person));
    }

    public function test_no_actor_is_refused(): void
    {
        $this->assertRefused(null, HumanDecisionGuard::REFUSAL_NO_ACTOR);
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->assertRefused($this->user(User::ROLE_USER, isActive: false), HumanDecisionGuard::REFUSAL_INACTIVE);
    }

    public function test_an_integration_is_refused_whatever_its_bid_role(): void
    {
        $integration = $this->user(User::ROLE_INTEGRATION);
        $integration->bid_role = User::BID_ROLE_SYSTEM_OWNER;

        $this->assertRefused($integration, HumanDecisionGuard::REFUSAL_INTEGRATION);
    }

    public function test_a_person_acting_through_the_api_is_refused(): void
    {
        $api = new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        };

        $this->assertRefused($this->user(User::ROLE_USER), HumanDecisionGuard::REFUSAL_API, new HumanDecisionGuard($api));
    }

    public function test_a_refusal_is_an_authorization_failure(): void
    {
        $this->assertTrue(is_subclass_of(HumanDecisionRefused::class, AuthorizationException::class));
    }

    public function test_every_decision_rides_on_a_human_only_key_or_on_none(): void
    {
        foreach (HumanDecision::cases() as $decision) {
            $permission = $decision->permission();

            if ($permission === null) {
                continue;
            }

            $this->assertTrue(CustomerPermissionCatalog::exists($permission), $decision->value);

            // Adopting an AI-proposed flow rides on quality.edit, which drafting also needs. The
            // decision is still human-only; the key cannot be.
            if ($decision !== HumanDecision::QUALITY_FLOW_ADOPT) {
                $this->assertTrue(CustomerPermissionCatalog::isHumanOnly($permission), $decision->value);
            }
        }
    }

    public function test_every_human_only_key_has_a_decision(): void
    {
        $covered = array_filter(array_map(
            static fn (HumanDecision $decision): ?string => $decision->permission(),
            HumanDecision::cases(),
        ));

        foreach (CustomerPermissionCatalog::humanOnlyPermissions() as $key) {
            $this->assertContains($key, $covered, $key);
        }
    }

    private function assertRefused(?User $actor, string $refusal, ?HumanDecisionGuard $guard = null): void
    {
        $guard ??= $this->guard();
        $this->assertFalse($guard->allows($actor));

        try {
            $guard->assert($actor, HumanDecision::RISK_ACCEPT);
            $this->fail('expected the decision to be refused');
        } catch (HumanDecisionRefused $e) {
            $this->assertSame($refusal, $e->refusal);
            $this->assertSame(HumanDecision::RISK_ACCEPT, $e->decision);
        }
    }

    private function guard(): HumanDecisionGuard
    {
        return app(HumanDecisionGuard::class);
    }

    private function user(?string $role, bool $isActive = true): User
    {
        $user = new User;
        $user->forceFill(['role' => $role, 'is_active' => $isActive, 'bid_role' => User::BID_ROLE_CONTRIBUTOR]);

        return $user;
    }
}
