<?php

namespace Tests\Unit\Services;

use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Contracts\AiTextGenerationClient;
use App\Services\Ai\Requirements\RequirementAnswerDraftService;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * AVVIK-024B — Deterministiske unit-tester for RequirementAnswerDraftService.
 *
 * Tester Procynias håndtering av ugyldig eller mangelfull AI-respons.
 * Ingen ekte OpenAI-kall gjøres — OpenAiClient er mocket i alle tester.
 * DB-fasaden mockes kun i T4 (suksessveien). T1–T3 kaster unntak før DB-skriv.
 */
class RequirementAnswerDraftServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * T1 – Ugyldig JSON fra OpenAI skal ikke lagre svarutkast.
     * Proves that non-JSON text from the AI causes a RuntimeException before
     * the DB transaction is entered, leaving the requirement unchanged.
     */
    public function test_invalid_json_from_openai_does_not_persist_draft(): void
    {
        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->fakeAiResponse('ikke json {'));

        $requirement = $this->requirementFixture();
        $service = new RequirementAnswerDraftService($client);

        try {
            $service->ensureAnswerDraft($requirement, collect());
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);
    }

    /**
     * T2 – Manglende answer_draft_text i JSON skal ikke lagre svarutkast.
     * Proves that valid JSON lacking the required key causes a RuntimeException
     * before the DB transaction is entered, leaving the requirement unchanged.
     */
    public function test_missing_answer_draft_text_field_does_not_persist_draft(): void
    {
        $malformed = json_encode(['wrong_key' => 'Dette er ikke riktig struktur']);

        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->fakeAiResponse((string) $malformed));

        $requirement = $this->requirementFixture();
        $service = new RequirementAnswerDraftService($client);

        try {
            $service->ensureAnswerDraft($requirement, collect());
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);
    }

    /**
     * T3 – Tom answer_draft_text skal ikke lagres som gyldig svar.
     * Proves that valid JSON with a whitespace-only answer_draft_text value
     * causes a RuntimeException before the DB transaction is entered.
     */
    public function test_whitespace_only_answer_draft_text_does_not_persist_draft(): void
    {
        $emptyDraft = json_encode(['answer_draft_text' => '   ']);

        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->fakeAiResponse((string) $emptyDraft));

        $requirement = $this->requirementFixture();
        $service = new RequirementAnswerDraftService($client);

        try {
            $service->ensureAnswerDraft($requirement, collect());
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);
    }

    /**
     * T4 – Gyldig minimal respons lagrer svarutkast.
     * Positive control: proves that a well-formed AI response is parsed,
     * persisted via the mocked DB transaction, and reflected on the model.
     * DB::transaction and model save()/refresh() are mocked — no real DB.
     */
    public function test_valid_minimal_response_is_parsed_and_persisted(): void
    {
        $draftText = 'Dette er et deterministisk testutkast.';
        $validPayload = json_encode(['answer_draft_text' => $draftText]);

        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->fakeAiResponse((string) $validPayload));

        $requirement = $this->requirementFixture();
        $requirement->shouldReceive('save')->andReturn(true);
        $requirement->shouldReceive('refresh')->andReturn($requirement);

        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());

        $service = new RequirementAnswerDraftService($client);
        $service->ensureAnswerDraft($requirement, collect());

        $this->assertSame($draftText, $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
    }

    /**
     * Purpose: Build a deterministic requirement fixture for unit tests.
     * The model is a Mockery partial mock so loadMissing() is intercepted
     * and the evidence relation is pre-set to an empty collection — no DB needed.
     */
    private function requirementFixture(): SavedNoticeAiRequirement
    {
        /** @var SavedNoticeAiRequirement&\Mockery\MockInterface $requirement */
        $requirement = Mockery::mock(SavedNoticeAiRequirement::class)->makePartial();
        $requirement->forceFill([
            'id' => 99,
            'saved_notice_id' => 10,
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);
        $requirement->shouldReceive('loadMissing')->andReturnSelf();
        $requirement->setRelation('evidence', collect([]));

        return $requirement;
    }

    /**
     * Purpose: Build a fake OpenAI Responses API array for a given text payload.
     * Matches the response structure that OpenAiClient::createResponse() returns.
     * Uses output_text (top-level shortcut) so responseTextFromOpenAi() picks it up first.
     */
    private function fakeAiResponse(string $text): array
    {
        return [
            'id' => 'resp_test_' . uniqid(),
            'object' => 'response',
            'status' => 'completed',
            'output_text' => $text,
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $text,
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 40,
                'output_tokens' => 20,
                'total_tokens' => 60,
            ],
        ];
    }
}
