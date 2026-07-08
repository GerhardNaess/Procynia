<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiArticleAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WikiArticleAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_generate_article_returns_markdown_on_valid_response(): void
    {
        $expectedMarkdown = "## Kompetanse\n\nVi er sertifisert og kompetente.";
        $client = $this->clientWithOutputText(['article' => ['markdown' => $expectedMarkdown]]);

        $result = $client->generateArticle('Test Page', [
            ['text' => 'Vi er sertifisert.', 'confidence' => 'high', 'excerpt' => 'Sertifisert siden 2015.', 'source' => 'kompetanse.docx'],
        ], 'no');

        $this->assertSame($expectedMarkdown, $result);
    }

    public function test_generate_article_throws_when_ai_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $client = app(WikiArticleAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not enabled/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_throws_on_empty_text_response(): void
    {
        $client = $this->clientWithRawResponse(['output_text' => '', 'output' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty text response/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_throws_on_invalid_json(): void
    {
        $client = $this->clientWithRawResponse(['output_text' => 'dette er ikke json']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_throws_when_article_markdown_is_empty(): void
    {
        $client = $this->clientWithOutputText(['article' => ['markdown' => '']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/generated article was empty/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_throws_when_article_markdown_is_whitespace_only(): void
    {
        $client = $this->clientWithOutputText(['article' => ['markdown' => "   \n  "]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/generated article was empty/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_rejects_output_with_html_comments(): void
    {
        $badMarkdown = "# Tittel\n\nInnhold.\n<!-- wiki-ingest-run:42 -->\nMer innhold.";
        $client = $this->clientWithOutputText(['article' => ['markdown' => $badMarkdown]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTML comments/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_rejects_output_with_multiple_kilde_lines(): void
    {
        $badMarkdown = "# Tittel\n\nInnhold.\n\nKilde: kompetanse.docx\n\nKilde: annet.docx\n\nMer innhold.";
        $client = $this->clientWithOutputText(['article' => ['markdown' => $badMarkdown]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/source citation lines/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_rejects_output_with_many_blockquote_lines(): void
    {
        $badMarkdown = "# Tittel\n\nInnhold.\n\n> Utdrag en\n> Utdrag to\n> Utdrag tre\n\nMer innhold.";
        $client = $this->clientWithOutputText(['article' => ['markdown' => $badMarkdown]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/blockquote lines/');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_generate_article_accepts_single_blockquote_line(): void
    {
        $goodMarkdown = "# Tittel\n\n> En enkelt sitat er OK.\n\nHovedinnhold her.";
        $client = $this->clientWithOutputText(['article' => ['markdown' => $goodMarkdown]]);

        $result = $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');

        $this->assertSame($goodMarkdown, $result);
    }

    public function test_api_exception_propagates_from_open_ai_client(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andThrow(new RuntimeException('API error'));
        $client = app(WikiArticleAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error');

        $client->generateArticle('Test Page', [['text' => 'Krav.', 'confidence' => 'high', 'excerpt' => '', 'source' => '']], 'no');
    }

    public function test_is_available_returns_false_by_default(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $this->assertFalse(WikiArticleAiClient::isAvailable());
    }

    public function test_is_available_returns_true_when_enabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->assertTrue(WikiArticleAiClient::isAvailable());
    }

    public function test_no_real_network_calls_are_made(): void
    {
        $expectedMarkdown = "## Fakta\n\nTestinnhold.";
        $client = $this->clientWithOutputText(['article' => ['markdown' => $expectedMarkdown]]);

        $result = $client->generateArticle('Side', [
            ['text' => 'Vi leverer kvalitet.', 'confidence' => 'high', 'excerpt' => 'Kildesetning.', 'source' => 'doc.docx'],
        ], 'en');

        $this->assertSame($expectedMarkdown, $result);
    }

    private function clientWithOutputText(array $body): WikiArticleAiClient
    {
        return $this->clientWithRawResponse(['output_text' => json_encode($body)]);
    }

    private function clientWithRawResponse(array $responseBody): WikiArticleAiClient
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andReturn($responseBody);

        return app(WikiArticleAiClient::class);
    }
}
