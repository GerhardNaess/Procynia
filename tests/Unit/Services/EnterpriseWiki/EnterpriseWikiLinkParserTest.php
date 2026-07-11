<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use Tests\TestCase;

class EnterpriseWikiLinkParserTest extends TestCase
{
    private function parser(): EnterpriseWikiLinkParser
    {
        return new EnterpriseWikiLinkParser();
    }

    public function test_parses_slug_only_link(): void
    {
        $result = $this->parser()->parse('See [[business-case]] for details.');

        $this->assertCount(1, $result);
        $this->assertSame('business-case', $result[0]['target_slug']);
        $this->assertSame('business-case', $result[0]['anchor_text']);
        $this->assertSame('[[business-case]]', $result[0]['original_markup']);
        $this->assertSame(0, $result[0]['occurrence_order']);
    }

    public function test_parses_slug_with_anchor_text(): void
    {
        $result = $this->parser()->parse('Contact the [[prosjekteier|prosjekteieren]] directly.');

        $this->assertCount(1, $result);
        $this->assertSame('prosjekteier', $result[0]['target_slug']);
        $this->assertSame('prosjekteieren', $result[0]['anchor_text']);
        $this->assertSame('[[prosjekteier|prosjekteieren]]', $result[0]['original_markup']);
    }

    public function test_parses_multiple_wikilinks_in_same_text(): void
    {
        $markdown = 'See [[business-case]] and [[prosjekteier|prosjekteieren]] for more.';

        $result = $this->parser()->parse($markdown);

        $this->assertCount(2, $result);
        $this->assertSame('business-case', $result[0]['target_slug']);
        $this->assertSame(0, $result[0]['occurrence_order']);
        $this->assertSame('prosjekteier', $result[1]['target_slug']);
        $this->assertSame(1, $result[1]['occurrence_order']);
    }

    public function test_same_target_multiple_times_returns_multiple_occurrences(): void
    {
        $markdown = '[[business-case]] is discussed here, and again: [[business-case]].';

        $result = $this->parser()->parse($markdown);

        $this->assertCount(2, $result);
        $this->assertSame('business-case', $result[0]['target_slug']);
        $this->assertSame('business-case', $result[1]['target_slug']);
        $this->assertSame(0, $result[0]['occurrence_order']);
        $this->assertSame(1, $result[1]['occurrence_order']);
    }

    public function test_empty_slug_is_rejected(): void
    {
        $this->assertSame([], $this->parser()->parse('This is invalid: [[]] and also [[   ]].'));
    }

    public function test_empty_anchor_is_rejected(): void
    {
        $this->assertSame([], $this->parser()->parse('This is invalid: [[business-case|]] and [[business-case|   ]].'));
    }

    public function test_malformed_markup_is_not_treated_as_a_valid_link(): void
    {
        $markdown = 'Unclosed [[business-case and [single-bracket] are not links.';

        $this->assertSame([], $this->parser()->parse($markdown));
    }

    public function test_ordinary_markdown_link_is_not_affected(): void
    {
        $markdown = 'See [the business case](https://example.com/business-case) for details.';

        $this->assertSame([], $this->parser()->parse($markdown));
    }

    public function test_text_without_wikilinks_returns_empty_result(): void
    {
        $this->assertSame([], $this->parser()->parse('Plain text with no links at all.'));
    }

    public function test_whitespace_around_slug_and_anchor_is_trimmed(): void
    {
        $result = $this->parser()->parse('[[  business-case  |  the business case  ]]');

        $this->assertCount(1, $result);
        $this->assertSame('business-case', $result[0]['target_slug']);
        $this->assertSame('the business case', $result[0]['anchor_text']);
    }

    public function test_mixed_valid_and_malformed_links_only_returns_valid_ones(): void
    {
        $markdown = 'Valid [[business-case]], invalid [[]], unclosed [[oops and valid [[prosjekteier|Owner]].';

        $result = $this->parser()->parse($markdown);

        $this->assertCount(2, $result);
        $this->assertSame('business-case', $result[0]['target_slug']);
        $this->assertSame('prosjekteier', $result[1]['target_slug']);
    }
}
