<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\CreatesWikiManualEditFixture;
use Tests\TestCase;

class WikiManualMixedBlockEditControllerTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use CreatesWikiManualEditFixture;
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

    /**
     * Claim repair repairs a mixed-provenance block against its source; a source_based block is not
     * something this flow may rewrite. The rule was previously unpinned, which is how it survived
     * only as an accident of the shared code path — and how general working-version editing
     * inherited it by mistake. It belongs to THIS endpoint, and it is asserted here.
     */
    public function test_a_non_mixed_block_is_still_rejected_by_claim_repair(): void
    {
        $fixture = $this->createManualEditFixture();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldNotReceive('extractClaimsForManualMixedBlock');
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldNotReceive('verifyClaim');

        $this->actingAs($fixture['actor'])->patch(
            $this->manualEditUrl($fixture['page'], $fixture['reviewClaim']),
            [
                'run_id' => $fixture['run']->id,
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [
                    // The review claim's own mixed block, plus a source_based one it may not touch.
                    ['block_key' => 'block-0002', 'markdown' => 'Endret mixed-tekst.'],
                    ['block_key' => 'block-0001', 'markdown' => 'Forsøk på å endre kildebasert tekst.'],
                ],
            ],
        )->assertSessionHasErrors('blocks');

        $this->assertSame(
            (int) $fixture['version']->id,
            (int) EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $fixture['page']->id)
                ->where('is_current', true)
                ->value('id'),
        );
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

    private function manualEditUrl(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): string
    {
        return route('app.wiki.claims.manual-block-edit.update', [
            'slug' => $page->slug,
            'claim' => $claim->id,
        ]);
    }
}
