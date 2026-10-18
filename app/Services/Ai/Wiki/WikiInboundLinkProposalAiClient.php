<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiOperationCatalog;
use RuntimeException;

/**
 * Proposes ONE inbound link from an existing Wiki page to a page the current run created (relink
 * v2, D8 — docs/enterprise-wiki-knowledge-integration-plan.md §6.4).
 *
 * The model never returns page content. It is shown the existing page as its content blocks and
 * returns a placement only: which block, which words in it carry the link, and why. The backend
 * (EnterpriseWikiInboundLinkInserter) inserts the link markup into the unchanged text and proves
 * mechanically that nothing else changed. The previous contract asked the model to return the whole
 * page rewritten, truncated to 10 000 characters, and could not prove the rest was untouched.
 *
 * The model also decides whether a link is justified at all: "no_link" is a normal answer.
 */
class WikiInboundLinkProposalAiClient
{
    public const OPERATION = 'wiki.propose_inbound_links';

    public const DECISION_LINK = 'link';

    public const DECISION_NO_LINK = 'no_link';

    /** The model is chosen centrally per operation — config/ai_operations.php. */
    public static function model(): string
    {
        return AiOperationCatalog::model(self::OPERATION);
    }

    public const PROMPT_VERSION = '1.0';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 400;

    private const PROMPT_NAME = 'wiki_inbound_link_proposal';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * @param  array{title: string, page_type: string}  $existingPage  the page that may receive the link
     * @param  list<array{block_key: string, markdown: string}>  $blocks  whole blocks, never truncated
     * @param  array{page_id: int, title: string, page_type: string, excerpt: ?string}  $newPage  the only allowed target
     * @return array{decision: string, block_key: ?string, anchor_text: ?string, reason: ?string}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid
     */
    public function propose(array $existingPage, array $blocks, array $newPage, string $languageCode): array
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiInboundLinkProposalAiClient: wiki AI is not enabled.');
        }

        $payload = [
            'model' => self::model(),
            'input' => [
                ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => $this->developerPrompt($this->languageName($languageCode))]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $this->userPrompt($existingPage, $blocks, $newPage)]]],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(array_column($blocks, 'block_key')),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];

        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120, operation: self::OPERATION);
        $decoded = $this->responsesDecoder->decode($response, 'WikiInboundLinkProposalAiClient');

        $decision = data_get($decoded, 'decision');

        if (! in_array($decision, [self::DECISION_LINK, self::DECISION_NO_LINK], true)) {
            throw new RuntimeException('WikiInboundLinkProposalAiClient: response has no valid decision.');
        }

        $stringOrNull = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? $value : null;

        return [
            'decision' => $decision,
            'block_key' => $stringOrNull(data_get($decoded, 'block_key')),
            'anchor_text' => $stringOrNull(data_get($decoded, 'anchor_text')),
            'reason' => $stringOrNull(data_get($decoded, 'reason')),
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            "You decide whether an EXISTING wiki page should get one link to a NEW wiki page. The content language is {$languageName}.",
            '',
            'You are given the existing page as numbered CONTENT BLOCKS and the NEW PAGE (title, type, excerpt).',
            'You do not write or change any content. You only point at where a link belongs.',
            '',
            'Choose decision "link" only when a block of the existing page genuinely discusses the subject',
            'the new page explains, so a reader of that block would benefit from following the link. A shared',
            'word, a passing mention or a merely related field is not enough — then choose "no_link".',
            '',
            'When decision is "link":',
            '- block_key: the block_key of exactly one block from CONTENT BLOCKS.',
            '- anchor_text: 1-8 consecutive words copied character for character from that block\'s markdown.',
            '  They must appear exactly once in that block, as ordinary prose — never inside a heading, a',
            '  table row, a code span, or an existing link — and must name the new page\'s subject.',
            '- reason: one short sentence on why the block relates to the new page.',
            'When decision is "no_link": set block_key, anchor_text and reason to null.',
            '',
            'Never write link syntax such as [[...]] or [..](..), never return page content, never propose',
            'more than one link.',
            '',
            'Return only JSON matching the schema.',
        ]);
    }

    /**
     * @param  array{title: string, page_type: string}  $existingPage
     * @param  list<array{block_key: string, markdown: string}>  $blocks
     * @param  array{page_id: int, title: string, page_type: string, excerpt: ?string}  $newPage
     */
    private function userPrompt(array $existingPage, array $blocks, array $newPage): string
    {
        $parts = [
            '--- NEW PAGE (the only allowed link target) ---',
            '',
            (string) json_encode([
                'title' => $newPage['title'],
                'page_type' => $newPage['page_type'],
                'excerpt' => $newPage['excerpt'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            "--- EXISTING PAGE: {$existingPage['title']} ({$existingPage['page_type']}) — CONTENT BLOCKS ---",
        ];

        foreach ($blocks as $block) {
            $parts[] = '';
            $parts[] = "[block_key: {$block['block_key']}]";
            $parts[] = $block['markdown'];
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>  $blockKeys
     * @return array<string, mixed>
     */
    private static function schema(array $blockKeys): array
    {
        $blockKeySchema = ['type' => ['string', 'null']];

        if ($blockKeys !== []) {
            $blockKeySchema['enum'] = [...array_values($blockKeys), null];
        }

        return [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => [self::DECISION_LINK, self::DECISION_NO_LINK]],
                'block_key' => $blockKeySchema,
                'anchor_text' => ['type' => ['string', 'null']],
                'reason' => ['type' => ['string', 'null']],
            ],
            'required' => ['decision', 'block_key', 'anchor_text', 'reason'],
            'additionalProperties' => false,
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
