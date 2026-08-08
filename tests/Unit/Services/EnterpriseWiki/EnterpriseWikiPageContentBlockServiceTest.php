<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimAnchorTextNormalizer;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use App\Services\EnterpriseWiki\EnterpriseWikiPageContentBlockService;
use PHPUnit\Framework\TestCase;

/**
 * Wiki run-35 live verification: an already-corrupted page title (see
 * EnterpriseWikiMaintainerDecisionPromptTest for the maintainer-decision-side fix) was fed into
 * the page-generation prompt as link-catalog context and echoed back verbatim as a wikilink's
 * anchor text, propagating the same control byte into a brand new page version's
 * content_markdown/content_blocks_json — a boundary the maintainer-decision validation does not
 * cover. buildBlocksFromStructuredResult() must reject this before it is ever persisted.
 */
class EnterpriseWikiPageContentBlockServiceTest extends TestCase
{
    private function service(): EnterpriseWikiPageContentBlockService
    {
        return new EnterpriseWikiPageContentBlockService(new EnterpriseWikiClaimAnchorTextNormalizer(new EnterpriseWikiLinkParser));
    }

    private function document(): EnterpriseWikiDocument
    {
        $document = new EnterpriseWikiDocument;
        $document->id = 1;
        $document->original_filename = 'source.docx';
        $document->file_hash_sha256 = str_pad('a', 64, '0');

        return $document;
    }

    public function test_block_with_clean_norwegian_text_is_accepted(): void
    {
        $blocks = $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => 'Rød/Gul/Grønn brukes som klassifiseringsmodell.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => 'Anbefalt praksis basert på gjennomgåtte rutiner.',
            'source_element_keys' => [],
        ]], []);

        $this->assertCount(1, $blocks);
        $this->assertSame('Rød/Gul/Grønn brukes som klassifiseringsmodell.', $blocks[0]['markdown']);
    }

    public function test_block_markdown_with_control_character_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => "Link to [[rod-gul-gronn|R\x0Fd/Gul/Gr]] appears here.",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => 'Reason.',
            'source_element_keys' => [],
        ]], []);
    }

    public function test_block_markdown_with_invalid_utf8_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid UTF-8/');

        $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => "Invalid \x80 byte sequence appears here.",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => 'Reason.',
            'source_element_keys' => [],
        ]], []);
    }

    public function test_best_practice_reason_with_control_character_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => 'Clean markdown text here.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => "Reason with \x0F control byte.",
            'source_element_keys' => [],
        ]], []);
    }

    public function test_ordinary_whitespace_and_newlines_in_markdown_are_accepted(): void
    {
        $blocks = $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => "Line one.\nLine two.\tTabbed.",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => 'Reason.',
            'source_element_keys' => [],
        ]], []);

        $this->assertCount(1, $blocks);
    }

    /**
     * Wiki run-5: a structural block (page title, section heading, "Se også" cross-reference) —
     * unlike source_based and best_practice — requires neither a best_practice_reason nor any
     * source_element_keys.
     */
    public function test_structural_block_is_accepted_without_reason_or_source_elements(): void
    {
        $blocks = $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => '# Masterdata ITIL',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
            'best_practice_reason' => null,
            'source_element_keys' => [],
        ]], []);

        $this->assertCount(1, $blocks);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL, $blocks[0]['content_origin']);
        $this->assertNull($blocks[0]['best_practice_reason']);
    }

    public function test_unsupported_content_origin_is_still_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsupported content_origin/');

        $this->service()->buildBlocksFromStructuredResult($this->document(), [[
            'markdown' => 'Some text.',
            'content_origin' => 'mixed',
            'best_practice_reason' => null,
            'source_element_keys' => [],
        ]], []);
    }
}
