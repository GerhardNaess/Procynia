<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Verifies whether a single claim is semantically supported by one or more concrete source
 * excerpts — never the raw whole document — and returns a structured verdict.
 *
 * Cross-language / paraphrase fix (see EnterpriseWikiClaimCanonicalizationService for the
 * deterministic safety net this feeds into): a Wiki claim is generated in the customer's Wiki
 * language, but the underlying tender document is frequently bilingual (e.g. Norwegian narrative
 * interleaved with English contractual clauses). The previous version of this client demanded a
 * verbatim, same-language quote and searched the entire (truncated) document text — a Norwegian
 * paraphrase of an English clause can never satisfy that, producing a false "unsupported"
 * verdict for content that is, in fact, faithfully sourced. This client now receives the
 * claim's own already-cited candidate source elements (never the whole document, unless no
 * candidates exist at all) and is explicitly instructed to judge sameness of MEANING across
 * languages and phrasing, while a structured checks block forces it to reason about the parts of
 * meaning (actor, action, object, modality, negation, numbers, time, scope, conditions) that a
 * free-text "supported: true" cannot be trusted to have actually checked.
 *
 * The backend (EnterpriseWikiVerifyPageClaimsService) never trusts a "supported"/
 * "partially_supported" verdict blindly: it revalidates supporting_source_element_keys against
 * the candidates actually offered, and runs a deterministic conflict check (numbers, dates,
 * negation, modality, actor, scope) against the specific excerpt(s) the model cited as support —
 * never against the whole candidate pool, which would let unrelated paragraphs be combined into
 * manufactured support no single source excerpt gives (Del 5).
 */
class WikiClaimVerificationAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 900;

    private const MAX_SOURCE_CHARS = 8000;

    private const MAX_ELEMENT_EXCERPT_CHARS = 1500;

    private const PROMPT_NAME = 'wiki_claim_verification';

    public const VERDICT_SUPPORTED = 'supported';

    public const VERDICT_PARTIALLY_SUPPORTED = 'partially_supported';

    public const VERDICT_CONTRADICTED = 'contradicted';

    public const VERDICT_NOT_SUPPORTED = 'not_supported';

    public const VERDICTS = [
        self::VERDICT_SUPPORTED,
        self::VERDICT_PARTIALLY_SUPPORTED,
        self::VERDICT_CONTRADICTED,
        self::VERDICT_NOT_SUPPORTED,
    ];

    /** Source element key used when no structured candidates exist — the legacy whole-document fallback. */
    public const FALLBACK_SOURCE_ELEMENT_KEY = 'whole_document_fallback';

    private const CHECK_DIMENSIONS = [
        'actor', 'action', 'object', 'modality', 'negation',
        'numbers_and_units', 'time_and_date', 'scope', 'conditions_and_exceptions',
        'subject_entity',
    ];

    private const CHECK_VALUES = ['match', 'mismatch', 'not_applicable'];

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Verify $claimText against one or more concrete candidate source excerpts.
     *
     * @param  list<array{key: string, type: ?string, excerpt: string, page_reference: ?string}>  $sourceElements
     *                                                                                                             The claim's own already-cited candidate source elements (from its content block).
     *                                                                                                             Empty when no structured elements exist — $fallbackSourceText is used instead.
     * @return array{
     *     verdict: string,
     *     same_meaning_across_languages: bool,
     *     claim_language: string,
     *     source_language: string,
     *     supporting_source_element_keys: list<string>,
     *     reason: string,
     *     unsupported_parts: string,
     *     checks: array<string, string>,
     * }
     *
     * @throws RuntimeException on API error, empty or invalid response
     */
    public function verifyClaim(
        string $claimText,
        array $sourceElements,
        string $fallbackSourceText,
        string $languageCode,
        ?string $blockMarkdown = null,
        ?string $documentLabel = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiClaimVerificationAiClient: wiki AI generation is not enabled.');
        }

        $candidates = $this->normalizeCandidates($sourceElements, $fallbackSourceText);
        $payload = $this->buildPayload($claimText, $candidates, $blockMarkdown, $documentLabel, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'WikiClaimVerificationAiClient');

        return $this->validate($decoded, array_column($candidates, 'key'));
    }

    /**
     * @param  list<array{key: string, type: ?string, excerpt: string, page_reference: ?string}>  $sourceElements
     * @return list<array{key: string, type: ?string, excerpt: string, page_reference: ?string}>
     */
    private function normalizeCandidates(array $sourceElements, string $fallbackSourceText): array
    {
        $candidates = [];

        foreach ($sourceElements as $element) {
            $key = trim((string) ($element['key'] ?? ''));
            $excerpt = trim((string) ($element['excerpt'] ?? ''));

            if ($key === '' || $excerpt === '') {
                continue;
            }

            $candidates[] = [
                'key' => $key,
                'type' => $element['type'] ?? null,
                'excerpt' => mb_substr($excerpt, 0, self::MAX_ELEMENT_EXCERPT_CHARS),
                'page_reference' => $element['page_reference'] ?? null,
            ];
        }

        if ($candidates !== []) {
            return $candidates;
        }

        $fallback = trim($fallbackSourceText);

        if ($fallback === '') {
            return [];
        }

        return [[
            'key' => self::FALLBACK_SOURCE_ELEMENT_KEY,
            'type' => null,
            'excerpt' => mb_substr($fallback, 0, self::MAX_SOURCE_CHARS),
            'page_reference' => null,
        ]];
    }

    private function buildPayload(string $claimText, array $candidates, ?string $blockMarkdown, ?string $documentLabel, string $languageName): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($claimText, $candidates, $blockMarkdown, $documentLabel),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(array_column($candidates, 'key')),
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
            'You are a fact-checking model for a tender/procurement knowledge base. You are given a',
            'claim (written in the customer\'s Wiki language) and one or more candidate source excerpts',
            'taken from the underlying source document, which may be written in a different language',
            "than the claim (source document context language: {$languageName}, but individual excerpts",
            'may be in Norwegian, English, or both — judge each excerpt on its own actual language).',
            '',
            'Your task: decide whether the claim expresses the SAME FACT(S) as the candidate excerpts,',
            'considered TOGETHER as one body of evidence — judging MEANING, not wording or language.',
            '',
            'You MUST accept as equivalent (never a reason to reject):',
            '- a different language between claim and source excerpt (translation),',
            '- different word order or a natural paraphrase/rewording,',
            '- an abbreviation written out in full or vice versa,',
            '- a different but equivalent way of writing a date, time, or number',
            '  (e.g. "09:00" and "09.00"; "30 min" and "30 minutter"; "Monday-Friday" and "mandag til fredag";',
            '  "quarterly" and "hvert kvartal"),',
            '- Wiki text that is more fluent/polished than the source excerpts, as long as the facts match,',
            '- a claim that synthesizes ONE topic/entity/process described across SEVERAL candidate',
            '  excerpts into a single, more fluent sentence — e.g. one excerpt names a process and another',
            '  excerpt describes what it accomplishes; combining them into "X is used to achieve Y" is',
            '  normal paraphrase/synthesis, not fabrication, as long as every excerpt you combine is',
            '  actually about the SAME named entity/process the claim itself names. Cite every excerpt',
            '  that contributes supporting evidence, not just one.',
            '',
            'You MUST reject (never accept just because the general topic is similar, and never accept',
            'just because some words happen to overlap somewhere across the combined excerpts):',
            '- a changed number, percentage, currency amount, time, date, or duration,',
            '- a changed responsible party/actor (e.g. supplier vs. customer),',
            '- a changed modality/certainty (e.g. "may"/"can" turned into "shall"/"must", or a recommendation',
            '  turned into a statement that something already exists or is already done),',
            '- a negation added, removed, or reversed,',
            '- a narrower scope in the source widened in the claim (e.g. "critical cases" generalized to',
            '  "all cases", or specific weekdays generalized to every day),',
            '- misattribution: the claim names a specific entity/process (e.g. "incident management") and',
            '  describes an action, function, or responsibility that the excerpts actually state about a',
            '  DIFFERENT named entity/process (e.g. "service desk") — combining excerpts never licenses',
            '  transplanting one thing\'s described role onto a different thing\'s name. The specific named',
            '  subject of the claim must match the specific named subject in the excerpt(s) you cite. This',
            '  applies even when a separate excerpt lists several entities/processes together as related —',
            '  e.g. one excerpt lists "incident management, request management, and problem management" as',
            '  related practices, and a second excerpt states that "the service desk handles registration,',
            '  prioritization, and follow-up of cases" — a claim that "incident management handles',
            '  registration, prioritization, and follow-up" is misattribution: being named together in a',
            '  list is NOT the same as sharing a specifically-described function, and the excerpt describing',
            '  that specific function names a different, single entity as its subject,',
            '- reinforcement: the claim adds emphasis, ranking, or a superlative that no excerpt states',
            '  (e.g. calling something "central"/"key"/"crucial" when the excerpts merely list it as one of',
            '  several equivalent items, with nothing singling it out),',
            '- a claim that requires connecting excerpts about genuinely UNRELATED topics/entities in a way',
            '  none of them, even combined, actually states — only combine excerpts that are already about',
            '  the same subject the claim itself is about.',
            '',
            'Verdict values:',
            '- "supported": the claim and the candidate excerpts (individually or combined, per the rules',
            '  above) express the same fact(s) — language, wording, and formatting differences are irrelevant.',
            '- "partially_supported": some of the claim is supported, but it also asserts something extra or',
            '  stronger than the candidate excerpts actually state — explain the unsupported part.',
            '- "contradicted": a candidate excerpt states something that directly conflicts with the claim.',
            '- "not_supported": no candidate excerpt, alone or combined, supports the claim at all.',
            '',
            'For the "checks" object, evaluate each dimension strictly between the claim and the combined',
            'set of excerpts you cite as support: "match" (equivalent), "mismatch" (genuinely different), or',
            '"not_applicable" (the dimension is not part of this claim, e.g. no numbers are involved).',
            '',
            'The "subject_entity" check is the most important defense against misattribution and deserves',
            'its own careful, literal reasoning, separate from general topic similarity:',
            '- Identify the claim\'s own named subject — the specific entity/process the claim itself is',
            '  ABOUT (e.g. "incident management", "the service desk").',
            '- Identify which excerpt(s), if any, explicitly and specifically describe THAT SAME named',
            '  subject performing/having the exact action or property the claim asserts.',
            '- "match": the excerpt(s) you are citing for that specific action/property explicitly name',
            '  the claim\'s own subject as performing it.',
            '- "mismatch": the specific action/property is only explicitly stated for a DIFFERENT named',
            '  subject in the excerpts — even if the claim\'s subject is mentioned somewhere else, e.g. in',
            '  an unrelated list of related items (being named in the same list as something is NOT the',
            '  same as being the one the excerpt says performs the action). Merely sharing a general topic,',
            '  category, or being mentioned in the same sentence/list as the true subject is never "match".',
            '- "not_applicable" only when the claim does not attribute an action/property to any specific',
            '  named subject at all (a genuinely general statement).',
            'If "subject_entity" is "mismatch", the overall verdict must be "not_supported" or',
            '"partially_supported" — never "supported" — regardless of how well other parts of the claim',
            'are worded like the excerpts.',
            '',
            'supporting_source_element_keys must be a subset of the candidate keys given to you, and empty',
            'when verdict is "not_supported" — cite every excerpt that contributes supporting evidence when',
            'combining several, not just the first one. reason must be a short, concrete, human-readable',
            'explanation (state the actual mismatch when relevant, e.g. which number/actor/modality differs,',
            'or which entity the claim misattributes) — never a generic phrase. unsupported_parts must name',
            'the specific part of the claim without support when verdict is "partially_supported", and must',
            'be an empty string otherwise.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    /**
     * @param  list<array{key: string, type: ?string, excerpt: string, page_reference: ?string}>  $candidates
     */
    private function userPrompt(string $claimText, array $candidates, ?string $blockMarkdown, ?string $documentLabel): string
    {
        $lines = ["Claim: {$claimText}", ''];

        if ($documentLabel !== null && trim($documentLabel) !== '') {
            $lines[] = "Source document: {$documentLabel}";
            $lines[] = '';
        }

        if ($blockMarkdown !== null && trim($blockMarkdown) !== '') {
            $lines[] = 'Wiki text the claim was extracted from (context only, not itself a source):';
            $lines[] = trim($blockMarkdown);
            $lines[] = '';
        }

        $lines[] = 'Candidate source excerpts:';

        foreach ($candidates as $candidate) {
            $reference = $candidate['page_reference'] !== null && trim((string) $candidate['page_reference']) !== ''
                ? " ({$candidate['page_reference']})"
                : '';

            $lines[] = "- [{$candidate['key']}]{$reference}: {$candidate['excerpt']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $candidateKeys
     * @return array<string, mixed>
     */
    private static function schema(array $candidateKeys): array
    {
        $keyEnum = $candidateKeys !== [] ? array_values(array_unique($candidateKeys)) : [self::FALLBACK_SOURCE_ELEMENT_KEY];

        $checkProperties = [];

        foreach (self::CHECK_DIMENSIONS as $dimension) {
            $checkProperties[$dimension] = ['type' => 'string', 'enum' => self::CHECK_VALUES];
        }

        return [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => self::VERDICTS],
                'same_meaning_across_languages' => ['type' => 'boolean'],
                'claim_language' => ['type' => 'string'],
                'source_language' => ['type' => 'string'],
                'supporting_source_element_keys' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $keyEnum],
                ],
                'reason' => ['type' => 'string'],
                'unsupported_parts' => ['type' => 'string'],
                'checks' => [
                    'type' => 'object',
                    'properties' => $checkProperties,
                    'required' => self::CHECK_DIMENSIONS,
                    'additionalProperties' => false,
                ],
            ],
            'required' => [
                'verdict', 'same_meaning_across_languages', 'claim_language', 'source_language',
                'supporting_source_element_keys', 'reason', 'unsupported_parts', 'checks',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Backend-side structural validation — never trust the model's free-text alone. An
     * unrecognized verdict, a malformed checks object, or a cited key outside the candidates
     * actually offered is treated as a decode failure (Del 2: "Backend skal validere responsen").
     *
     * @param  list<string>  $candidateKeys
     * @return array{verdict: string, same_meaning_across_languages: bool, claim_language: string, source_language: string, supporting_source_element_keys: list<string>, reason: string, unsupported_parts: string, checks: array<string, string>}
     */
    private function validate(array $decoded, array $candidateKeys): array
    {
        $verdict = $decoded['verdict'] ?? null;

        if (! is_string($verdict) || ! in_array($verdict, self::VERDICTS, true)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: response had an invalid or missing verdict.');
        }

        if (! is_bool($decoded['same_meaning_across_languages'] ?? null)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: response is missing required fields.');
        }

        $reason = $decoded['reason'] ?? null;
        $unsupportedParts = $decoded['unsupported_parts'] ?? null;
        $claimLanguage = $decoded['claim_language'] ?? null;
        $sourceLanguage = $decoded['source_language'] ?? null;

        if (! is_string($reason) || ! is_string($unsupportedParts) || ! is_string($claimLanguage) || ! is_string($sourceLanguage)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: response is missing required fields.');
        }

        $supportingKeys = $decoded['supporting_source_element_keys'] ?? null;

        if (! is_array($supportingKeys)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: response is missing required fields.');
        }

        // Never trust a cited key the candidate list did not actually contain — filtered out
        // rather than trusted, so a hallucinated key cannot manufacture false support.
        $validatedKeys = array_values(array_intersect(
            array_filter($supportingKeys, 'is_string'),
            $candidateKeys,
        ));

        $checks = $decoded['checks'] ?? null;

        if (! is_array($checks)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: response is missing required fields.');
        }

        $validatedChecks = [];

        foreach (self::CHECK_DIMENSIONS as $dimension) {
            $value = $checks[$dimension] ?? null;

            if (! is_string($value) || ! in_array($value, self::CHECK_VALUES, true)) {
                throw new RuntimeException("WikiClaimVerificationAiClient: response checks.{$dimension} was invalid or missing.");
            }

            $validatedChecks[$dimension] = $value;
        }

        return [
            'verdict' => $verdict,
            'same_meaning_across_languages' => (bool) $decoded['same_meaning_across_languages'],
            'claim_language' => $claimLanguage,
            'source_language' => $sourceLanguage,
            'supporting_source_element_keys' => $validatedKeys,
            'reason' => $reason,
            'unsupported_parts' => $unsupportedParts,
            'checks' => $validatedChecks,
        ];
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
