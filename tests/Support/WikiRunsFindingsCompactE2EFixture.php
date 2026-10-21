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
     * A failed run stored exactly the way Wiki run 26 is: the same error_message, character for
     * character, and the same planned topics — no failed_phase and no page pivot rows, as on that
     * run. Same filename, so cleanup() removes it.
     *
     * @return array{run_id: int, error_message: string}
     */
    public static function seedStoppedRun(int $customerId): array
    {
        self::cleanup($customerId);

        $title = 'Vurdering av betalingsbetingelser og økonomisk informasjon';
        $topics = ['Formål og tidspunkt for aktiviteten', 'Ansvar', 'Fremgangsmåte og vurderingskriterier', 'Dokumentasjon og resultat', 'Relatert prosesskontekst'];
        $errorMessage = '1 of 2 initial page(s) failed to generate: '.$title.'. Details — '.$title.' (article): '
            .'[EnterpriseWikiPlannedSectionEvidenceMissingException] Run [26] page [110] (article): planned_section_no_evidence before generation: '
            .implode(', ', $topics).'.';

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => self::FILENAME,
            'file_path' => 'e2e-compact-findings/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Betalingsbetingelser og økonomisk informasjon.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-stopped-run'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_json' => ['source_article' => [
                'title' => $title,
                'owned_topics' => array_map(static fn (string $topic): array => ['topic' => $topic], $topics),
            ]],
            'error_message' => $errorMessage,
            'started_at' => now()->subMinutes(6),
            'finished_at' => now(),
        ]);

        return ['run_id' => $run->id, 'error_message' => $errorMessage];
    }

    /**
     * «Fjern fra oversikten»: one run per case on one document — failed the way run 26 is stored,
     * completed with a page it created, cancelled, still generating, escalated with follow-up open,
     * and escalated with its QA follow-up complete. Same filename and slug prefix, so cleanup()
     * removes all of it.
     *
     * @return array<string, int>
     */
    public static function seedDismissal(int $customerId): array
    {
        $stopped = self::seedStoppedRun($customerId);
        $failed = EnterpriseWikiIngestRun::query()->findOrFail($stopped['run_id']);

        $make = static fn (string $status, array $attributes = []): EnterpriseWikiIngestRun => EnterpriseWikiIngestRun::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $failed->source_id,
            'source_hash' => hash('sha256', 'e2e-dismissal-'.$status),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(15),
        ], $attributes));

        $completed = $make(EnterpriseWikiIngestRun::STATUS_COMPLETED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED]);
        [$completedPage] = self::createPage($customerId, $completed, 'dismiss-completed', 'Fullført side for opprydding', [
            ['block-0001', 'Leverandører betales innen 30 dager.'],
        ]);

        $cancelled = $make(EnterpriseWikiIngestRun::STATUS_CANCELLED);
        $active = $make(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, ['finished_at' => null, 'started_at' => now()]);
        $escalatedOpen = $make(EnterpriseWikiIngestRun::STATUS_ESCALATED, [
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_result' => ['quality_findings' => ['source_section_uncovered']],
        ]);

        $escalatedDone = $make(EnterpriseWikiIngestRun::STATUS_ESCALATED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);
        [$donePage, $doneVersion] = self::createPage($customerId, $escalatedDone, 'dismiss-escalated', 'Eskalert side med fullført oppfølging', [
            ['block-0001', 'Avvik skal registreres samme dag.'],
        ]);
        $doneClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $donePage->id,
            'enterprise_wiki_page_version_id' => $doneVersion->id,
            'claim_text' => 'Avvik skal registreres samme dag.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_at' => now(),
            'verified_at' => now(),
            'review_metadata' => ['classification_basis' => 'semantic_verification', 'verdict' => 'not_supported', 'deterministic_reason' => 'normative_strengthening'],
        ]);
        $escalatedDone->update(['qa_result' => [
            'quality_findings' => ['normative_strengthening'],
            'checks' => ['normative_fidelity' => ['open_strengthened_claim_ids' => [$doneClaim->id], 'ambiguous_claim_ids' => []]],
        ]]);

        // Escalated with a page that failed to generate — shaped like run 963.
        $escalatedFailedPage = $make(EnterpriseWikiIngestRun::STATUS_ESCALATED, ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);
        self::createPage($customerId, $escalatedFailedPage, 'dismiss-failed-page', 'Side som ikke ble generert', [
            ['block-0001', 'Tekst.'],
        ]);
        EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $escalatedFailedPage->id)->update([
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
            'generation_error' => 'unknown',
        ]);

        // Recorded as completed, but never ran: no start, no end, no page plan, no pages (runs #27–#29).
        $neverRan = $make(EnterpriseWikiIngestRun::STATUS_COMPLETED, [
            'maintainer_decision_status' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        return [
            'never_ran' => $neverRan->id,
            'escalated_failed_page' => $escalatedFailedPage->id,
            'failed' => $failed->id,
            'completed' => $completed->id,
            'completed_page' => $completedPage->id,
            'cancelled' => $cancelled->id,
            'active' => $active->id,
            'escalated_open' => $escalatedOpen->id,
            'escalated_done' => $escalatedDone->id,
        ];
    }

    /**
     * Scenario H: a run escalated on one normative finding that is the last open one, with the QA
     * result such a run records (quality_findings + the claim id its normative check found). Same
     * filename and slug prefix as seed(), so cleanup() removes it too.
     *
     * @return array{run_id: int, claim_id: int, page_slug: string}
     */
    public static function seedFollowUp(int $customerId): array
    {
        self::cleanup($customerId);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => self::FILENAME,
            'file_path' => 'e2e-compact-findings/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Avvik bør registreres samme dag som de oppdages.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-qa-follow-up'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_last_error' => 'Quality finding(s) need a decision: normative_strengthening.',
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'started_at' => now()->subMinutes(8),
            'finished_at' => now(),
        ]);

        [$page, $version] = self::createPage($customerId, $run, 'follow-up', 'Avviksregistrering', [
            ['block-0001', 'Avvik skal registreres samme dag som de oppdages.'],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Avvik skal registreres samme dag som de oppdages.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'page_excerpt' => 'Avvik skal registreres samme dag som de oppdages.',
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'reason' => 'Kilden sier «bør», teksten sier «skal».',
                'deterministic_reason' => 'normative_strengthening',
                'decision_source' => 'verification',
            ],
        ]);

        $run->update(['qa_result' => [
            'quality_findings' => ['normative_strengthening'],
            'checks' => ['normative_fidelity' => ['open_strengthened_claim_ids' => [$claim->id], 'ambiguous_claim_ids' => []]],
        ]]);

        return ['run_id' => $run->id, 'claim_id' => $claim->id, 'page_slug' => $page->slug];
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
