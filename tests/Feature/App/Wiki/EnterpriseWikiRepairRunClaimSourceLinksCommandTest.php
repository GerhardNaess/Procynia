<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Run-38 follow-up: repairing claims whose content_block_key is missing/stale and restoring the
 * source references their correctly-matched block declares — never guessing when more than one
 * block matches, never touching a claim outside the explicit --claim-ids scope, never calling AI.
 */
class EnterpriseWikiRepairRunClaimSourceLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_the_unique_match_without_changing_data(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
        ])
            ->expectsOutputToContain('Read-only analysis')
            ->expectsOutputToContain('Relinked:                  1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertNull($fresh->content_block_key);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
    }

    public function test_apply_sets_the_block_key_and_restores_source_references(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Applied repair')
            ->expectsOutputToContain('Relinked:                  1')
            ->expectsOutputToContain('Source references created: 1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame('block-uniq', $fresh->content_block_key);

        $reference = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->first();
        $this->assertNotNull($reference);
        $this->assertSame('paragraph-9', $reference->source_element_key);
        $this->assertSame('Critical incidents shall be responded to within 30 minutes.', $reference->excerpt);
    }

    public function test_repair_restores_candidates_but_never_marks_a_claim_supported_itself(): void
    {
        // Mirrors real run-38 claim 4048: relinking restores CANDIDATE source references so a
        // separate verification step can judge the claim — it must never, on its own, flip
        // content_origin. A genuinely misattributed claim stays rejected until (and unless) actual
        // verification says otherwise; this command has no opinion on that question at all.
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame('block-uniq', $fresh->content_block_key);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $fresh->content_origin);
    }

    public function test_ambiguous_match_is_reported_and_not_repaired(): void
    {
        [$run, , , , $ambiguousClaim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $ambiguousClaim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Ambiguous (not repaired):  1')
            ->assertExitCode(0);

        $fresh = $ambiguousClaim->fresh();
        $this->assertNull($fresh->content_block_key);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $ambiguousClaim->id)->count());
    }

    public function test_no_matching_block_is_reported_and_not_repaired(): void
    {
        [$run, $page, $version] = $this->createRunWithBlocks();

        $unmatchedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Dette finnes ingen steder i blokkene.',
            'page_excerpt' => 'Dette finnes ingen steder i blokkene.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 3,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $unmatchedClaim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('No match (not repaired):   1')
            ->assertExitCode(0);

        $this->assertNull($unmatchedClaim->fresh()->content_block_key);
    }

    public function test_repair_never_touches_a_claim_outside_the_explicit_scope(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $protectedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $claim->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $claim->enterprise_wiki_page_version_id,
            'claim_text' => 'Critical incidents shall be responded to within 30 minutes.',
            'page_excerpt' => 'Critical incidents shall be responded to within 30 minutes.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'genuine_misattribution',
            'position_order' => 4,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        // Only $claim is in scope — $protectedClaim has an identical anchor and would match the
        // same unique block, but must never be touched because its ID was never requested.
        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $fresh = $protectedClaim->fresh();
        $this->assertNull($fresh->content_block_key);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $fresh->content_origin);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $protectedClaim->id)->count());
    }

    public function test_repair_can_run_twice_without_creating_duplicate_references(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Unchanged (already ok):    1')
            ->expectsOutputToContain('Relinked:                  0')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    public function test_discovers_a_strongly_matching_source_element_when_the_block_has_none(): void
    {
        // The block itself declares no source elements (a synthesis block, like real run-38's
        // best-practice-styled blocks) — but a claim anchored to it strongly overlaps with a
        // specific, DIFFERENT known paragraph elsewhere in the document's catalog.
        [$run, , , $claim] = $this->createRunWithDiscoveryScenario();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Relinked:                  1')
            ->assertExitCode(0);

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->first();

        $this->assertNotNull($reference);
        $this->assertSame('paragraph-strong', $reference->source_element_key);

        // The block's own provenance is updated too, so any other claim sharing it benefits.
        $blockKey = $claim->fresh()->content_block_key;
        $version = $claim->version()->first();
        $block = collect($version->fresh()->content_blocks_json)->firstWhere('block_key', $blockKey);
        $this->assertContains('paragraph-strong', array_column($block['source_elements'], 'source_element_key'));
    }

    public function test_ambiguous_source_candidate_is_not_added_automatically(): void
    {
        // Two different known paragraphs score equally well against the claim's clause — neither
        // is added, since picking one over the other would be a guess.
        [$run, , , , , $tiedClaim] = $this->createRunWithDiscoveryScenario();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $tiedClaim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            0,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $tiedClaim->id)->count(),
        );
    }

    public function test_repair_converges_in_a_single_pass_for_two_claims_sharing_a_block(): void
    {
        // Two different claims are both anchored to the SAME no-source-elements block, and each
        // one's own clause strongly matches a DIFFERENT known paragraph. A single --apply must
        // give BOTH claims BOTH discovered elements (not just the one each found on its own) —
        // otherwise the same run would need to be applied twice to converge.
        [$run, , , , $siblingA, , , $siblingB] = $this->createRunWithDiscoveryScenario();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => $siblingA->id.','.$siblingB->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $siblingAKeys = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $siblingA->id)->pluck('source_element_key');
        $siblingBKeys = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $siblingB->id)->pluck('source_element_key');

        $this->assertContains('paragraph-sibling-1', $siblingAKeys);
        $this->assertContains('paragraph-sibling-2', $siblingAKeys);
        $this->assertContains('paragraph-sibling-1', $siblingBKeys);
        $this->assertContains('paragraph-sibling-2', $siblingBKeys);

        // Re-running with the identical input must report no further changes at all.
        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => $siblingA->id.','.$siblingB->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Relinked:                  0')
            ->expectsOutputToContain('Unchanged (already ok):    2')
            ->assertExitCode(0);
    }

    public function test_command_fails_without_run_id(): void
    {
        $this->artisan('wiki:repair-run-claim-source-links', ['--claim-ids' => '1'])
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_without_claim_ids(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createRunWithBlocks($customer);

        $this->artisan('wiki:repair-run-claim-source-links', ['--run-id' => $run->id])
            ->expectsOutputToContain('--claim-ids is required')
            ->assertExitCode(1);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiClaim, 4: EnterpriseWikiClaim}
     */
    private function createRunWithBlocks(?Customer $customer = null): array
    {
        $customer ??= $this->createCustomer();

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Critical incidents shall be responded to within 30 minutes.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'repair-link-page-'.Str::lower(Str::random(8)),
            'title' => 'Repair Link Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $uniqueMarkdown = 'Critical incidents shall be responded to within 30 minutes.';
        $duplicateAnchorText = 'Kunden eier prosessene videre etter innføring.';

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Repair Link Page\n\n{$uniqueMarkdown}\n\n{$duplicateAnchorText}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-uniq',
                    'position' => 0,
                    'markdown' => $uniqueMarkdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_element_key' => 'paragraph-9',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => $uniqueMarkdown,
                        'source_label' => 'source.pdf',
                        'source_hash' => $document->file_hash_sha256,
                        'page_reference' => 'Avsnitt 9',
                    ]],
                ],
                [
                    'block_key' => 'block-dup-a',
                    'position' => 1,
                    'markdown' => $duplicateAnchorText.' Videre detaljer for blokk A.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_element_key' => 'paragraph-10',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => $duplicateAnchorText,
                        'source_label' => 'source.pdf',
                        'source_hash' => $document->file_hash_sha256,
                        'page_reference' => 'Avsnitt 10',
                    ]],
                ],
                [
                    'block_key' => 'block-dup-b',
                    'position' => 2,
                    'markdown' => $duplicateAnchorText.' Videre detaljer for blokk B.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_element_key' => 'paragraph-11',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => $duplicateAnchorText,
                        'source_label' => 'source.pdf',
                        'source_hash' => $document->file_hash_sha256,
                        'page_reference' => 'Avsnitt 11',
                    ]],
                ],
            ],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $uniqueMarkdown,
            'page_excerpt' => $uniqueMarkdown,
            'content_block_key' => null,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $ambiguousClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $duplicateAnchorText,
            'page_excerpt' => $duplicateAnchorText,
            'content_block_key' => null,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        return [$run, $page, $version, $claim, $ambiguousClaim];
    }

    /**
     * Fixture for the automatic strong-candidate-discovery tests. Every scenario uses its own
     * disjoint made-up vocabulary so the three independent scenarios (a clean strong match, a
     * genuine tie, two sibling claims sharing one block) can never accidentally score against
     * each other's catalog entries.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiClaim, 4: EnterpriseWikiClaim, 5: EnterpriseWikiClaim, 6: EnterpriseWikiClaim, 7: EnterpriseWikiClaim}
     */
    private function createRunWithDiscoveryScenario(?Customer $customer = null): array
    {
        $customer ??= $this->createCustomer();

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Discovery scenario document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'discovery-page-'.Str::lower(Str::random(8)),
            'title' => 'Discovery Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $discoveryAnchor = 'aaone aatwo aathree aafour aafive aasix extraone extratwo.';
        $tiedAnchor = 'bbone bbtwo bbthree bbfour bbfive bbsix eeextra1 eeextra2.';
        $siblingAAnchor = 'ffone fftwo ffthree fffour fffive ffsix siblingextraA1 siblingextraA2.';
        $siblingBAnchor = 'ggone ggtwo ggthree ggfour ggfive ggsix siblingextraB1 siblingextraB2.';

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Discovery Page\n\n{$discoveryAnchor}\n\n{$tiedAnchor}\n\n{$siblingAAnchor} {$siblingBAnchor}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-discovery',
                    'position' => 0,
                    'markdown' => $discoveryAnchor,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    'source_elements' => [],
                ],
                [
                    'block_key' => 'block-tied',
                    'position' => 1,
                    'markdown' => $tiedAnchor,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    'source_elements' => [],
                ],
                [
                    'block_key' => 'block-shared',
                    'position' => 2,
                    'markdown' => "{$siblingAAnchor} {$siblingBAnchor}",
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    'source_elements' => [],
                ],
            ],
        ]);

        $catalogSeedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Katalog-eier (ikke selv en del av reparasjonsscenarioet).',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 9,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $catalog = [
            'paragraph-strong' => 'aaone aatwo aathree aafour aafive aasix aaseven aaeight aanine.',
            'paragraph-tie-a' => 'bbone bbtwo bbthree bbfour bbfive bbsix ccunique1 ccunique2 ccunique3.',
            'paragraph-tie-b' => 'bbone bbtwo bbthree bbfour bbfive bbsix ddunique1 ddunique2 ddunique3.',
            'paragraph-sibling-1' => 'ffone fftwo ffthree fffour fffive ffsix.',
            'paragraph-sibling-2' => 'ggone ggtwo ggthree ggfour ggfive ggsix.',
        ];

        foreach ($catalog as $key => $excerpt) {
            EnterpriseWikiSourceReference::query()->create([
                'enterprise_wiki_claim_id' => $catalogSeedClaim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_element_key' => $key,
                'source_element_type' => 'paragraph',
                'source_label' => 'source.pdf',
                'excerpt' => $excerpt,
                'source_hash' => $document->file_hash_sha256,
                'page_reference' => 'Avsnitt',
            ]);
        }

        $makeClaim = function (string $anchor, int $order) use ($page, $version): EnterpriseWikiClaim {
            return EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => $anchor,
                'page_excerpt' => $anchor,
                'content_block_key' => null,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'generation_issue' => 'unsupported_generated_content',
                'position_order' => $order,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
            ]);
        };

        $discoveryClaim = $makeClaim($discoveryAnchor, 0);
        $siblingA = $makeClaim($siblingAAnchor, 1);
        $tiedClaim = $makeClaim($tiedAnchor, 2);
        $siblingB = $makeClaim($siblingBAnchor, 3);

        return [$run, $page, $version, $discoveryClaim, $siblingA, $tiedClaim, $catalogSeedClaim, $siblingB];
    }

    private function createCustomer(string $name = 'Repair Link Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
