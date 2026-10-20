<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Str;

/**
 * Test-only fixture for tests/e2e/wiki-runs-findings-compact.spec.js: an escalated run shaped
 * like Wiki run 963 — two «Endret normativ betydning» claim findings whose explanations quote
 * both sentences (the long text that used to make each row several hundred pixels tall), one
 * best-practice suggestion on a page with a long title, two unsupported claims outside a
 * source-based block (one open, one decided), and one resolved lint finding, so every Funn
 * filter has something to show. No AI call, no queue job.
 */
class WikiRunsFindingsCompactE2EFixture
{
    private const FILENAME = 'E2E Kompakte QA-funn.docx';

    private const SLUG_PREFIX = 'e2e-compact-findings-';

    /**
     * @return array{run_id: int, claim_ids: list<int>, page_slug: string}
     */
    public static function seed(int $customerId): array
    {
        self::cleanup($customerId);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => self::FILENAME,
            'file_path' => 'e2e-compact-findings/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Deteksjonsgap og responsgap har ulike årsaker, krever ulike tiltak og bør derfor aldri slås sammen til ett tall.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-compact-findings'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_last_error' => 'QA gate escalated: 2 claim(s) require a QA decision (normative_strengthening).',
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'started_at' => now()->subMinutes(8),
            'finished_at' => now(),
        ]);

        [$gapPage, $gapVersion] = self::createPage($customerId, $run, 'gap', 'Deteksjonsgap og responsgap', [
            ['block-0001', 'Deteksjonsgap er tiden fra første tekniske spor til første alarm.'],
            ['block-0002', 'Gapene har ulike årsaker, og skal ikke slås sammen til ett tall.'],
            ['block-0003', 'Gapene krever ulike tiltak, og de skal derfor ikke aggregeres til ett samlet tall.'],
        ]);

        $claimIds = [];

        foreach ([
            'block-0002' => 'og skal ikke slås sammen til ett tall.',
            'block-0003' => 'og de skal derfor ikke aggregeres til ett samlet tall.',
        ] as $blockKey => $claimSentence) {
            $claimIds[] = EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $gapPage->id,
                'enterprise_wiki_page_version_id' => $gapVersion->id,
                'claim_text' => 'Deteksjonsgap og responsgap '.$claimSentence,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'generation_issue' => 'unsupported_generated_content',
                'page_excerpt' => 'Gapene har ulike årsaker, '.$claimSentence,
                'content_block_key' => $blockKey,
                'position_order' => (int) substr($blockKey, -1),
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
                'review_metadata' => [
                    'classification_basis' => 'semantic_verification',
                    'verdict' => 'not_supported',
                    'reason' => 'Kilden beskriver de samme gapene, men sier at de bør ikke slås sammen.',
                    'deterministic_reason' => 'normative_strengthening',
                    'normative_check' => [
                        'status' => 'strengthened',
                        'claim_sentence' => $claimSentence,
                        'source_sentence' => 'og bør derfor aldri slås sammen til ett tall.',
                        'claim_class' => 'prohibition',
                        'source_class' => 'negative_recommendation',
                    ],
                    'decision_source' => 'verification',
                ],
            ])->id;
        }

        [$longPage, $longVersion] = self::createPage($customerId, $run, 'long', 'Etterhendelsesgjennomgang og forbedringssløyfe etter sikkerhetshendelser i driftsorganisasjonen', [
            ['block-0001', 'Etter hver alvorlig hendelse gjennomføres en etterhendelsesgjennomgang.'],
        ], [
            'block_key' => 'block-0002',
            'markdown' => 'Det anbefales å følge opp tiltakene fra gjennomgangen i et fast forbedringsforum.',
        ], [
            ['block-0003', 'Gjennomgangen skal alltid ledes av sikkerhetssjefen.'],
            ['block-0004', 'Rapporten fra gjennomgangen skal alltid godkjennes av styret.'],
        ]);

        // Unsupported claims outside a source-based block: their explanation is only in the
        // finding itself — one still open, one already decided.
        foreach ([
            ['block-0003', 'Gjennomgangen skal alltid ledes av sikkerhetssjefen.', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, 'Kilden sier ikke hvem som leder gjennomgangen.'],
            ['block-0004', 'Rapporten fra gjennomgangen skal alltid godkjennes av styret.', EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED, 'Kilden nevner ikke styrets godkjenning av rapporten.'],
        ] as [$blockKey, $text, $approvalStatus, $unsupportedParts]) {
            $claimIds[] = EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $longPage->id,
                'enterprise_wiki_page_version_id' => $longVersion->id,
                'claim_text' => $text,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'generation_issue' => 'unsupported_generated_content',
                'page_excerpt' => $text,
                'content_block_key' => $blockKey,
                'position_order' => (int) substr($blockKey, -1),
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'conflict_flag' => false,
                'approval_status' => $approvalStatus,
                'approved_at' => $approvalStatus === EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED ? now() : null,
                'verified_at' => now(),
                'review_metadata' => [
                    'classification_basis' => 'semantic_verification',
                    'verdict' => 'not_supported',
                    'unsupported_parts' => $unsupportedParts,
                    'decision_source' => 'verification',
                ],
            ])->id;
        }

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $longPage->id,
            'enterprise_wiki_page_version_id' => $longVersion->id,
            'claim_text' => 'Det anbefales å følge opp tiltakene fra gjennomgangen i et fast forbedringsforum.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'page_excerpt' => 'Det anbefales å følge opp tiltakene fra gjennomgangen i et fast forbedringsforum.',
            'content_block_key' => 'block-0002',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_reason' => 'Generell anbefaling for forbedringsarbeid utover kildedokumentet.',
            'review_metadata' => [
                'statement_kind' => 'recommendation',
                'classification_basis' => 'ai_block_content_origin',
            ],
        ]);

        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customerId,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $gapPage->id,
            'enterprise_wiki_page_version_id' => $gapVersion->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'E2E resolved finding',
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
            'detected_at' => now()->subMinutes(3),
            'resolved_at' => now(),
        ]);

        return ['run_id' => $run->id, 'claim_ids' => $claimIds, 'page_slug' => $gapPage->slug];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $sourceBlocks
     * @param  array{block_key: string, markdown: string}|null  $bestPracticeBlock
     * @param  list<array{0: string, 1: string}>  $unsupportedBlocks
     * @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion}
     */
    private static function createPage(int $customerId, EnterpriseWikiIngestRun $run, string $key, string $title, array $sourceBlocks, ?array $bestPracticeBlock = null, array $unsupportedBlocks = []): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => self::SLUG_PREFIX.$key.'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('e2e', 64, '0'),
        ]);

        $blocks = [];

        foreach ($sourceBlocks as $position => [$blockKey, $markdown]) {
            $blocks[] = [
                'block_key' => $blockKey,
                'position' => $position,
                'markdown' => $markdown,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            ];
        }

        if ($bestPracticeBlock !== null) {
            $blocks[] = $bestPracticeBlock + [
                'position' => count($blocks),
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'best_practice_reason' => 'Generell anbefaling utover kildedokumentet.',
            ];
        }

        foreach ($unsupportedBlocks as [$blockKey, $markdown]) {
            $blocks[] = [
                'block_key' => $blockKey,
                'position' => count($blocks),
                'markdown' => $markdown,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            ];
        }

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\n".implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'e2e-compact-findings-fixture',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        return [$page, $version];
    }

    public static function cleanup(int $customerId): void
    {
        $documentIds = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('original_filename', self::FILENAME)
            ->pluck('id');

        $runIds = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->whereIn('source_id', $documentIds)
            ->pluck('id');

        $pageIds = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        EnterpriseWikiLintFinding::query()->whereIn('enterprise_wiki_ingest_run_id', $runIds)->delete();
        EnterpriseWikiClaim::query()->whereIn('enterprise_wiki_page_id', $pageIds)->delete();
        EnterpriseWikiIngestRunPage::query()->whereIn('enterprise_wiki_ingest_run_id', $runIds)->delete();
        EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', $pageIds)->delete();
        EnterpriseWikiIngestRun::query()->whereIn('id', $runIds)->delete();
        EnterpriseWikiPage::query()->whereIn('id', $pageIds)->delete();
        EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->delete();
    }
}
