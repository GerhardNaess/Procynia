<?php

namespace Tests\Feature\App;

use App\Exceptions\RequirementWikiAnswerEditedException;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;
use ZipArchive;

/**
 * A43, the two bounded parts:
 *  1. the Word export carries only the case's current requirements — an answer to a rejected or
 *     replaced requirement is not an ordinary answer in the bid;
 *  2. a hand-edited answer records who edited it, and a new AI generation replaces it only when the
 *     person says so — refused before any AI call, and again under lock if the edit landed while
 *     the generation ran.
 * Answering an unedited requirement works exactly as before.
 */
class TenderAnswerTraceTest extends TestCase
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

    public function test_the_export_leaves_out_answers_to_rejected_and_replaced_requirements(): void
    {
        $world = $this->world();
        $this->answer($this->requirement($world['notice'], 'approved'), 'Svar på godkjent krav.');
        $this->answer($this->requirement($world['notice'], 'draft'), 'Svar på uavgjort krav.');
        $this->answer($this->requirement($world['notice'], 'rejected'), 'Svar på avvist krav.');
        $this->answer($this->requirement($world['notice'], 'approved', SavedNoticeAiRequirement::PUBLICATION_STATUS_SUPERSEDED), 'Svar på erstattet krav.');

        $text = $this->exportedText($world);

        $this->assertStringContainsString('Svar på godkjent krav.', $text);
        $this->assertStringContainsString('Svar på uavgjort krav.', $text);
        $this->assertStringNotContainsString('Svar på avvist krav.', $text);
        $this->assertStringNotContainsString('Svar på erstattet krav.', $text);
    }

    public function test_a_case_with_answers_only_to_rejected_requirements_has_nothing_to_export(): void
    {
        $world = $this->world();
        $this->answer($this->requirement($world['notice'], 'rejected'), 'Svar på avvist krav.');

        $this->actingAs($world['owner'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $world['notice']->id]))
            ->assertStatus(422);
    }

    public function test_a_hand_edit_records_who_edited_the_answer(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice'], 'approved');
        $this->answer($requirement, 'AI-tekst.');

        $this->actingAs($world['contributor'])
            ->patchJson($this->url('app.ai.requirements.wiki-answer.update', $world, $requirement), ['answer_text' => 'Tekst skrevet av bidragsyter.'])
            ->assertOk()
            ->assertJsonPath('wiki_answer.edited_by', $world['contributor']->name);

        $answer = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->sole();
        $this->assertTrue($answer->is_hand_edited);
        $this->assertSame($world['contributor']->id, (int) $answer->edited_by_user_id);
        $this->assertNotNull($answer->edited_at);
    }

    /** Asked first: no AI call, nothing replaced, and the page is told to ask the person. */
    public function test_regenerating_a_hand_edited_answer_asks_first_and_spends_nothing(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice'], 'approved');
        $this->answer($requirement, 'Tekst skrevet av bidragsyter.', edited: $world['contributor']);
        $this->expectNoAnswerAi();

        $this->actingAs($world['owner'])
            ->postJson($this->url('app.ai.requirements.wiki-answer.generate', $world, $requirement))
            ->assertStatus(409)
            ->assertJsonPath('edited', true);

        $this->assertSame('Tekst skrevet av bidragsyter.', $this->answerText($requirement));
    }

    public function test_the_person_can_choose_to_replace_the_edited_answer(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice'], 'approved');
        $this->answer($requirement, 'Tekst skrevet av bidragsyter.', edited: $world['contributor']);
        $this->fakeAnswerAi('Nytt AI-utkast.');

        $this->actingAs($world['owner'])
            ->postJson($this->url('app.ai.requirements.wiki-answer.generate', $world, $requirement), ['replace_edited' => true])
            ->assertOk()
            ->assertJsonPath('wiki_answer.text', 'Nytt AI-utkast.')
            ->assertJsonPath('wiki_answer.is_edited', false)
            ->assertJsonPath('wiki_answer.edited_at', null);

        $answer = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->sole();
        $this->assertNull($answer->edited_by_user_id);
        $this->assertSame($world['owner']->id, (int) $answer->generated_by_user_id);
    }

    /** Edited before editors were recorded (who and when unknown): protected all the same. */
    public function test_an_edit_from_before_the_record_is_protected_and_shown_without_a_name(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice'], 'approved');
        $this->answer($requirement, 'Gammel manuell tekst.');
        SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->update(['is_hand_edited' => true]);
        $this->expectNoAnswerAi();

        $this->actingAs($world['owner'])
            ->postJson($this->url('app.ai.requirements.wiki-answer.generate', $world, $requirement))
            ->assertStatus(409);
        $this->assertSame('Gammel manuell tekst.', $this->answerText($requirement));

        $this->actingAs($world['owner'])
            ->get(route('app.ai.show', ['savedNotice' => $world['notice']->id]))
            ->assertViewHas('page', function (array $page) use ($requirement): bool {
                $answer = collect(data_get($page, 'props.requirements'))->firstWhere('id', $requirement->id)['wiki_answer'];
                $this->assertTrue($answer['is_edited']);
                $this->assertNull($answer['edited_at']);
                $this->assertNull($answer['edited_by']);

                return true;
            });
    }

    /** An answer nobody edited is regenerated as before, without a question. */
    public function test_an_unedited_answer_is_regenerated_without_asking(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice'], 'approved');
        $this->answer($requirement, 'Gammelt AI-utkast.');
        $this->fakeAnswerAi('Nytt AI-utkast.');

        $this->actingAs($world['owner'])
            ->postJson($this->url('app.ai.requirements.wiki-answer.generate', $world, $requirement))
            ->assertOk()
            ->assertJsonPath('wiki_answer.text', 'Nytt AI-utkast.');
    }

    /** Edited while the generation ran: the person's text stays, checked under the row lock. */
    public function test_an_edit_made_during_generation_is_not_overwritten(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice'], 'approved');
        $this->answer($requirement, 'Gammelt AI-utkast.');

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturnUsing(function () use ($requirement, $world): array {
                // A colleague saves a hand-edit while the model is still writing.
                SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)
                    ->update(['answer_text' => 'Redigert underveis.', 'is_hand_edited' => true, 'edited_by_user_id' => $world['contributor']->id, 'edited_at' => now()]);

                return ['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Nytt AI-utkast.', 'used_page_ids' => []]]];
            }));

        try {
            app(RequirementWikiAnswerService::class)->generate($requirement, (int) $world['customer']->id, 'no', $world['owner']);
            $this->fail('the generation overwrote an edit made while it ran');
        } catch (RequirementWikiAnswerEditedException) {
            // kept
        }

        $this->assertSame('Redigert underveis.', $this->answerText($requirement));
    }

    private function exportedText(array $world): string
    {
        $response = $this->actingAs($world['owner'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $world['notice']->id]))
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'a43').'.docx';
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the export is a Word file');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return strip_tags($xml);
    }

    private function url(string $route, array $world, SavedNoticeAiRequirement $requirement): string
    {
        return route($route, ['savedNotice' => $world['notice']->id, 'requirement' => $requirement->id]);
    }

    private function answerText(SavedNoticeAiRequirement $requirement): string
    {
        return (string) SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->value('answer_text');
    }

    private function expectNoAnswerAi(): void
    {
        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
    }

    private function fakeAnswerAi(string $text): void
    {
        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => $text, 'used_page_ids' => []]]]));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));
    }

    /** @return array{customer: Customer, owner: User, contributor: User, notice: SavedNotice} */
    private function world(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Svar AS',
            'slug' => 'svar-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 20,
        ]);

        $person = fn (string $label, string $bidRole, string $role = User::ROLE_USER): User => User::factory()->create([
            'name' => $label,
            'email' => Str::slug($label).'.'.Str::lower(Str::random(6)).'@example.test',
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $owner = $person('Eier', User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);
        $contributor = $person('Bidragsyter', User::BID_ROLE_CONTRIBUTOR);

        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $owner->id,
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'A43-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'status' => 'ACTIVE',
        ]);

        return compact('customer', 'owner', 'contributor', 'notice');
    }

    private function requirement(SavedNotice $notice, string $approval, string $publication = SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED): SavedNoticeAiRequirement
    {
        $review = ['approved' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED, 'rejected' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED][$approval]
            ?? SavedNoticeAiRequirement::REVIEW_STATUS_PENDING;

        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => $approval,
            'review_status' => $review,
            'publication_status' => $publication,
            'published_at' => now(),
            'requirement_identifier' => 'K-'.Str::upper(Str::random(4)),
            'requirement_text' => 'Krav '.Str::random(6),
            'original_requirement_text' => 'Krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);
    }

    private function answer(SavedNoticeAiRequirement $requirement, string $text, ?User $edited = null): void
    {
        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => $text,
            'sources' => [],
            'generated_at' => now()->subHour(),
            'is_hand_edited' => $edited !== null,
            'edited_by_user_id' => $edited?->id,
            'edited_at' => $edited !== null ? now()->subMinutes(5) : null,
        ]);
    }
}
