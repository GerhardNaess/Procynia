<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiAssessmentService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Every Wiki read behind a tender answer is filtered by one customer id, and that id arrives as a
 * parameter. RequirementWikiResearchService::research() — the core the answer, the assessment and
 * the operator command all go through — refuses an id that is not the requirement's own, so no
 * entry can ground one customer's bid in another customer's Wiki.
 */
class RequirementWikiResearchCustomerBoundaryTest extends TestCase
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

    /** One customer's requirement is never researched, answered or assessed in another customer's Wiki. */
    public function test_wiki_research_refuses_a_customer_that_does_not_own_the_requirement(): void
    {
        $world = $this->world('Eier AS');
        $other = $this->world('Fremmed Wiki AS');

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));

        $attempts = [
            'research' => fn () => app(RequirementWikiResearchService::class)->research($world['requirement'], (int) $other['customer']->id, 'no'),
            'answer' => fn () => app(RequirementWikiAnswerService::class)->generate($world['requirement'], (int) $other['customer']->id, 'no'),
            'assessment' => fn () => app(RequirementWikiAssessmentService::class)->assessRequirement($world['requirement'], (int) $other['customer']->id, 'no'),
        ];

        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} must be refused for a foreign customer id");
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertFalse(SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->exists());
        $this->assertFalse(DB::table('saved_notice_ai_requirement_assessments')->where('saved_notice_ai_requirement_id', $world['requirement']->id)->exists());

        // The owning customer's own id passes the same check (no Wiki content: an empty context).
        $context = app(RequirementWikiResearchService::class)->research($world['requirement'], (int) $world['customer']->id, 'no');
        $this->assertSame([], $context['pages']);
    }

    /** @return array{customer: Customer, requirement: SavedNoticeAiRequirement} */
    private function world(string $name): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'WIKI-B-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'external_url' => 'https://doffin.no/notices/wiki-b',
            'summary' => 'Kort',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
            'requirement_text' => 'Leverandøren skal ha beredskapsplan.',
            'original_requirement_text' => 'Leverandøren skal ha beredskapsplan.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);

        return ['customer' => $customer, 'requirement' => $requirement];
    }
}
