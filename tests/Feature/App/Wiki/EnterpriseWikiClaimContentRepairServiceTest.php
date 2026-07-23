<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Controlled, bounded block-content repair (Del 5) for runs stopped with
 * qa_status=repair_required. All AI calls are mocked — no external model calls.
 */
class EnterpriseWikiClaimContentRepairServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_no_repairables_returns_not_attempted(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->createVersion($article, [], 'Clean content.');
        $this->attachPageToRun($run, $article, $version);

        $this->mock(WikiSemanticReviserAiClient::class)->shouldReceive('revise')->never();

        $result = $this->service()->attempt($run);

        $this->assertFalse($result['attempted']);
        $this->assertSame('no_repairables', $result['reason']);
    }

    public function test_max_attempts_reached_skips_without_calling_ai(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $run->update(['claim_content_repair_attempt_count' => EnterpriseWikiIngestRun::MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS]);

        $this->mock(WikiSemanticReviserAiClient::class)->shouldReceive('revise')->never();

        $result = $this->service()->attempt($run);

        $this->assertFalse($result['attempted']);
        $this->assertSame('max_attempts_reached', $result['reason']);
    }

    public function test_claim_without_block_anchor_is_left_unrepaired(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->createVersion($article, [], 'Some content.');
        $this->attachPageToRun($run, $article, $version);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Unsupported claim with no block anchor.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'content_block_key' => null,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->mock(WikiSemanticReviserAiClient::class)->shouldReceive('revise')->never();

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $result = $this->service()->attempt($run);

        $this->assertTrue($result['attempted']);
        $this->assertSame('unrepairable_blocks_present', $result['reason']);
        $this->assertContains($article->id, $result['unrepairable_page_ids']);
        $this->assertSame([], $result['repaired_page_ids']);
        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_reviser_failure_leaves_page_unrepaired(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->createVersion($article, [$this->block('block-0001', 'Bad text.', $document)], 'Bad text.');
        $this->attachPageToRun($run, $article, $version);
        $this->createBadClaim($article, $version, 'block-0001');

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->andThrow(new \RuntimeException('OpenAI timeout.'));

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $result = $this->service()->attempt($run);

        $this->assertContains($article->id, $result['unrepairable_page_ids']);
        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());

        $version->refresh();
        $this->assertTrue((bool) $version->is_current);
    }

    public function test_repairs_block_creates_new_version_and_flow_reaches_passed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);

        // Article: one bad block/claim to repair.
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->createVersion($article, [$this->block('block-0001', 'Bad text.', $document)], 'Bad text.');
        $articlePivot = $this->attachPageToRun($run, $article, $articleVersion);
        $this->createBadClaim($article, $articleVersion, 'block-0001');

        // Summary: already clean and already extracted — must not be touched by re-extraction.
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $summaryVersion = $this->createVersion($summary, [$this->block('block-0001', 'Clean summary text.', $document)], 'Clean summary text.');
        $this->attachPageToRun($run, $summary, $summaryVersion, extracted: true);
        $summaryClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $summary->id,
            'enterprise_wiki_page_version_id' => $summaryVersion->id,
            'claim_text' => 'Clean summary text.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $summaryClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Clean summary text.',
        ]);

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->withArgs(function (string $source, string $existingContent) use ($document): bool {
                return $source === $document->extracted_text && $existingContent === 'Bad text.';
            })
            ->andReturn('Revised text confirmed by the source.');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn([
                'claims' => [[
                    'text' => 'Revised text confirmed by the source.',
                    'confidence' => 'high',
                    'excerpt' => 'Revised text confirmed by the source.',
                    'conflict_note' => null,
                ]],
            ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult());

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $result = $this->service()->attempt($run);

        $this->assertTrue($result['attempted']);
        $this->assertContains($article->id, $result['repaired_page_ids']);
        $this->assertSame($versionCountBefore + 1, EnterpriseWikiPageVersion::query()->count());

        // Old version preserved, no longer current.
        $articleVersion->refresh();
        $this->assertFalse((bool) $articleVersion->is_current);
        $this->assertSame('Bad text.', $articleVersion->content_markdown);

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->firstOrFail();
        $this->assertSame('Revised text confirmed by the source.', $newVersion->content_markdown);
        $this->assertStringContainsString('claim-content-repair', $newVersion->generated_by_model);

        $articlePivot->refresh();
        $this->assertSame($newVersion->id, $articlePivot->generated_page_version_id);

        $run->refresh();
        $this->assertSame(1, $run->claim_content_repair_attempt_count);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertSame(
            0,
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $newVersion->id)
                ->whereIn('content_origin', [
                    EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                ])
                ->count(),
        );
    }

    public function test_manual_mixed_block_edit_stages_extracts_verifies_promotes_and_preserves_unchanged_claims(): void
    {
        $fixture = $this->createManualMixedBlockEditFixture();
        $newMarkdown = 'Kunden bidrar til dokumentert kontroll etter revisjonen.';

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->withArgs(function (string $pageTitle, string $pageType, string $blockMarkdown, string $contentBlockKey, array $sourceElements) use ($fixture, $newMarkdown): bool {
                $this->assertSame($fixture['page']->title, $pageTitle);
                $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $pageType);
                $this->assertSame($newMarkdown, $blockMarkdown);
                $this->assertSame('block-0002', $contentBlockKey);
                $this->assertSame(['source-edited-1'], array_column($sourceElements, 'key'));

                return true;
            })
            ->andReturn(['claims' => [[
                'text' => $newMarkdown,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'excerpt' => 'Kunden bidrar til dokumentert kontroll',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_element_keys' => ['source-edited-1'],
                'best_practice_reason' => null,
                'conflict_note' => null,
            ]]]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->withArgs(function (string $claimText, array $sourceElements, string $fallbackSourceText, string $languageCode, ?string $blockMarkdown, string $documentLabel): bool {
                $this->assertSame('Kunden bidrar til dokumentert kontroll etter revisjonen.', $claimText);
                $this->assertSame(['source-edited-1'], array_column($sourceElements, 'key'));
                $this->assertSame('', $fallbackSourceText);
                $this->assertSame('no', $languageCode);
                $this->assertNull($blockMarkdown);
                $this->assertNotSame('', $documentLabel);

                return true;
            })
            ->andReturn($this->verificationResult(
                supportingSourceElementKeys: ['source-edited-1'],
                reason: 'Påstanden er støttet av kildereferansen.',
            ));

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $canonicalFactsBefore = EnterpriseWikiCanonicalFact::query()->count();
        $result = $this->service()->applyManualMixedBlockEdit(
            $fixture['run']->fresh(),
            $fixture['page']->fresh(),
            $fixture['version']->fresh(),
            $fixture['reviewClaim']->fresh(),
            'block-0002',
            $newMarkdown,
            $fixture['actor']->fresh(),
        );

        $this->assertSame(['block-0002'], $result['changed_content_block_keys']);
        $this->assertSame(1, $result['extracted_claims']);
        $this->assertSame(1, $result['verified_claims']);
        $this->assertCount(2, $result['copied_claim_ids']);
        $this->assertCount(1, $result['new_claim_ids']);
        $this->assertCount(1, $result['canonical_fact_ids']);
        $this->assertSame($versionCountBefore + 1, EnterpriseWikiPageVersion::query()->count());

        $fixture['version']->refresh();
        $this->assertFalse((bool) $fixture['version']->is_current);
        $this->assertSame('Kunden sikrer dokumentert kontroll.', $fixture['blocks'][1]['markdown']);

        $newVersion = EnterpriseWikiPageVersion::query()->findOrFail($result['page_version_id']);
        $this->assertTrue((bool) $newVersion->is_current);
        $this->assertFalse((bool) $newVersion->is_staged);
        $this->assertNull($newVersion->generated_by_model);
        $this->assertSame($fixture['actor']->id, $newVersion->created_by_user_id);
        $this->assertSame($fixture['version']->version_number + 1, $newVersion->version_number);
        $this->assertSame(
            implode("\n\n", [
                'Uendret kildebasert tekst.',
                $newMarkdown,
                'Kunden beskriver gammel risiko.',
            ]),
            $newVersion->content_markdown,
        );
        $this->assertSame('mixed', $newVersion->content_blocks_json[1]['content_origin']);
        $this->assertSame($newMarkdown, $newVersion->content_blocks_json[1]['markdown']);
        $this->assertSame($fixture['blocks'][0], $newVersion->content_blocks_json[0]);

        $fixture['pivot']->refresh();
        $this->assertSame($newVersion->id, $fixture['pivot']->generated_page_version_id);
        $this->assertNotNull($fixture['pivot']->claims_extracted_at);
        $this->assertNull($fixture['pivot']->claims_claimed_at);
        $this->assertNull($fixture['pivot']->claims_claim_token);

        $copiedClaim = EnterpriseWikiClaim::query()->findOrFail($result['copied_claim_ids'][0]);
        $this->assertNotSame($fixture['unchangedClaim']->id, $copiedClaim->id);
        $this->assertSame($newVersion->id, $copiedClaim->enterprise_wiki_page_version_id);
        $this->assertSame('block-0001', $copiedClaim->content_block_key);
        $this->assertSame($fixture['unchangedClaim']->content_origin, $copiedClaim->content_origin);
        $this->assertSame($fixture['unchangedClaim']->approval_status, $copiedClaim->approval_status);
        $this->assertSame($fixture['unchangedClaim']->canonical_fact_id, $copiedClaim->canonical_fact_id);
        $this->assertNotNull($copiedClaim->verified_at);
        $this->assertNull($copiedClaim->verification_claimed_at);
        $this->assertNull($copiedClaim->verification_claim_token);
        $this->assertSame(0, $copiedClaim->decisions()->count());

        $copiedReference = $copiedClaim->sourceReferences()->sole();
        $this->assertNotSame($fixture['unchangedReference']->id, $copiedReference->id);
        $this->assertSame('source-unchanged-1', $copiedReference->source_element_key);

        $copiedThirdBlockClaim = EnterpriseWikiClaim::query()
            ->whereIn('id', $result['copied_claim_ids'])
            ->where('content_block_key', 'block-0003')
            ->sole();
        $this->assertSame($newVersion->id, $copiedThirdBlockClaim->enterprise_wiki_page_version_id);
        $this->assertSame($fixture['secondEditedClaim']->claim_text, $copiedThirdBlockClaim->claim_text);

        $newClaim = EnterpriseWikiClaim::query()->findOrFail($result['new_claim_ids'][0]);
        $this->assertSame($newVersion->id, $newClaim->enterprise_wiki_page_version_id);
        $this->assertSame('block-0002', $newClaim->content_block_key);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $newClaim->content_origin);
        $this->assertNotNull($newClaim->verified_at);
        $this->assertNotNull($newClaim->canonical_fact_id);
        $this->assertSame(['source-edited-1'], $newClaim->sourceReferences()->pluck('source_element_key')->all());

        $this->assertTrue(EnterpriseWikiClaim::query()->whereKey($fixture['reviewClaim']->id)->exists());
        $this->assertFalse(EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $newVersion->id)
            ->where('claim_text', $fixture['reviewClaim']->claim_text)
            ->exists());
        $this->assertSame($canonicalFactsBefore + 1, EnterpriseWikiCanonicalFact::query()->count());
        $this->assertSame(0, $this->orphanedSourceReferencesCount());
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $fixture['run']->fresh()->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $fixture['run']->fresh()->qa_status);
    }

    public function test_manual_mixed_block_edit_can_change_multiple_blocks_without_copying_their_old_claims(): void
    {
        $fixture = $this->createManualMixedBlockEditFixture();
        $extractedBlockKeys = [];

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->twice()
            ->withArgs(function (string $pageTitle, string $pageType, string $blockMarkdown, string $contentBlockKey) use (&$extractedBlockKeys): bool {
                $extractedBlockKeys[] = $contentBlockKey;

                return $pageTitle !== ''
                    && $pageType === EnterpriseWikiPage::PAGE_TYPE_ARTICLE
                    && in_array($contentBlockKey, ['block-0002', 'block-0003'], true)
                    && str_contains($blockMarkdown, $contentBlockKey === 'block-0002' ? 'Første endrede blokk' : 'Andre endrede blokk');
            })
            ->andReturnUsing(function (string $pageTitle, string $pageType, string $blockMarkdown, string $contentBlockKey): array {
                return ['claims' => [[
                    'text' => $contentBlockKey === 'block-0002'
                        ? 'Første endrede blokk må vurderes.'
                        : 'Andre endrede blokk må vurderes.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
                    'excerpt' => $contentBlockKey === 'block-0002'
                        ? 'Første endrede blokk'
                        : 'Andre endrede blokk',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    'source_element_keys' => [],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ]]];
            });

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->never();

        $result = $this->service()->applyManualMixedBlockEdit(
            $fixture['run']->fresh(),
            $fixture['page']->fresh(),
            $fixture['version']->fresh(),
            $fixture['reviewClaim']->fresh(),
            [
                'block-0002' => 'Første endrede blokk må vurderes.',
                'block-0003' => 'Andre endrede blokk må vurderes.',
            ],
            $fixture['actor']->fresh(),
        );

        $newVersion = EnterpriseWikiPageVersion::query()->findOrFail($result['page_version_id']);

        $this->assertSame(['block-0002', 'block-0003'], $extractedBlockKeys);
        $this->assertSame(['block-0002', 'block-0003'], $result['changed_content_block_keys']);
        $this->assertCount(1, $result['copied_claim_ids']);
        $this->assertCount(2, $result['new_claim_ids']);
        $this->assertSame([], $result['canonical_fact_ids']);
        $this->assertSame('Første endrede blokk må vurderes.', $newVersion->content_blocks_json[1]['markdown']);
        $this->assertSame('Andre endrede blokk må vurderes.', $newVersion->content_blocks_json[2]['markdown']);

        $newVersionClaims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $newVersion->id)
            ->orderBy('content_block_key')
            ->get();

        $this->assertSame(['block-0001', 'block-0002', 'block-0003'], $newVersionClaims->pluck('content_block_key')->all());
        $this->assertFalse($newVersionClaims->pluck('claim_text')->contains($fixture['reviewClaim']->claim_text));
        $this->assertFalse($newVersionClaims->pluck('claim_text')->contains($fixture['secondEditedClaim']->claim_text));
        $this->assertSame(0, $this->orphanedSourceReferencesCount());
    }

    public function test_manual_mixed_block_edit_rejects_no_op_without_creating_version(): void
    {
        $fixture = $this->createManualMixedBlockEditFixture();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->never();

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('did not change any content block');

        try {
            $this->service()->applyManualMixedBlockEdit(
                $fixture['run']->fresh(),
                $fixture['page']->fresh(),
                $fixture['version']->fresh(),
                $fixture['reviewClaim']->fresh(),
                'block-0002',
                'Kunden sikrer dokumentert kontroll.',
                $fixture['actor']->fresh(),
            );
        } finally {
            $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());
            $this->assertTrue((bool) $fixture['version']->fresh()->is_current);
        }
    }

    public function test_manual_mixed_block_edit_cleans_staged_data_when_extraction_fails(): void
    {
        $fixture = $this->createManualMixedBlockEditFixture();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->andThrow(new \RuntimeException('AI extraction failed.'));

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $claimCountBefore = EnterpriseWikiClaim::query()->count();
        $referenceCountBefore = EnterpriseWikiSourceReference::query()->count();
        $canonicalFactCountBefore = EnterpriseWikiCanonicalFact::query()->count();

        try {
            $this->service()->applyManualMixedBlockEdit(
                $fixture['run']->fresh(),
                $fixture['page']->fresh(),
                $fixture['version']->fresh(),
                $fixture['reviewClaim']->fresh(),
                'block-0002',
                'Kunden bidrar til dokumentert kontroll etter revisjonen.',
                $fixture['actor']->fresh(),
            );
            $this->fail('Expected extraction failure to abort the manual edit.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI extraction failed.', $e->getMessage());
        }

        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimCountBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($referenceCountBefore, EnterpriseWikiSourceReference::query()->count());
        $this->assertSame($canonicalFactCountBefore, EnterpriseWikiCanonicalFact::query()->count());
        $this->assertTrue((bool) $fixture['version']->fresh()->is_current);
        $this->assertSame($fixture['version']->id, $fixture['pivot']->fresh()->generated_page_version_id);
        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('is_staged', true)->exists());
        $this->assertSame(0, $this->orphanedSourceReferencesCount());
    }

    public function test_manual_mixed_block_edit_cleans_staged_data_when_current_becomes_stale_after_staging(): void
    {
        $fixture = $this->createManualMixedBlockEditFixture();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->withArgs(function () use ($fixture): bool {
                $fixture['version']->update(['is_current' => false]);
                EnterpriseWikiPageVersion::query()->create([
                    'enterprise_wiki_page_id' => $fixture['page']->id,
                    'version_number' => 2,
                    'is_current' => true,
                    'is_staged' => false,
                    'content_markdown' => 'Concurrent current version.',
                    'content_blocks_json' => $fixture['blocks'],
                    'generated_by_model' => 'gpt-5',
                ]);

                return true;
            })
            ->andReturn(['claims' => [[
                'text' => 'Kunden bidrar til dokumentert kontroll etter revisjonen.',
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
                'excerpt' => 'Kunden bidrar til dokumentert kontroll',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'source_element_keys' => [],
                'best_practice_reason' => null,
                'conflict_note' => null,
            ]]]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->never();

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $claimCountBefore = EnterpriseWikiClaim::query()->count();
        $referenceCountBefore = EnterpriseWikiSourceReference::query()->count();
        $canonicalFactCountBefore = EnterpriseWikiCanonicalFact::query()->count();

        try {
            $this->service()->applyManualMixedBlockEdit(
                $fixture['run']->fresh(),
                $fixture['page']->fresh(),
                $fixture['version']->fresh(),
                $fixture['reviewClaim']->fresh(),
                'block-0002',
                'Kunden bidrar til dokumentert kontroll etter revisjonen.',
                $fixture['actor']->fresh(),
            );
            $this->fail('Expected stale current version to abort the manual edit.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not the published current version', $e->getMessage());
        }

        $this->assertSame($versionCountBefore + 1, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimCountBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($referenceCountBefore, EnterpriseWikiSourceReference::query()->count());
        $this->assertSame($canonicalFactCountBefore, EnterpriseWikiCanonicalFact::query()->count());
        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('is_staged', true)->exists());
        $this->assertSame($fixture['version']->id, $fixture['pivot']->fresh()->generated_page_version_id);
        $this->assertSame(0, $this->orphanedSourceReferencesCount());
    }

    public function test_manual_mixed_block_edit_rejects_invalid_scope_and_block_identity_before_ai(): void
    {
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->never();

        $scenarios = [
            'wrong customer' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();
                $otherCustomer = $this->createCustomer('Other Customer AS');
                $otherPage = $this->createPage($otherCustomer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Foreign Article');

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $otherPage,
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    'block-0002',
                    'Ny tekst.',
                    $fixture['actor']->fresh(),
                );
            },
            'wrong page' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();
                $otherPage = $this->createPage($fixture['customer'], EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Other Article');

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $otherPage,
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    'block-0002',
                    'Ny tekst.',
                    $fixture['actor']->fresh(),
                );
            },
            'wrong version' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();
                $wrongVersion = EnterpriseWikiPageVersion::query()->create([
                    'enterprise_wiki_page_id' => $fixture['page']->id,
                    'version_number' => 0,
                    'is_current' => false,
                    'content_markdown' => 'Historisk tekst.',
                    'content_blocks_json' => [],
                    'generated_by_model' => 'gpt-5',
                ]);

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $wrongVersion,
                    $fixture['reviewClaim']->fresh(),
                    'block-0002',
                    'Ny tekst.',
                    $fixture['actor']->fresh(),
                );
            },
            'stale current' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();
                $fixture['version']->update(['is_current' => false]);
                EnterpriseWikiPageVersion::query()->create([
                    'enterprise_wiki_page_id' => $fixture['page']->id,
                    'version_number' => 2,
                    'is_current' => true,
                    'content_markdown' => 'Ny current.',
                    'content_blocks_json' => $fixture['blocks'],
                    'generated_by_model' => 'gpt-5',
                ]);

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    'block-0002',
                    'Ny tekst.',
                    $fixture['actor']->fresh(),
                );
            },
            'unauthorized user' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();
                $viewer = User::factory()->create([
                    'customer_id' => $fixture['customer']->id,
                    'role' => User::ROLE_USER,
                    'bid_role' => User::BID_ROLE_VIEWER,
                    'is_active' => true,
                ]);

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    'block-0002',
                    'Ny tekst.',
                    $viewer,
                );
            },
            'missing block' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    'block-missing',
                    'Ny tekst.',
                    $fixture['actor']->fresh(),
                );
            },
            'review claim block unchanged' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    [
                        'block-0002' => 'Kunden sikrer dokumentert kontroll.',
                        'block-0003' => 'Kun en annen blokk endres.',
                    ],
                    $fixture['actor']->fresh(),
                );
            },
            'duplicate submitted block' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    [
                        ['block_key' => 'block-0002', 'markdown' => 'Ny tekst.'],
                        ['block_key' => 'block-0002', 'markdown' => 'Annen tekst.'],
                    ],
                    $fixture['actor']->fresh(),
                );
            },
            'duplicate stored block identity' => function (): void {
                $fixture = $this->createManualMixedBlockEditFixture();
                $blocks = $fixture['blocks'];
                $blocks[] = array_merge($blocks[1], ['position' => 99]);
                $fixture['version']->update(['content_blocks_json' => $blocks]);

                $this->service()->applyManualMixedBlockEdit(
                    $fixture['run']->fresh(),
                    $fixture['page']->fresh(),
                    $fixture['version']->fresh(),
                    $fixture['reviewClaim']->fresh(),
                    'block-0002',
                    'Ny tekst.',
                    $fixture['actor']->fresh(),
                );
            },
        ];

        foreach ($scenarios as $scenario => $exercise) {
            try {
                $exercise();
                $this->fail("Expected manual mixed block edit scenario [{$scenario}] to be rejected.");
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiClaimContentRepairService
    {
        return app(EnterpriseWikiClaimContentRepairService::class);
    }

    private function createCustomer(string $name = 'Claim Content Repair Test AS'): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Authoritative source document text for claim content repair tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRepairRequiredRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            'qa_started_at' => now()->subMinute(),
            'qa_completed_at' => now(),
            'qa_attempt_count' => 1,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function createVersion(EnterpriseWikiPage $page, array $blocks, string $markdown): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function block(string $blockKey, string $markdown, EnterpriseWikiDocument $document): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => 'paragraph',
        ];
    }

    private function attachPageToRun(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        bool $extracted = false,
    ): EnterpriseWikiIngestRunPage {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'generation_started_at' => now()->subMinute(),
            'generation_completed_at' => now(),
            'claims_extracted_at' => $extracted ? now() : null,
        ]);
    }

    private function createBadClaim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $blockKey): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Bad text.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'content_block_key' => $blockKey,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createManualMixedBlockEditFixture(): array
    {
        $customer = $this->createCustomer('Manual Mixed Block Edit AS');
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $page = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Manual Edit Article');
        $actor = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);

        $blocks = [
            $this->manualEditBlock(
                'block-0001',
                'Uendret kildebasert tekst.',
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                $document,
                0,
                'source-unchanged-1',
                'Uendret kildebasert tekst.',
            ),
            $this->manualEditBlock(
                'block-0002',
                'Kunden sikrer dokumentert kontroll.',
                'mixed',
                $document,
                1,
                'source-edited-1',
                'Kunden medvirker til dokumentert kontroll etter revisjonen.',
            ),
            $this->manualEditBlock(
                'block-0003',
                'Kunden beskriver gammel risiko.',
                'mixed',
                $document,
                2,
                'source-edited-2',
                'Kunden beskriver oppdatert risiko.',
            ),
        ];

        $version = $this->createVersion(
            $page,
            $blocks,
            implode("\n\n", array_column($blocks, 'markdown')),
        );
        $pivot = $this->attachPageToRun($run, $page, $version, extracted: true);

        $canonicalFact = EnterpriseWikiCanonicalFact::query()->create([
            'customer_id' => $customer->id,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => $document->file_hash_sha256,
            'source_element_keys' => ['source-unchanged-1'],
            'source_element_keys_hash' => hash('sha256', json_encode(['source-unchanged-1'], JSON_THROW_ON_ERROR)),
            'normalized_fingerprint' => hash('sha256', 'uendret-kildebasert-tekst'),
            'canonical_text' => 'Uendret kildebasert tekst.',
            'verification_status' => EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED,
            'verification_reason' => 'Eksisterende verifisert claim.',
            'verified_at' => now(),
        ]);

        $unchangedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Uendret kildebasert tekst.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'page_excerpt' => 'Uendret kildebasert tekst.',
            'content_block_key' => 'block-0001',
            'canonical_fact_id' => $canonicalFact->id,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'approval_comment' => 'Beholdt fra tidligere versjon.',
            'verified_at' => now(),
        ]);

        $unchangedReference = EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $unchangedClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_element_key' => 'source-unchanged-1',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_label' => $document->original_filename,
            'excerpt' => 'Uendret kildebasert tekst.',
            'source_hash' => $document->file_hash_sha256,
            'page_reference' => 'Avsnitt 1',
        ]);

        EnterpriseWikiClaimDecision::query()->create([
            'enterprise_wiki_claim_id' => $unchangedClaim->id,
            'decided_by_user_id' => $actor->id,
            'decision_type' => EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS,
            'previous_state' => ['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING],
            'new_state' => ['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED],
            'comment' => 'Historisk beslutning kopieres ikke.',
            'created_at' => now(),
        ]);

        $reviewClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Kunden sikrer dokumentert kontroll.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'page_excerpt' => 'Kunden sikrer dokumentert kontroll.',
            'content_block_key' => 'block-0002',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $secondEditedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Kunden beskriver gammel risiko.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'page_excerpt' => 'Kunden beskriver gammel risiko.',
            'content_block_key' => 'block-0003',
            'position_order' => 2,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        return compact(
            'customer',
            'document',
            'run',
            'page',
            'version',
            'pivot',
            'actor',
            'blocks',
            'canonicalFact',
            'unchangedClaim',
            'unchangedReference',
            'reviewClaim',
            'secondEditedClaim',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manualEditBlock(
        string $blockKey,
        string $markdown,
        string $contentOrigin,
        EnterpriseWikiDocument $document,
        int $position,
        string $sourceElementKey,
        string $sourceExcerpt,
    ): array {
        $sourceElement = [
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'document_version_hash' => $document->file_hash_sha256,
            'source_element_key' => $sourceElementKey,
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_excerpt' => $sourceExcerpt,
            'page_reference' => 'Avsnitt '.($position + 1),
        ];

        return [
            'block_key' => $blockKey,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => $contentOrigin,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'document_version_hash' => $document->file_hash_sha256,
            'source_element_key' => $sourceElementKey,
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_excerpt' => $sourceExcerpt,
            'page_reference' => 'Avsnitt '.($position + 1),
            'source_elements' => [$sourceElement],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    private function orphanedSourceReferencesCount(): int
    {
        return DB::table('enterprise_wiki_source_references')
            ->leftJoin('enterprise_wiki_claims', 'enterprise_wiki_source_references.enterprise_wiki_claim_id', '=', 'enterprise_wiki_claims.id')
            ->whereNull('enterprise_wiki_claims.id')
            ->count();
    }
}
