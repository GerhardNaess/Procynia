<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WikiController::show() must distinguish "Kilde funnet" (source_found), "Manuelt godkjent"
 * (manually_approved) and "Mangler kilde" (missing_source) per claim, and the claim_summary
 * counts must reflect all three buckets — see EnterpriseWikiClaim::sourceStatus().
 */
class WikiShowClaimStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_claim_summary_and_per_claim_badges_reflect_all_source_statuses(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'status-page-'.Str::lower(Str::random(6)),
            'title' => 'Status Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Status Page',
            'generated_by_model' => 'gpt-5',
        ]);

        $foundClaim = $this->makeClaim($page, $version, 'Har reell kildereferanse.', 0);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $foundClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => 'kilde.pdf',
            'excerpt' => 'Utdrag som støtter påstanden.',
        ]);

        $approvedClaim = $this->makeClaim($page, $version, 'Manuelt godkjent uten kilde.', 1, [
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
            'approval_comment' => 'Bekreftet av kunden.',
        ]);

        $missingClaim = $this->makeClaim($page, $version, 'Mangler fortsatt kilde.', 2);

        $response = $this->actingAs($viewer)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($foundClaim, $approvedClaim, $missingClaim, $owner): bool {
            $props = data_get($inertia, 'props');
            $summary = data_get($props, 'claim_summary');
            $claims = collect(data_get($props, 'claims', []))->keyBy('id');

            $found = $claims->get($foundClaim->id);
            $approved = $claims->get($approvedClaim->id);
            $missing = $claims->get($missingClaim->id);

            return $summary['total'] === 3
                && $summary['source_found'] === 1
                && $summary['manually_approved'] === 1
                && $summary['missing_source'] === 1
                && ($found['source_status'] ?? null) === EnterpriseWikiClaim::SOURCE_STATUS_FOUND
                && ($approved['source_status'] ?? null) === EnterpriseWikiClaim::SOURCE_STATUS_MANUALLY_APPROVED
                && ($approved['approved_by_name'] ?? null) === $owner->name
                && ($approved['approval_comment'] ?? null) === 'Bekreftet av kunden.'
                && ($missing['source_status'] ?? null) === EnterpriseWikiClaim::SOURCE_STATUS_MISSING;
        });
    }

    private function makeClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $text,
        int $order,
        array $overrides = [],
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'position_order' => $order,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $overrides));
    }

    private function createCustomer(string $name = 'Show Status Test AS'): Customer
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

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Status Tester',
            'email' => Str::lower(Str::random(8)).'@show-status-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
