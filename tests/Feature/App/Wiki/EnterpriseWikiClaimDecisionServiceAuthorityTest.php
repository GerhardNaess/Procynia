<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimDecisionService;
use App\Services\Security\ActorChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * The claim decisions refuse on their own, in the service — phase 0 step 7
 * (docs/external-api-phase-0-plan.md §4.4). An integration, a request through the API and a person
 * who may not handle the claim are turned away, and the claim, its text and its audit trail stay
 * as they were.
 */
class EnterpriseWikiClaimDecisionServiceAuthorityTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    private const SUGGESTION = 'Virksomheten bør gjennomføre en årlig tilgangsgjennomgang.';

    public function test_no_claim_decision_is_taken_by_an_integration(): void
    {
        $case = $this->page();
        $integration = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER, isQa: true);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $this->assertEveryDecisionRefused($case, $integration->fresh(), HumanDecisionRefused::class);
    }

    public function test_no_claim_decision_is_taken_through_the_api(): void
    {
        $case = $this->page();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($case, $case['qa'], HumanDecisionRefused::class);
    }

    public function test_a_person_who_may_not_handle_the_claim_is_refused_by_the_service(): void
    {
        $case = $this->page();
        // Reads and edits the Wiki, but holds no claim authority (no QA, not a document owner).
        $editor = $this->user($case['customer'], User::BID_ROLE_CONTRIBUTOR);

        $this->assertEveryDecisionRefused($case, $editor, HttpException::class);
    }

    /**
     * Customer separation in the service itself (§2m): a System Owner and a QA of another customer
     * decide none of this customer's claims, whatever they may do in their own Wiki.
     */
    public function test_a_person_of_another_customer_is_refused_by_the_service(): void
    {
        $case = $this->page();
        $other = $this->page();

        foreach ([
            'system owner' => $this->user($other['customer'], User::BID_ROLE_SYSTEM_OWNER),
            'QA' => $other['qa'],
        ] as $who => $stranger) {
            $this->assertEveryDecisionRefused($case, $stranger, HttpException::class, 403);
        }
    }

    /** A claim is decided only on its own page: a claim of another page is not this page's to decide. */
    public function test_a_claim_of_another_page_is_refused(): void
    {
        $case = $this->page();
        $otherPage = EnterpriseWikiPage::query()->create([
            'customer_id' => $case['customer']->id,
            'slug' => 'annen-'.Str::lower(Str::random(6)),
            'title' => 'Annen side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $this->assertEveryDecisionRefused(['page' => $otherPage] + $case, $case['qa'], HttpException::class, 403);
    }

    /** @param array<string, mixed> $case @param class-string<\Throwable> $refusal @param int|null $status the HTTP status the refusal must carry */
    private function assertEveryDecisionRefused(array $case, User $actor, string $refusal, ?int $status = null): void
    {
        $service = app(EnterpriseWikiClaimDecisionService::class);

        foreach ([
            'approve' => fn () => $service->approve($actor, $case['page'], $case['claim'], 'Endret tekst.', null),
            'reject' => fn () => $service->reject($actor, $case['page'], $case['claim'], null),
            'unapprove' => fn () => $service->unapprove($actor, $case['page'], $case['claim']),
            'blocking' => fn () => $service->setBlockingOverride($actor, $case['page'], $case['claim'], false, null),
            'source reference' => fn () => $service->attachSourceReference($actor, $case['page'], $case['claim'], 1, []),
        ] as $name => $decide) {
            try {
                $decide();
                $this->fail("{$name} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, $name);

                if ($status !== null) {
                    $this->assertSame($status, $e->getStatusCode(), $name);
                }
            }
        }

        $claim = $case['claim']->fresh();
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $claim->approval_status);
        $this->assertSame(self::SUGGESTION, $claim->claim_text);
        $this->assertStringContainsString(self::SUGGESTION, $case['version']->fresh()->content_markdown);
        $this->assertSame(0, EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Påstandsbeslutning AS',
            'slug' => 'pastand-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'side-'.Str::lower(Str::random(6)),
            'title' => 'Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Side\n\n".self::SUGGESTION,
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Side'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => self::SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
            ],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => self::SUGGESTION,
            'page_excerpt' => self::SUGGESTION,
            'content_block_key' => 'block-0002',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        return compact('customer', 'page', 'version', 'claim', 'qa');
    }

    private function user(Customer $customer, string $bidRole, bool $isQa = false): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
            'is_qa' => $isQa,
        ]));
    }
}
