<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileCpvCode;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunityStatus;
use App\Services\OpportunitySources\WatchProfileRelevanceScorer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Relevance scoring, now that it belongs to no register.
 *
 * It was a private method on the Doffin worker. Nothing in it was Doffin's — it reads a watch
 * profile on one side and a NormalizedNotice on the other — but a second worker would have had to
 * copy it, and two copies of a scoring rule eventually disagree about what a hit is worth.
 *
 * The numbers are the contract. They are already written into relevance_score on records people
 * look at every day, so these tests state them outright: an extraction that changed them would be
 * a silent re-ranking of everybody's watch inbox.
 */
class WatchProfileRelevanceScorerTest extends TestCase
{
    use DatabaseTransactions;

    private function scorer(): WatchProfileRelevanceScorer
    {
        return app(WatchProfileRelevanceScorer::class);
    }

    /**
     * @param  array<int, string>|string  $keywords
     * @param  array<int, array{cpv_code: string, weight: int}>  $cpvCodes
     */
    private function profile(array|string $keywords = [], array $cpvCodes = []): WatchProfile
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Scoring AS',
            'slug' => 'scoring-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Watch Owner',
            'email' => 'scoring-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
        ]);

        $profile = WatchProfile::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'name' => 'Renhold',
            'keywords' => is_array($keywords) ? $keywords : [],
            'is_active' => true,
        ]);

        if (is_string($keywords)) {
            // A profile from before keywords were stored as JSON. Rows like this still exist.
            $profile->setRawAttributes(array_merge($profile->getAttributes(), ['keywords' => $keywords]), true);
        }

        foreach ($cpvCodes as $rule) {
            WatchProfileCpvCode::query()->create([
                'watch_profile_id' => $profile->id,
                'cpv_code' => $rule['cpv_code'],
                'weight' => $rule['weight'],
            ]);
        }

        return $profile->load('cpvCodes');
    }

    /** @param array<string, mixed> $attributes */
    private function notice(array $attributes = []): NormalizedNotice
    {
        return new NormalizedNotice(
            sourceKey: $attributes['source'] ?? 'doffin',
            externalId: '2026-500001',
            title: $attributes['title'] ?? 'Rammeavtale for konsulentbistand',
            description: $attributes['description'] ?? null,
            buyerName: $attributes['buyer_name'] ?? null,
            publicationDate: '2026-03-29',
            deadline: '2026-04-30',
            status: OpportunityStatus::Open,
            sourceUrl: null,
            cpvCodes: $attributes['cpv_codes'] ?? [],
        );
    }

    // ------------------------------------------------------------------ the weights

    public function test_a_keyword_in_the_title_or_description_is_worth_twenty(): void
    {
        $profile = $this->profile(['rammeavtale']);

        $this->assertSame(20, $this->scorer()->score($profile, $this->notice(['title' => 'Rammeavtale for drift'])));
        $this->assertSame(20, $this->scorer()->score($profile, $this->notice([
            'title' => 'Konkurranse',
            'description' => 'Denne rammeavtalen gjelder konsulenttjenester.',
        ])));
    }

    /** The same word in the buyer's name is weaker evidence, and scores as such. */
    public function test_a_keyword_in_the_buyer_name_is_worth_eight(): void
    {
        $profile = $this->profile(['tingrett']);

        $this->assertSame(8, $this->scorer()->score($profile, $this->notice([
            'title' => 'Konkurranse om vakthold',
            'buyer_name' => 'Oslo tingrett',
        ])));
    }

    /** A keyword counts once, at its best match — not once per place it appears. */
    public function test_a_keyword_matching_both_text_and_buyer_counts_once(): void
    {
        $profile = $this->profile(['renhold']);

        $this->assertSame(20, $this->scorer()->score($profile, $this->notice([
            'title' => 'Renhold av lokaler',
            'buyer_name' => 'Renholdsetaten',
        ])));
    }

    public function test_a_matching_cpv_code_is_worth_its_configured_weight(): void
    {
        $profile = $this->profile([], [['cpv_code' => '90910000', 'weight' => 25]]);

        $this->assertSame(25, $this->scorer()->score($profile, $this->notice([
            'title' => 'Ingen nøkkelord her',
            'cpv_codes' => ['90910000'],
        ])));
    }

    /** A hit that answers both halves of the profile is worth more than the sum of them. */
    public function test_matching_both_a_keyword_and_a_cpv_code_adds_ten(): void
    {
        $profile = $this->profile(['rammeavtale'], [['cpv_code' => '72000000', 'weight' => 25]]);

        // 20 for the keyword, 25 for the CPV rule, 10 because both kinds matched.
        $this->assertSame(55, $this->scorer()->score($profile, $this->notice([
            'title' => 'Rammeavtale for konsulentbistand',
            'cpv_codes' => ['72000000'],
        ])));
    }

    /**
     * The two numbers the nightly Doffin sweep has been writing all along.
     *
     * 55 and 20 are asserted in WatchProfileInboxDiscoveryTest through the whole command. They are
     * asserted here against the extracted scorer directly, so a future change to the arithmetic
     * fails in the place the arithmetic lives.
     */
    public function test_the_scores_the_doffin_sweep_already_produces_are_unchanged(): void
    {
        $personal = $this->profile(['rammeavtale'], [['cpv_code' => '72000000', 'weight' => 25]]);
        $department = $this->profile(['renhold']);

        $this->assertSame(55, $this->scorer()->score($personal, $this->notice([
            'title' => 'Rammeavtale for konsulentbistand',
            'description' => 'Denne rammeavtalen gjelder konsulenttjenester.',
            'buyer_name' => 'Procynia AS',
            'cpv_codes' => ['72000000'],
        ])));
        $this->assertSame(20, $this->scorer()->score($department, $this->notice([
            'title' => 'Renholdstjenester for nytt kontor',
            'description' => 'Renhold av nye lokaler.',
            'buyer_name' => 'Oslo kommune',
            'cpv_codes' => ['90910000'],
        ])));
    }

    public function test_nothing_matching_scores_nothing(): void
    {
        $profile = $this->profile(['rammeavtale'], [['cpv_code' => '72000000', 'weight' => 25]]);

        $this->assertSame(0, $this->scorer()->score($profile, $this->notice([
            'title' => 'Irrelevant kunngjøring',
            'description' => 'Ingen match mot profilen.',
            'buyer_name' => 'Unknown Buyer',
            'cpv_codes' => ['45000000'],
        ])));
    }

    /**
     * Punctuation is ignored on both sides — and the check digit is not.
     *
     * Matching reduces each code to its digits, so "90.910.000" and "90 910 000" are the same code
     * as "90910000", while "90910000-9" becomes the nine-digit string "909100009" and matches
     * nothing. That is how scoring has always worked and how OpportunitySearchCriteria normalises
     * codes too, so it is stated here rather than quietly changed: both the CPV catalogue and the
     * stored watch rules hold bare eight-digit codes, so nothing in Procynia hits it today. A
     * register that started returning codes with their check digit would, silently.
     */
    public function test_cpv_matching_ignores_punctuation_but_not_the_check_digit(): void
    {
        $profile = $this->profile([], [['cpv_code' => '90.910.000', 'weight' => 15]]);

        $this->assertSame(15, $this->scorer()->score($profile, $this->notice([
            'title' => 'Renhold',
            'cpv_codes' => ['90910000'],
        ])));
        $this->assertSame(0, $this->scorer()->score($profile, $this->notice([
            'title' => 'Renhold',
            'cpv_codes' => ['90910000-9'],
        ])));
    }

    // ------------------------------------------------------------------ the two keyword readings

    /**
     * A profile whose keywords are a plain string is read two different ways, and always has been:
     * split into terms for the register, kept whole for scoring. The difference is preserved here
     * rather than quietly unified, because unifying it would re-rank records that already exist.
     */
    public function test_a_plain_string_profile_is_searched_as_terms_and_scored_as_one(): void
    {
        $profile = $this->profile('renhold, tingrett');

        $this->assertSame(['renhold', 'tingrett'], $this->scorer()->searchKeywords($profile));
        $this->assertSame(['renhold, tingrett'], $this->scorer()->scoringKeywords($profile));
        $this->assertSame(0, $this->scorer()->score($profile, $this->notice(['title' => 'Renhold av lokaler'])));
    }

    public function test_a_json_profile_is_the_same_list_both_ways(): void
    {
        $profile = $this->profile(['renhold', 'tingrett']);

        $this->assertSame(['renhold', 'tingrett'], $this->scorer()->searchKeywords($profile));
        $this->assertSame(['renhold', 'tingrett'], $this->scorer()->scoringKeywords($profile));
    }

    // ------------------------------------------------------------------ searchability

    /** What a register can be narrowed by: something to search for, or something to classify by. */
    public function test_a_profile_is_searchable_when_it_names_keywords_or_cpv_codes(): void
    {
        $this->assertTrue($this->scorer()->hasSearchableCriteria($this->profile(['renhold'])));
        $this->assertTrue($this->scorer()->hasSearchableCriteria($this->profile([], [['cpv_code' => '90910000', 'weight' => 5]])));
        $this->assertFalse($this->scorer()->hasSearchableCriteria($this->profile()));
        $this->assertFalse($this->scorer()->hasSearchableCriteria($this->profile(['   '])));
    }
}
