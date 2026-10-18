<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiInboundLinkInserter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Relink v2 (D8): the backend, not the model, writes the inbound link — and proves that nothing
 * but link markup changed.
 */
class EnterpriseWikiInboundLinkInserterTest extends TestCase
{
    private const INTENT = ['intent_id' => 'inbound-1-2', 'target_page_id' => 2, 'anchor_text' => 'risikovurdering', 'reason' => 'x'];

    public function test_the_link_is_inserted_into_unchanged_text_and_recorded_on_its_block(): void
    {
        [$markdown, $blocks] = $this->page(['# Tittel', 'Vi gjør en risikovurdering hvert år.', 'Siste avsnitt.']);

        $result = (new EnterpriseWikiInboundLinkInserter)->insert($markdown, $blocks, 'b2', 'risikovurdering', 'risiko', self::INTENT);

        $this->assertTrue($result['ok']);
        $this->assertSame("# Tittel\n\nVi gjør en [[risiko|risikovurdering]] hvert år.\n\nSiste avsnitt.", $result['content_markdown']);
        $this->assertSame($markdown, EnterpriseWikiInboundLinkInserter::stripWikilinks($result['content_markdown']));
        $this->assertSame($blocks[0], $result['content_blocks_json'][0]);
        $this->assertSame($blocks[2], $result['content_blocks_json'][2]);
        $this->assertSame('source_based', $result['content_blocks_json'][1]['content_origin']);
        $this->assertSame([self::INTENT], $result['content_blocks_json'][1]['link_intents']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function protectedContexts(): array
    {
        return [
            'heading' => ['## risikovurdering', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'table row' => ['| risikovurdering | årlig |', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'fenced code' => ["```\nrisikovurdering\n```", 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'inline code' => ['Kjør `risikovurdering` nå.', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'markdown link' => ['Se [risikovurdering](https://example.test).', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'image' => ['![risikovurdering](bilde.png)', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'existing wikilink' => ['Se [[annen|risikovurdering]].', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'html' => ['<span title="risikovurdering">x</span>', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_PROTECTED],
            'two occurrences' => ['En risikovurdering og en ny risikovurdering.', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_AMBIGUOUS_ANCHOR],
            'part of a word' => ['Risikovurderingene er ferdige.', 'Risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_NOT_FOUND],
            'absent' => ['Ingenting her.', 'risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_ANCHOR_NOT_FOUND],
            'pipe in anchor' => ['a|b c', 'a|b', EnterpriseWikiInboundLinkInserter::REJECT_INVALID_ANCHOR],
            'bracket in anchor' => ['se [x] her', '[x]', EnterpriseWikiInboundLinkInserter::REJECT_INVALID_ANCHOR],
            'padded anchor' => ['en risikovurdering her', ' risikovurdering', EnterpriseWikiInboundLinkInserter::REJECT_INVALID_ANCHOR],
        ];
    }

    #[DataProvider('protectedContexts')]
    public function test_a_protected_ambiguous_or_invalid_anchor_is_rejected(string $paragraph, string $anchor, string $reason): void
    {
        [$markdown, $blocks] = $this->page(['# Tittel', $paragraph]);

        $result = (new EnterpriseWikiInboundLinkInserter)->insert($markdown, $blocks, 'b2', $anchor, 'risiko', self::INTENT);

        $this->assertSame(['ok' => false, 'reason' => $reason], $result);
    }

    public function test_an_unprotected_occurrence_next_to_a_protected_one_is_used(): void
    {
        [$markdown, $blocks] = $this->page(['Se `risikovurdering` og gjør en risikovurdering.']);

        $result = (new EnterpriseWikiInboundLinkInserter)->insert($markdown, $blocks, 'b1', 'risikovurdering', 'risiko', self::INTENT);

        $this->assertSame('Se `risikovurdering` og gjør en [[risiko|risikovurdering]].', $result['content_markdown']);
    }

    public function test_table_and_image_blocks_and_unknown_blocks_are_never_edited(): void
    {
        [$markdown, $blocks] = $this->page(['Tekst med risikovurdering.']);
        $blocks[0]['block_type'] = 'table';
        $inserter = new EnterpriseWikiInboundLinkInserter;

        $this->assertSame(EnterpriseWikiInboundLinkInserter::REJECT_INELIGIBLE_BLOCK, $inserter->insert($markdown, $blocks, 'b1', 'risikovurdering', 'risiko', self::INTENT)['reason']);
        $this->assertSame(EnterpriseWikiInboundLinkInserter::REJECT_UNKNOWN_BLOCK, $inserter->insert($markdown, $blocks, 'nope', 'risikovurdering', 'risiko', self::INTENT)['reason']);
    }

    public function test_a_block_that_cannot_be_located_exactly_once_in_the_page_is_rejected(): void
    {
        [, $blocks] = $this->page(['Gjør en risikovurdering.', 'Gjør en risikovurdering.']);
        $markdown = "Gjør en risikovurdering.\n\nGjør en risikovurdering.";

        $result = (new EnterpriseWikiInboundLinkInserter)->insert($markdown, $blocks, 'b1', 'risikovurdering', 'risiko', self::INTENT);

        $this->assertSame(EnterpriseWikiInboundLinkInserter::REJECT_BLOCK_NOT_LOCATABLE, $result['reason']);
    }

    /**
     * @param  list<string>  $paragraphs
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function page(array $paragraphs): array
    {
        $blocks = [];

        foreach ($paragraphs as $index => $paragraph) {
            $blocks[] = [
                'block_key' => 'b'.($index + 1),
                'position' => $index,
                'markdown' => $paragraph,
                'content_origin' => 'source_based',
                'source_type' => 'enterprise_wiki_document',
                'source_id' => 7,
                'link_intents' => [],
            ];
        }

        return [implode("\n\n", $paragraphs), $blocks];
    }
}
