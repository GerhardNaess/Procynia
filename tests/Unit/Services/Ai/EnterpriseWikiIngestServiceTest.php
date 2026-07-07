<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use InvalidArgumentException;
use Tests\TestCase;

class EnterpriseWikiIngestServiceTest extends TestCase
{
    private EnterpriseWikiIngestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnterpriseWikiIngestService();
    }

    // --- Source hash ---

    public function test_source_hash_is_deterministic_for_same_inputs(): void
    {
        $hash1 = $this->service->computeSourceHash(42, 'abc123filecontentsha256');
        $hash2 = $this->service->computeSourceHash(42, 'abc123filecontentsha256');

        $this->assertSame($hash1, $hash2);
    }

    public function test_source_hash_changes_when_version_id_changes(): void
    {
        $hash1 = $this->service->computeSourceHash(42, 'abc123');
        $hash2 = $this->service->computeSourceHash(43, 'abc123');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_source_hash_changes_when_file_hash_changes(): void
    {
        $hash1 = $this->service->computeSourceHash(42, 'abc123');
        $hash2 = $this->service->computeSourceHash(42, 'xyz999differentfile');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_source_hash_is_64_character_hex_string(): void
    {
        $hash = $this->service->computeSourceHash(1, 'somehash');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_source_hash_encodes_version_id_so_id_1_and_11_differ(): void
    {
        $hash1 = $this->service->computeSourceHash(1, 'hash');
        $hash11 = $this->service->computeSourceHash(11, 'hash');

        $this->assertNotSame($hash1, $hash11);
    }

    // --- Text size validation ---

    public function test_extracted_text_within_limit_passes_without_exception(): void
    {
        $text = str_repeat('a', 100);

        $this->service->validateExtractedTextSize($text);

        $this->assertTrue(true);
    }

    public function test_extracted_text_at_exact_limit_passes_without_exception(): void
    {
        $text = str_repeat('a', EnterpriseWikiIngestService::MAX_EXTRACTED_TEXT_CHARS);

        $this->service->validateExtractedTextSize($text);

        $this->assertTrue(true);
    }

    public function test_extracted_text_exceeding_limit_throws_invalid_argument_exception(): void
    {
        $text = str_repeat('a', EnterpriseWikiIngestService::MAX_EXTRACTED_TEXT_CHARS + 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/500[\s_]?000/');

        $this->service->validateExtractedTextSize($text);
    }

    public function test_empty_text_passes_validation(): void
    {
        $this->service->validateExtractedTextSize('');

        $this->assertTrue(true);
    }

    public function test_max_extracted_text_chars_constant_is_500000(): void
    {
        $this->assertSame(500_000, EnterpriseWikiIngestService::MAX_EXTRACTED_TEXT_CHARS);
    }
}
