<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
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
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

class WikiManualMixedBlockEditControllerTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_authorized_user_can_save_one_changed_block(): void
    {
        $fixture = $this->createManualEditFixture();
        $newMarkdown = 'Kunden bidrar til dokumentert kontroll.';

        $this->expectManualExtractionAndVerification([
            'block-0002' => $newMarkdown,
        ], $fixture);

        $response = $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    ['block_key' => 'block-0002', 'markdown' => $newMarkdown],
                ],
                'back_url' => '/app/wiki?tab=runs',
            ],
        );

        $newClaim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_id', $fixture['page']->id)
            ->where('content_block_key', 'block-0002')
            ->where('claim_text', $newMarkdown)
            ->sole();

        $response
            ->assertRedirect(route('app.wiki.show', [
                'slug' => $fixture['page']->slug,
                'claim_id' => $newClaim->id,
                'back_url' => '/app/wiki?tab=runs',
            ]))
            ->assertSessionHas('success', 'Wiki-teksten er lagret som ny aktiv versjon.');

        $oldVersion = $fixture['version']->fresh();
        $this->assertFalse($oldVersion->is_current);
        $this->assertSame('Kunden sikrer dokumentert kontroll.', $oldVersion->content_blocks_json[1]['markdown']);

        $currentVersion = $fixture['page']->fresh()->currentVersion;
        $this->assertSame($newClaim->enterprise_wiki_page_version_id, $currentVersion->id);
        $this->assertTrue($currentVersion->is_current);
        $this->assertFalse($currentVersion->is_staged);
        $this->assertSame($newMarkdown, $currentVersion->content_blocks_json[1]['markdown']);
        $this->assertSame('mixed', $currentVersion->content_blocks_json[1]['content_origin']);
        $this->assertSame($currentVersion->id, $fixture['pivot']->fresh()->generated_page_version_id);
    }

    public function test_authorized_user_can_save_multiple_changed_blocks(): void
    {
        $fixture = $this->createManualEditFixture();
        $edits = [
            'block-0002' => 'Første endrede blokk dokumenteres.',
            'block-0003' => 'Andre endrede blokk dokumenteres.',
        ];

        $this->expectManualExtractionAndVerification($edits, $fixture);

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    ['block_key' => 'block-0002', 'markdown' => $edits['block-0002']],
                    ['block_key' => 'block-0003', 'markdown' => $edits['block-0003']],
                ],
            ],
        )->assertSessionHas('success');

        $currentVersion = $fixture['page']->fresh()->currentVersion;
        $this->assertSame($edits['block-0002'], $currentVersion->content_blocks_json[1]['markdown']);
        $this->assertSame($edits['block-0003'], $currentVersion->content_blocks_json[2]['markdown']);
        $this->assertSame($fixture['blocks'][0], $currentVersion->content_blocks_json[0]);
        $this->assertSame(2, EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $currentVersion->id)
            ->whereIn('content_block_key', ['block-0002', 'block-0003'])
            ->count());
        $this->assertFalse(EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $currentVersion->id)
            ->whereIn('claim_text', [
                $fixture['reviewClaim']->claim_text,
                $fixture['secondReviewClaim']->claim_text,
            ])
            ->exists());
    }

    public function test_foreign_customer_page_is_not_found(): void
    {
        $fixture = $this->createManualEditFixture();
        $foreignFixture = $this->createManualEditFixture('Foreign Manual Edit AS');

        $this->mock(EnterpriseWikiClaimContentRepairService::class)
            ->shouldReceive('applyManualMixedBlockEdit')
            ->never();

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($foreignFixture['page'], $foreignFixture['reviewClaim']),
            [
                'run_id' => $foreignFixture['run']->id,
                'expected_page_version_id' => $foreignFixture['version']->id,
                'blocks' => [
                    ['block_key' => 'block-0002', 'markdown' => 'Fremmed endring.'],
                ],
            ],
        )->assertNotFound();

        $this->assertTrue($foreignFixture['version']->fresh()->is_current);
    }

    public function test_user_without_claim_approval_permission_cannot_save(): void
    {
        $fixture = $this->createManualEditFixture();
        $viewer = User::factory()->create([
            'customer_id' => $fixture['customer']->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'is_active' => true,
        ]);

        $this->mock(EnterpriseWikiClaimContentRepairService::class)
            ->shouldReceive('applyManualMixedBlockEdit')
            ->never();

        $this->actingAs($viewer)->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    ['block_key' => 'block-0002', 'markdown' => 'Ikke autorisert.'],
                ],
            ],
        )->assertForbidden();

        $this->assertTrue($fixture['version']->fresh()->is_current);
    }

    public function test_stale_page_version_returns_conflict_message_without_changing_current_version(): void
    {
        $fixture = $this->createManualEditFixture();
        $staleVersion = $fixture['version'];
        $staleVersion->update(['is_current' => false]);
        $newCurrent = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $fixture['page']->id,
            'version_number' => 2,
            'is_current' => true,
            'is_staged' => false,
            'content_markdown' => $staleVersion->content_markdown,
            'content_blocks_json' => $staleVersion->content_blocks_json,
            'generated_by_model' => 'gpt-5',
        ]);

        $this->mock(EnterpriseWikiClaimContentRepairService::class)
            ->shouldReceive('applyManualMixedBlockEdit')
            ->never();

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $staleVersion->id,
                'blocks' => [
                    ['block_key' => 'block-0002', 'markdown' => 'For sen endring.'],
                ],
            ],
        )
            ->assertRedirect()
            ->assertSessionHas('error', 'Wiki-siden er endret av noen andre. Last inn siden på nytt før du lagrer.');

        $this->assertTrue($newCurrent->fresh()->is_current);
        $this->assertSame($newCurrent->id, $fixture['page']->fresh()->currentVersion->id);
    }

    public function test_invalid_or_missing_block_key_is_rejected(): void
    {
        $fixture = $this->createManualEditFixture();

        $this->mock(EnterpriseWikiClaimContentRepairService::class)
            ->shouldReceive('applyManualMixedBlockEdit')
            ->never();

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    ['markdown' => 'Mangler nøkkel.'],
                ],
            ],
        )->assertSessionHasErrors(['blocks.0.block_key']);

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    ['block_key' => 'block-missing', 'markdown' => 'Ukjent blokk.'],
                ],
            ],
        )->assertSessionHasErrors(['blocks']);

        $this->assertTrue($fixture['version']->fresh()->is_current);
    }

    public function test_controller_delegates_manual_block_edit_to_repair_service(): void
    {
        $fixture = $this->createManualEditFixture();
        $submittedMarkdown = $fixture['blocks'][1]['markdown'];

        $this->mock(EnterpriseWikiClaimContentRepairService::class)
            ->shouldReceive('applyManualMixedBlockEdit')
            ->once()
            ->withArgs(function (
                EnterpriseWikiIngestRun $run,
                EnterpriseWikiPage $page,
                EnterpriseWikiPageVersion $version,
                EnterpriseWikiClaim $reviewClaim,
                array $blocks,
                User $actor,
            ) use ($fixture, $submittedMarkdown): bool {
                $this->assertSame($fixture['run']->id, $run->id);
                $this->assertSame($fixture['page']->id, $page->id);
                $this->assertSame($fixture['version']->id, $version->id);
                $this->assertSame($fixture['reviewClaim']->id, $reviewClaim->id);
                $this->assertSame([[
                    'block_key' => 'block-0002',
                    'markdown' => $submittedMarkdown,
                ]], $blocks);
                $this->assertSame($fixture['actor']->id, $actor->id);

                return true;
            })
            ->andReturn([
                'page_version_id' => $fixture['version']->id + 1,
                'previous_page_version_id' => $fixture['version']->id,
                'changed_content_block_keys' => ['block-0002'],
                'copied_claim_ids' => [],
                'new_claim_ids' => [],
                'extracted_claims' => 0,
                'verified_claims' => 0,
                'canonical_fact_ids' => [],
            ]);

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    ['block_key' => 'block-0002', 'markdown' => $submittedMarkdown],
                ],
            ],
        )
            ->assertRedirect(route('app.wiki.show', ['slug' => $fixture['page']->slug]))
            ->assertSessionHas('success');
    }

    public function test_show_exposes_raw_blocks_and_manual_edit_context(): void
    {
        $fixture = $this->createManualEditFixture();
        $linkedMarkdown = 'Se [[annen-side|annen side]] i teksten.';
        $fixture['version']->update([
            'content_markdown' => $linkedMarkdown,
            'content_blocks_json' => [[
                'block_key' => 'block-0002',
                'position' => 0,
                'markdown' => $linkedMarkdown,
                'content_origin' => 'mixed',
                'source_elements' => [],
            ]],
        ]);

        $response = $this->actingAs($fixture['actor'])->get(route('app.wiki.show', [
            'slug' => $fixture['page']->slug,
            'claim_id' => $fixture['reviewClaim']->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($fixture, $linkedMarkdown): bool {
            $block = data_get($inertia, 'props.current_version.content_blocks_json.0');

            return data_get($inertia, 'props.can_edit_wiki_claims') === true
                && data_get($inertia, 'props.manual_block_edit.run_id') === $fixture['run']->id
                && data_get($inertia, 'props.manual_block_edit.update_url_template') === "/app/wiki/{$fixture['page']->slug}/claims/__CLAIM_ID__/manual-block-edit"
                && data_get($block, 'raw_markdown') === $linkedMarkdown
                && data_get($block, 'content_origin') === 'mixed';
        });
    }

    private function expectManualExtractionAndVerification(array $edits, array $fixture): void
    {
        $sourceKeyByBlock = [
            'block-0002' => 'source-edited-1',
            'block-0003' => 'source-edited-2',
        ];

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->times(count($edits))
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $blockMarkdown,
                string $contentBlockKey,
                array $sourceElements,
            ) use ($edits, $fixture, $sourceKeyByBlock): array {
                $this->assertSame($fixture['page']->title, $pageTitle);
                $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $pageType);
                $this->assertArrayHasKey($contentBlockKey, $edits);
                $this->assertSame($edits[$contentBlockKey], $blockMarkdown);
                $this->assertSame([$sourceKeyByBlock[$contentBlockKey]], array_column($sourceElements, 'key'));

                return ['claims' => [[
                    'text' => $blockMarkdown,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'excerpt' => $blockMarkdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => [$sourceKeyByBlock[$contentBlockKey]],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ]]];
            });

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->times(count($edits))
            ->andReturnUsing(function (
                string $claimText,
                array $sourceElements,
                string $fallbackSourceText,
                string $languageCode,
                ?string $blockMarkdown,
            ) use ($edits, $sourceKeyByBlock): array {
                $contentBlockKey = array_search($claimText, $edits, true);
                $this->assertIsString($contentBlockKey);
                $this->assertSame([$sourceKeyByBlock[$contentBlockKey]], array_column($sourceElements, 'key'));
                $this->assertSame('', $fallbackSourceText);
                $this->assertSame('no', $languageCode);
                $this->assertNull($blockMarkdown);

                return $this->verificationResult(
                    supportingSourceElementKeys: [$sourceKeyByBlock[$contentBlockKey]],
                    reason: 'Påstanden er støttet av kildereferansen.',
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function createManualEditFixture(string $customerName = 'Manual Edit Controller AS'): array
    {
        $customer = $this->createCustomer($customerName);
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document);
        $page = $this->createPage($customer);
        $actor = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);

        $blocks = [
            $this->block('block-0001', 'Uendret kildebasert tekst.', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $document, 0, 'source-unchanged-1'),
            $this->block('block-0002', 'Kunden sikrer dokumentert kontroll.', 'mixed', $document, 1, 'source-edited-1'),
            $this->block('block-0003', 'Kunden beskriver gammel risiko.', 'mixed', $document, 2, 'source-edited-2'),
        ];
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'is_staged' => false,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
        $pivot = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'claims_extracted_at' => now(),
        ]);

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
            'verified_at' => now(),
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $unchangedClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_element_key' => 'source-unchanged-1',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_label' => $document->original_filename,
            'excerpt' => 'Uendret kildebasert tekst.',
            'source_hash' => $document->file_hash_sha256,
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
        $secondReviewClaim = EnterpriseWikiClaim::query()->create([
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
            'actor',
            'blocks',
            'version',
            'pivot',
            'unchangedClaim',
            'reviewClaim',
            'secondReviewClaim',
        );
    }

    private function createCustomer(string $name): Customer
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
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
            'extracted_text' => 'Authoritative source document text.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ]);
    }

    private function createPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'manual-edit-'.Str::lower(Str::random(8)),
            'title' => 'Manual Edit Article',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function block(
        string $blockKey,
        string $markdown,
        string $contentOrigin,
        EnterpriseWikiDocument $document,
        int $position,
        string $sourceElementKey,
    ): array {
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
            'source_excerpt' => $markdown,
            'page_reference' => 'Avsnitt '.($position + 1),
            'source_elements' => [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_hash' => $document->file_hash_sha256,
                'document_version_hash' => $document->file_hash_sha256,
                'source_element_key' => $sourceElementKey,
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_row_key' => null,
                'source_excerpt' => $markdown,
                'page_reference' => 'Avsnitt '.($position + 1),
            ]],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    private function manualEditUrl(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): string
    {
        return route('app.wiki.claims.manual-block-edit.update', [
            'slug' => $page->slug,
            'claim' => $claim->id,
        ]);
    }
}
