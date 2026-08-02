<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Str;

/**
 * Test-only fixture for the best-practice-vs-source-content UI E2E spec
 * (tests/e2e/wiki-best-practice.spec.js). Not autoloaded in production, invoked via
 * `php artisan tinker --execute=...` from the Playwright spec, mirroring
 * WikiWordTableE2EFixture/WikiWordImageE2EFixture.
 *
 * Seeds a page whose content_blocks_json has one source_based block and one best_practice
 * block, so the reader-facing distinction (Show.jsx's "Beste praksis" box) can be verified in a
 * real browser without any real OpenAI call.
 */
class WikiBestPracticeE2EFixture
{
    private const PAGE_SLUG = 'e2e-best-practice-verifisering';

    public static function seed(int $customerId): string
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => 'Incident Management Illustration (E2E).docx',
            'file_path' => 'e2e-best-practice/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => self::PAGE_SLUG,
            'title' => 'Best Practice E2E',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $sourceText = 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.';
        $bestPracticeText = 'Det anbefales å definere tydelige roller, eskaleringspunkter og responstider.';

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Best Practice E2E\n\n{$sourceText}\n\n{$bestPracticeText}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-0001',
                    'position' => 0,
                    'markdown' => $sourceText,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_id' => $document->id,
                    'source_label' => $document->original_filename,
                    'page_reference' => 'Løpende tekst',
                ],
                [
                    'block_key' => 'block-0002',
                    'position' => 1,
                    'markdown' => $bestPracticeText,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    'best_practice_reason' => 'Generell ITSM-anbefaling utover kildedokumentet.',
                ],
            ],
            'generated_by_model' => 'e2e-best-practice-fixture',
        ]);

        $sourceClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $sourceText,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'page_excerpt' => $sourceText,
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $sourceClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_label' => $document->original_filename,
            'excerpt' => $sourceText,
            'source_hash' => $document->file_hash_sha256,
            'page_reference' => 'Avsnitt 1',
        ]);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $bestPracticeText,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'page_excerpt' => $bestPracticeText,
            'content_block_key' => 'block-0002',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_metadata' => [
                'statement_kind' => 'recommendation',
                'classification_basis' => 'ai_block_content_origin',
                'suggested_placement' => 'block-0002',
                'visible_wiki_link_recommendation' => 'not_needed',
            ],
        ]);

        return self::PAGE_SLUG;
    }

    public static function cleanup(int $customerId): void
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', self::PAGE_SLUG)
            ->get();

        foreach ($pages as $page) {
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            $page->delete();
        }

        EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('file_path', 'like', 'e2e-best-practice/%')
            ->delete();
    }
}
