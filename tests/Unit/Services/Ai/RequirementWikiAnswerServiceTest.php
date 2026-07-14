<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify the Wiki-answer engine (Fase 9) only ever draws on Enterprise Wiki content that
 * is approved and available for the requirement's own customer, never fabricates an answer when
 * coverage is 'none', and persists entirely separately from the existing answer-draft flow.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAnswerServiceTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_it_returns_none_coverage_without_calling_ai_when_no_approved_wiki_content_exists(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen 10 dager.');

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE, $answer->coverage_status);
        $this->assertNull($answer->answer_text);
        $this->assertNotNull($answer->missing_summary);
        $this->assertSame([], $answer->sources);
    }

    public function test_it_ignores_wiki_content_belonging_to_a_different_customer(): void
    {
        $customer = $this->createCustomer('Customer A');
        $otherCustomer = $this->createCustomer('Customer B');
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen 10 dager.');

        $this->createApprovedClaim($otherCustomer, 'Dokumentasjon leveres innen 10 dager i henhold til avtalen.');

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE, $answer->coverage_status);
    }

    public function test_it_ignores_pages_that_are_not_approved(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen 10 dager.');

        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen 10 dager i henhold til avtalen.', [
            'page' => ['status' => EnterpriseWikiPage::STATUS_DRAFT],
        ]);

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE, $answer->coverage_status);
    }

    public function test_it_ignores_claims_flagged_as_conflicting(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen 10 dager.');

        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen 10 dager i henhold til avtalen.', [
            'claim' => ['conflict_flag' => true],
        ]);

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE, $answer->coverage_status);
    }

    public function test_it_ignores_claims_belonging_to_a_superseded_page_version(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen 10 dager.');

        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen 10 dager i henhold til avtalen.', [
            'version' => ['is_current' => false],
        ]);

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE, $answer->coverage_status);
    }

    public function test_it_generates_a_full_coverage_answer_from_relevant_approved_claims(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen ti dager.');

        $claim = $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen ti dager i henhold til avtalen.');

        $this->mock(RequirementWikiAnswerAiClient::class, function (MockInterface $mock) use ($claim): void {
            $mock->shouldReceive('generateAnswer')
                ->once()
                ->withArgs(function (string $identifier, string $text, array $candidates, string $language) use ($claim): bool {
                    return $candidates !== [] && $candidates[0]['claim_key'] === 'claim-'.$claim->id;
                })
                ->andReturn([
                    'coverage_status' => 'full',
                    'answer_text' => 'Dokumentasjon leveres innen ti dager.',
                    'missing_summary' => null,
                    'used_claim_keys' => ['claim-'.$claim->id],
                ]);
        });

        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no', $user->id);

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL, $answer->coverage_status);
        $this->assertSame('Dokumentasjon leveres innen ti dager.', $answer->answer_text);
        $this->assertNull($answer->missing_summary);
        $this->assertCount(1, $answer->sources);
        $this->assertSame($claim->id, $answer->sources[0]['claim_id']);
        $this->assertSame($user->id, $answer->generated_by_user_id);
        $this->assertNotNull($answer->generated_at);
    }

    public function test_it_records_a_partial_coverage_answer_with_missing_summary(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen ti dager i henhold til avtalen.');

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn([
                'coverage_status' => 'partial',
                'answer_text' => 'Dokumentasjon leveres innen ti dager.',
                'missing_summary' => 'Wiki-en dokumenterer ikke hvilket format dokumentasjonen skal leveres i.',
                'used_claim_keys' => [],
            ]));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_PARTIAL, $answer->coverage_status);
        $this->assertSame('Wiki-en dokumenterer ikke hvilket format dokumentasjonen skal leveres i.', $answer->missing_summary);
    }

    /**
     * Anti-fabrication guarantee (task requirement 6): when the AI itself reports 'none' despite
     * candidate claims existing, no answer text is ever persisted.
     */
    public function test_it_never_persists_a_fabricated_answer_when_ai_reports_no_coverage(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen ti dager i henhold til avtalen.');

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn([
                'coverage_status' => 'none',
                'answer_text' => null,
                'missing_summary' => null,
                'used_claim_keys' => [],
            ]));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE, $answer->coverage_status);
        $this->assertNull($answer->answer_text);
        $this->assertSame([], $answer->sources);
    }

    public function test_it_never_reads_or_writes_the_existing_answer_draft_columns(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $requirement->forceFill([
            'answer_draft_text' => 'Eksisterende svarutkast som aldri skal endres.',
            'answer_draft_generated_at' => now(),
        ])->save();

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $requirement->refresh();

        $this->assertSame('Eksisterende svarutkast som aldri skal endres.', $requirement->answer_draft_text);
    }

    public function test_regenerating_updates_the_same_row_instead_of_creating_a_duplicate(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen ti dager.');

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
        $firstCount = SavedNoticeAiRequirementWikiAnswer::query()
            ->where('saved_notice_ai_requirement_id', $requirement->id)
            ->count();

        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen ti dager i henhold til avtalen.');
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn([
                'coverage_status' => 'full',
                'answer_text' => 'Dokumentasjon leveres innen ti dager.',
                'missing_summary' => null,
                'used_claim_keys' => [],
            ]));

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
        $secondCount = SavedNoticeAiRequirementWikiAnswer::query()
            ->where('saved_notice_ai_requirement_id', $requirement->id)
            ->count();

        $this->assertSame(1, $firstCount);
        $this->assertSame(1, $secondCount);
        $this->assertSame(
            SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            $requirement->wikiAnswer()->first()->coverage_status,
        );
    }

    public function test_it_throws_when_candidates_exist_but_wiki_ai_generation_is_disabled(): void
    {
        $customer = $this->createCustomer();
        $requirement = $this->createRequirement($customer, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $this->createApprovedClaim($customer, 'Dokumentasjon leveres innen ti dager i henhold til avtalen.');

        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
    }

    private function createCustomer(string $name = 'Wiki Answer Test AS'): Customer
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
            'is_active' => true,
        ]);
    }

    private function createRequirement(Customer $customer, string $requirementText): SavedNoticeAiRequirement
    {
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'WIKI-ANSWER-'.Str::random(8),
            'title' => 'Wiki answer test case',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/wiki-answer-test',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-04-01 00:00:00',
            'deadline' => '2026-05-01 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'requirement_identifier' => '1.1',
            'requirement_text' => $requirementText,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /**
     * @param  array{page?: array, version?: array, claim?: array}  $overrides
     */
    private function createApprovedClaim(Customer $customer, string $claimText, array $overrides = []): EnterpriseWikiClaim
    {
        $page = EnterpriseWikiPage::query()->create(array_merge([
            'customer_id' => $customer->id,
            'slug' => 'wiki-answer-page-'.Str::lower(Str::random(8)),
            'title' => 'Dokumentasjonskrav',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ], $overrides['page'] ?? []));

        $version = EnterpriseWikiPageVersion::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Dokumentasjonskrav',
        ], $overrides['version'] ?? []));

        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $claimText,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
        ], $overrides['claim'] ?? []));
    }
}
