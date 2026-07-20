<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimFindingExplainer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Blocking is a decision an authorized user can record independently of severity or of the
 * claim's own approval_status (product rule: "Alvorlighet er systemets vurdering... Blokkering...
 * Disse skal være separate egenskaper"). Every decision is appended to
 * EnterpriseWikiClaimDecision — never just overwritten in place — so the full history survives.
 */
class WikiClaimBlockingOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_authorized_user_can_remove_blocking(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createClaimDefect($customer);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/blocking",
            ['blocking' => false, 'comment' => 'Vurdert og godkjent som et akseptabelt avvik.'],
        );

        $response->assertRedirect();
        $fresh = $claim->fresh();
        $this->assertFalse($fresh->blocking_override);
        $this->assertSame($owner->id, $fresh->blocking_override_by_user_id);
        $this->assertNotNull($fresh->blocking_override_at);
    }

    public function test_authorized_user_can_keep_blocking(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createClaimDefect($customer);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/blocking",
            ['blocking' => true],
        );

        $response->assertRedirect();
        $fresh = $claim->fresh();
        $this->assertTrue($fresh->blocking_override);
        $this->assertSame($owner->id, $fresh->blocking_override_by_user_id);
    }

    public function test_decision_is_recorded_with_user_timestamp_comment_and_previous_new_state(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createClaimDefect($customer);

        $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/blocking",
            ['blocking' => false, 'comment' => 'Kunden har bekreftet muntlig.'],
        )->assertRedirect();

        $decision = EnterpriseWikiClaimDecision::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($decision);
        $this->assertSame(EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE, $decision->decision_type);
        $this->assertSame($owner->id, $decision->decided_by_user_id);
        $this->assertSame('Kunden har bekreftet muntlig.', $decision->comment);
        $this->assertNull($decision->previous_state['blocking_override']);
        $this->assertFalse($decision->new_state['blocking_override']);
        $this->assertNotNull($decision->created_at);
    }

    public function test_second_decision_appends_a_new_row_and_preserves_the_first(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createClaimDefect($customer);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/blocking", ['blocking' => false])
            ->assertRedirect();
        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/blocking", ['blocking' => true])
            ->assertRedirect();

        $decisions = EnterpriseWikiClaimDecision::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $decisions);
        $this->assertNull($decisions[0]->previous_state['blocking_override']);
        $this->assertFalse($decisions[0]->new_state['blocking_override']);
        $this->assertFalse($decisions[1]->previous_state['blocking_override']);
        $this->assertTrue($decisions[1]->new_state['blocking_override']);
    }

    public function test_unauthorized_user_cannot_change_blocking(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createClaimDefect($customer);

        $response = $this->actingAs($contributor)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/blocking",
            ['blocking' => false],
        );

        $response->assertForbidden();
        $this->assertNull($claim->fresh()->blocking_override);
        $this->assertSame(
            0,
            EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    public function test_customer_isolation_is_preserved(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $ownerB = $this->createUser($customerB, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createClaimDefect($customerA);

        $response = $this->actingAs($ownerB)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/blocking",
            ['blocking' => false],
        );

        $response->assertNotFound();
        $this->assertNull($claim->fresh()->blocking_override);
    }

    public function test_best_practice_suggestion_is_unaffected_by_blocking_override(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createClaimDefect($customer, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/blocking",
            ['blocking' => false],
        );

        $response->assertStatus(422);
        $this->assertNull($claim->fresh()->blocking_override);
    }

    public function test_real_unsupported_claim_still_suggests_blocking_without_any_override(): void
    {
        [, , $claim] = $this->createClaimDefect($this->createCustomer());

        $this->assertNull($claim->blocking_override);
        // No override recorded — the system's own suggestion (always true for a genuinely
        // unsupported factual claim) is what remains in effect until a human decides otherwise.
        $this->assertTrue(
            app(EnterpriseWikiClaimFindingExplainer::class)->suggestedBlocking($claim->fresh()),
        );
    }

    private function createCustomer(string $name = 'Blocking Override Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole, bool $isQa = false): User
    {
        return User::query()->create([
            'name' => 'Blocking Override Tester',
            'email' => Str::lower(Str::random(8)).'@blocking-override-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'is_qa' => $isQa,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion, 2: EnterpriseWikiClaim}
     */
    private function createClaimDefect(
        Customer $customer,
        string $contentOrigin = EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
    ): array {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'blocking-page-'.Str::lower(Str::random(6)),
            'title' => 'Blocking Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Blocking Page',
            'generated_by_model' => 'gpt-5',
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'En påstand som ikke er bekreftet mot kilden.',
            'content_origin' => $contentOrigin,
            'generation_issue' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT
                ? 'unsupported_generated_content'
                : null,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        return [$page, $version, $claim];
    }
}
