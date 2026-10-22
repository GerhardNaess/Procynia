<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageReviewService;
use App\Services\Security\ActorChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * The page review decisions refuse on their own, in the service — phase 0 step 6
 * (docs/external-api-phase-0-plan.md §4.4). Whatever entry point calls them, an integration, a
 * request through the API and a person whose turn it is not are turned away, and nothing changes.
 */
class EnterpriseWikiPageReviewServiceAuthorityTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    public function test_no_decision_is_taken_by_an_integration(): void
    {
        $case = $this->page(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $integration = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER, isWikiApprover: true);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $integration = $integration->fresh();

        foreach ($this->decisions($case, $integration) as $name => $decide) {
            try {
                $decide();
                $this->fail("{$name} must refuse an integration");
            } catch (HumanDecisionRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertUnchanged($case);
    }

    /** Delegated mode included: a person acting through the API takes none of these decisions (B2). */
    public function test_no_decision_is_taken_through_the_api(): void
    {
        $case = $this->page(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });
        $this->app->forgetInstance(EnterpriseWikiPageReviewService::class);

        foreach ($this->decisions($case, $case['reviewer']) as $name => $decide) {
            try {
                $decide();
                $this->fail("{$name} must refuse a request through the API");
            } catch (HumanDecisionRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertUnchanged($case);
    }

    public function test_a_person_whose_turn_it_is_not_is_refused_by_the_service(): void
    {
        $case = $this->page(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $otherApprover = $this->user($case['customer'], User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);
        $service = app(EnterpriseWikiPageReviewService::class);

        foreach ([
            'approve' => fn () => $service->approve($otherApprover, $case['page']),
            'send back' => fn () => $service->returnToOwner($otherApprover, $case['page'], 'Ikke min gjennomgang å avgjøre.'),
            'withdraw' => fn () => $service->withdraw($otherApprover, $case['page']),
        ] as $name => $decide) {
            try {
                $decide();
                $this->fail("{$name} must refuse somebody the version was not handed to");
            } catch (HttpException|AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertUnchanged($case);
    }

    /** @param array<string, mixed> $case @return array<string, callable(): mixed> */
    private function decisions(array $case, User $actor): array
    {
        $service = app(EnterpriseWikiPageReviewService::class);

        return [
            'submit' => fn () => $service->submit($actor, $case['page'], $case['version'], $case['reviewer']),
            'reopen' => fn () => $service->reopenRejected($actor, $case['page']),
            'withdraw' => fn () => $service->withdraw($actor, $case['page']),
            'approve' => fn () => $service->approve($actor, $case['page']),
            'send back' => fn () => $service->returnToOwner($actor, $case['page'], 'Begrunnelse som er lang nok.'),
            'assign QA' => fn () => $service->assignQa($actor, $case['page'], $case['reviewer']),
        ];
    }

    /** @param array<string, mixed> $case */
    private function assertUnchanged(array $case): void
    {
        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertNull($page->published_version_id);
        $this->assertSame((int) $case['reviewer']->id, (int) $case['version']->fresh()->reviewer_user_id);
        $this->assertNull($case['version']->fresh()->qa_user_id);
    }

    /** @return array<string, mixed> */
    private function page(string $status): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Gjennomgang AS',
            'slug' => 'gjennomgang-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true, isQa: true);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'side-'.Str::lower(Str::random(6)),
            'title' => 'Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Side',
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $reviewer->id,
        ]);

        return ['customer' => $customer, 'owner' => $owner, 'reviewer' => $reviewer, 'page' => $page->fresh(), 'version' => $version];
    }

    private function user(Customer $customer, string $bidRole, bool $isWikiApprover = false, bool $isQa = false): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
            'is_qa' => $isQa,
        ]));
    }
}
