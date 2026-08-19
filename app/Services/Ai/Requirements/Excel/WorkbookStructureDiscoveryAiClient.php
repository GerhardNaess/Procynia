<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookFieldRoleData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Asks a model one question: how is this workbook organised?
 *
 * Follows the same shape as the existing planning clients (see
 * EnterpriseWikiSemanticSearchPlanAiClient): OpenAiClient + the shared Responses decoder, strict
 * json_schema, temperature 0, an explicit output-token bound, and metrics returned alongside the
 * result. No new client style, no new retry mechanism.
 *
 * The division of labour is the important part. The model is good at reading intent from layout —
 * that this band of merged cells is a section heading, that the mostly-empty column on the right
 * is where the supplier answers. It is not the authority on anything factual: every coordinate it
 * returns is checked against the real workbook by WorkbookSchemaValidator before use, and its
 * output is a proposal until then.
 *
 * What it is explicitly NOT asked to do: read requirements, judge them, rewrite them, or classify
 * them against Procynia's own categories. This phase produces a map of the workbook, not content.
 */
class WorkbookStructureDiscoveryAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 3000;

    private const TIMEOUT_SECONDS = 120;

    private const PROMPT_NAME = 'excel_workbook_structure_discovery';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Purpose: Run structure discovery for one workbook orientation.
     * Inputs: The compact orientation from WorkbookOrientationBuilder.
     * Returns: The RAW discovery result plus call metrics. Never trusted directly — hand it to
     *          WorkbookSchemaValidator together with the workbook it came from.
     * Side effects: One OpenAI call.
     *
     * @param  array<string, mixed>  $orientation
     * @return array{discovery: array<string, mixed>, metrics: array<string, ?int>}
     */
    public function discoverStructure(array $orientation, string $languageCode = 'no'): array
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('WorkbookStructureDiscoveryAiClient: AI is not enabled.');
        }

        $startedAt = microtime(true);
        $payload = $this->buildPayload($orientation, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: self::TIMEOUT_SECONDS);
        $decoded = $this->responsesDecoder->decode($response, 'WorkbookStructureDiscoveryAiClient');

        return [
            'discovery' => $decoded,
            'metrics' => [
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'input_tokens' => $this->integer($response['usage']['input_tokens'] ?? null),
                'output_tokens' => $this->integer($response['usage']['output_tokens'] ?? null),
                // Orientation size is the cost driver worth watching; the orientation itself is
                // never logged, because a customer's requirement text is in it.
                'orientation_chars' => mb_strlen(json_encode($orientation) ?: '', 'UTF-8'),
            ],
        ];
    }

    /** @param  array<string, mixed>  $orientation */
    private function buildPayload(array $orientation, string $languageName): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => $this->developerPrompt($languageName)]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($orientation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            'You are given a compact description of one Excel workbook: its sheets, their dimensions, merged ranges, per-column statistics, and a deterministic sample of rows with real cell coordinates.',
            sprintf('The workbook content is typically in %s. Do not translate anything.', $languageName),
            '',
            'Your ONLY task is to describe HOW this workbook is organised, so a separate system can later read the requirements out of it correctly.',
            '',
            'You are NOT asked to:',
            '- read, summarize, rewrite, judge or classify any requirement,',
            '- decide whether a requirement is good, mandatory in substance, or relevant,',
            '- produce any requirement text in your answer.',
            '',
            'Rules that make your answer usable:',
            '- Use ONLY sheet indexes, row numbers and column letters that appear in the description you were given. Every coordinate is verified against the real workbook afterwards, and an invented one makes the whole answer unusable.',
            '- Ranges are A1-style within a single sheet, e.g. "B17:G240". Never write a range that spans two sheets.',
            '- data_range must start at or after the first data row and must not extend past the sheet\'s last used row.',
            '- Column letters in field_roles must lie inside the data_range you chose for that sheet.',
            '',
            'Deciding which sheets hold requirements:',
            '- A workbook often has an introduction sheet, a price form and one or more requirement sheets. Put the requirement ones in requirement_sheets and the rest in supporting_sheets with a short reason.',
            '- A sheet may be hidden and still hold requirements. Judge it by its content, not by its visibility, and note the visibility in a warning if you rely on a hidden sheet.',
            '',
            'Field roles — describe the PART a column plays, never what it is called:',
            '- requirement_id: a per-requirement identifier or number.',
            '- requirement_text: the requirement wording itself. Use it more than once when the wording is genuinely split across columns (for example a short title column plus a detail column).',
            '- qualification: whether the requirement is absolute or desirable (however the workbook expresses that).',
            '- weighting: a weight, score or points column.',
            '- response: a column the supplier is meant to fill in (usually mostly empty).',
            '- comment: explanatory or contextual notes.',
            '- section: a column that labels which section or category a row belongs to.',
            '- other: anything the roles above do not genuinely fit. Prefer "other" over a forced guess.',
            '',
            'How a logical requirement is formed — this decides whether requirements get split or merged later, so choose carefully:',
            '- "row": one row of the data region is one requirement.',
            '- "merged_group": one requirement spans several consecutive rows, held together by a merged cell or a repeated identifier. Give grouping_column_letter for the column that holds the group together.',
            '- "section_grouped_row": each row is its own requirement, but rows sit under section heading rows. List those heading rows in section_row_numbers.',
            '',
            'Also report:',
            '- header_range when a header band exists (it may span several rows, and merged header cells are normal), or null when the sheet has none.',
            '- section_row_numbers for rows that label a section rather than state a requirement.',
            '- warnings for anything a human should look at: an ambiguous column, a sheet you were unsure about, a layout that changes partway down.',
            '- confidence between 0 and 1, honestly.',
            '- reason: one or two sentences, enough to debug your choice. Not a report.',
            '',
            'Return only JSON matching the schema. No text before or after the JSON.',
        ]);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'requirement_sheets' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sheet_index' => ['type' => 'integer'],
                            'sheet_name' => ['type' => 'string'],
                            'header_range' => ['type' => ['string', 'null']],
                            'data_range' => ['type' => 'string'],
                            'logical_unit_strategy' => ['type' => 'string', 'enum' => WorkbookSheetSchemaData::LOGICAL_UNIT_STRATEGIES],
                            'grouping_column_letter' => ['type' => ['string', 'null']],
                            'section_row_numbers' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'field_roles' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'column_letter' => ['type' => 'string'],
                                        'role' => ['type' => 'string', 'enum' => WorkbookFieldRoleData::ROLES],
                                        'header_label' => ['type' => ['string', 'null']],
                                        'confidence' => ['type' => ['number', 'null']],
                                    ],
                                    'required' => ['column_letter', 'role', 'header_label', 'confidence'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'confidence' => ['type' => ['number', 'null']],
                            'reason' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'sheet_index', 'sheet_name', 'header_range', 'data_range', 'logical_unit_strategy',
                            'grouping_column_letter', 'section_row_numbers', 'field_roles', 'warnings', 'confidence', 'reason',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'supporting_sheets' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sheet_index' => ['type' => 'integer'],
                            'sheet_name' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['sheet_index', 'sheet_name', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => ['number', 'null']],
            ],
            'required' => ['requirement_sheets', 'supporting_sheets', 'warnings', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
