<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\CreatesWikiManualEditFixture;
use Tests\TestCase;

/**
 * Ordinary manual editing of a page's working version, through PATCH /app/wiki/{slug}/working-version.
 *
 * THE PRODUCT RULE THESE TESTS PIN DOWN: an authorized page owner is authoritative for the words on
 * their own working version. Saving therefore runs no claim extraction, no verification and no
 * source re-grounding; it needs no ingest run, no source document, and no AI. Every save test below
 * asserts that no AI client is reached at all — that is the rule, not an optimisation.
 *
 * Claim repair (WikiManualMixedBlockEditControllerTest) is the other job: there Procynia judges
 * claims against sources and is deliberately strict. The two must not be conflated again.
 */
class WikiWorkingVersionEditControllerTest extends TestCase
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

    public function test_page_owner_can_save_an_edited_block_as_a_new_working_version(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Owner AS');
        $owner = $this->pageOwner($fixture);
        $newMarkdown = 'Teksten slik sideeier vil ha den.';

        $this->expectNoAiCalls();

        $this->actingAs($owner)
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => $newMarkdown]],
            ])
            ->assertRedirect(route('app.wiki.show', ['slug' => $fixture['page']->slug]))
            ->assertSessionHas('success');

        $new = $this->currentVersion($fixture['page']);
        $this->assertNotSame((int) $fixture['version']->id, (int) $new->id);
        $this->assertSame(2, (int) $new->version_number);
        $this->assertStringContainsString($newMarkdown, (string) $new->content_markdown);
    }

    /**
     * The whole point of the correction: the text may say something the source document does not,
     * and it still saves. The user decides what the page says.
     */
    public function test_text_the_source_does_not_support_is_saved_anyway(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Authoritative AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Leverandøren skal betale 50 000 kroner i dagbot ved forsinkelse.']],
            ])
            ->assertSessionHas('success');

        $this->assertStringContainsString(
            'dagbot',
            (string) $this->currentVersion($fixture['page'])->content_markdown,
        );
    }

    /** Normative wording is ordinary Norwegian prose, not a reason to involve anything. */
    public function test_normative_wording_saves_like_any_other_text(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Normative AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Prosessen skal gi etterprøvbar sporbarhet for det som er dokumentert.']],
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, (int) $this->currentVersion($fixture['page'])->version_number);
    }

    /** An explicit product requirement: editing must work in an environment with AI switched off. */
    public function test_editing_works_with_ai_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $fixture = $this->createManualEditFixture('Working Version Ai Off AS');
        $newMarkdown = 'Lagret uten AI i miljøet.';

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => $newMarkdown]],
            ])
            ->assertSessionHas('success');

        $current = $this->currentVersion($fixture['page']);
        $this->assertSame(2, (int) $current->version_number);
        $this->assertStringContainsString($newMarkdown, (string) $current->content_markdown);
    }

    public function test_show_offers_editing_even_when_ai_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $fixture = $this->createManualEditFixture('Working Version Context Ai Off AS');

        $this->actingAs($this->pageOwner($fixture))
            ->get(route('app.wiki.show', ['slug' => $fixture['page']->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('working_version_edit.can_edit', true)
                ->where('working_version_edit.unavailable_reason', null)
            );
    }

    public function test_system_owner_can_save_without_being_the_page_owner(): void
    {
        $fixture = $this->createManualEditFixture('Working Version System Owner AS');

        $this->expectNoAiCalls();
        $this->assertNull($fixture['page']->fresh()->owner_user_id);

        $this->actingAs($fixture['actor'])
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'System Owner retter teksten.']],
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, (int) $this->currentVersion($fixture['page'])->version_number);
    }

    public function test_user_without_permission_is_refused_server_side(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Denied AS');
        $stranger = $this->contributor($fixture);

        $this->expectNoAiCalls();

        $this->actingAs($stranger)
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Uautorisert endring.']],
            ])
            ->assertForbidden();

        $this->assertNoNewVersion($fixture);
    }

    public function test_request_needs_neither_a_claim_id_nor_a_run_id(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Payload AS');

        $this->expectNoAiCalls();

        $payload = [
            'expected_page_version_id' => $fixture['version']->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ingen run- eller claim-id.']],
        ];

        $this->assertArrayNotHasKey('run_id', $payload);
        $this->assertArrayNotHasKey('claim_id', $payload);
        $this->assertStringNotContainsString('claims', $this->workingVersionUrl($fixture['page']));

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), $payload)
            ->assertSessionHas('success');

        $this->assertSame(2, (int) $this->currentVersion($fixture['page'])->version_number);
    }

    public function test_only_changed_blocks_are_touched(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Changed Only AS');
        $newMarkdown = 'Bare denne blokken er endret.';

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => $newMarkdown]],
            ])
            ->assertSessionHas('success');

        $blocks = collect((array) $this->currentVersion($fixture['page'])->content_blocks_json)
            ->keyBy(fn (array $block): string => (string) $block['block_key']);

        $this->assertSame($newMarkdown, $blocks['block-0001']['markdown']);
        $this->assertSame('Kunden sikrer dokumentert kontroll.', $blocks['block-0002']['markdown']);
        $this->assertSame('Kunden beskriver gammel risiko.', $blocks['block-0003']['markdown']);

        // Untouched blocks keep exactly the provenance they had.
        $this->assertSame('mixed', $blocks['block-0002']['content_origin']);
        $this->assertSame('source-edited-1', $blocks['block-0002']['source_element_key']);
    }

    /**
     * The honest-provenance half of the rule. The edit is never refused, but the stored provenance
     * must stop claiming the document backs words a person has since rewritten.
     */
    public function test_an_edited_block_becomes_human_authored_and_drops_document_provenance(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Provenance AS');

        $before = collect((array) $fixture['version']->content_blocks_json)->firstWhere('block_key', 'block-0001');
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $before['content_origin']);
        $this->assertSame('source-unchanged-1', $before['source_element_key']);

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Sideeier har skrevet om denne teksten.']],
            ])
            ->assertSessionHas('success');

        $after = collect((array) $this->currentVersion($fixture['page'])->content_blocks_json)
            ->firstWhere('block_key', 'block-0001');

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_HUMAN_AUTHORED, $after['content_origin']);
        $this->assertNull($after['source_element_key']);
        $this->assertNull($after['source_id']);
        $this->assertNull($after['source_excerpt']);
        $this->assertSame([], $after['source_elements']);

        // The superseded version still holds the original text and its provenance. That is the
        // audit trail, which is why nothing has to be preserved on the block itself.
        $previousBlock = collect((array) $fixture['version']->fresh()->content_blocks_json)
            ->firstWhere('block_key', 'block-0001');
        $this->assertSame('source-unchanged-1', $previousBlock['source_element_key']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $previousBlock['content_origin']);
    }

    /** The new version records who wrote it. */
    public function test_the_new_version_records_its_editor(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Author AS');
        $owner = $this->pageOwner($fixture);

        $this->expectNoAiCalls();

        $this->actingAs($owner)
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Skrevet av sideeier.']],
            ])
            ->assertSessionHas('success');

        $current = $this->currentVersion($fixture['page']);
        $this->assertSame((int) $owner->id, (int) $current->created_by_user_id);
        $this->assertNull($current->generated_by_model);
    }

    /**
     * A verification result earned against the OLD wording says nothing about the new one, so it is
     * not carried onto the new version for a block the user changed.
     */
    public function test_stale_claims_are_not_carried_onto_the_new_version(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Stale Claims AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Helt ny formulering fra sideeier.']],
            ])
            ->assertSessionHas('success');

        $current = $this->currentVersion($fixture['page']);
        $liveBlockKeys = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $current->id)
            ->pluck('content_block_key')
            ->all();

        // The edited block's previously-approved claim is gone from the live version.
        $this->assertNotContains('block-0001', $liveBlockKeys);

        // Untouched blocks keep theirs.
        $this->assertContains('block-0002', $liveBlockKeys);

        // And the old claim still exists on the superseded version, as history.
        $this->assertSame(1, EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $fixture['version']->id)
            ->where('content_block_key', 'block-0001')
            ->count());
    }

    public function test_saving_supersedes_the_previous_version_and_keeps_workflow_state(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Lifecycle AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Oppdatert tekst i arbeidsversjonen.']],
            ])
            ->assertSessionHas('success');

        $previous = $fixture['version']->fresh();
        $current = $this->currentVersion($fixture['page']);

        $this->assertFalse((bool) $previous->is_current);
        $this->assertTrue((bool) $current->is_current);
        $this->assertFalse((bool) $current->is_staged);
        $this->assertSame((int) $previous->version_number + 1, (int) $current->version_number);

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $fixture['page']->fresh()->status);
        $this->assertNull($current->reviewer_user_id);
        $this->assertNull($current->submitted_at);
        $this->assertNull($fixture['page']->fresh()->published_version_id);
    }

    public function test_both_content_representations_carry_the_edit(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Projection AS');
        $marker = 'UNIK-MARKOR-7Q2';
        $newMarkdown = 'Oppdatert tekst '.$marker.'.';
        $originalBlockText = 'Uendret kildebasert tekst.';

        $this->assertStringContainsString($originalBlockText, (string) $fixture['version']->content_markdown);
        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => $newMarkdown]],
            ])
            ->assertSessionHas('success');

        $current = $this->currentVersion($fixture['page']);
        $edited = collect((array) $current->content_blocks_json)->firstWhere('block_key', 'block-0001');

        $this->assertSame($newMarkdown, $edited['markdown']);
        $this->assertStringContainsString($marker, (string) $current->content_markdown);
        $this->assertStringNotContainsString($originalBlockText, (string) $current->content_markdown);
        $this->assertStringContainsString('Kunden sikrer dokumentert kontroll.', (string) $current->content_markdown);

        $this->actingAs($this->pageOwner($fixture))
            ->get(route('app.wiki.show', ['slug' => $fixture['page']->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('current_version.id', (int) $current->id)
                ->where('current_version.version_number', 2)
                ->where('current_version.content_markdown', fn (string $markdown): bool => str_contains($markdown, $marker))
            );
    }

    public function test_best_practice_review_survives_a_manual_edit(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Bp Review AS');
        $review = [['planned_topic' => 'Kontroll', 'gap_found' => false, 'justification' => 'Dekket.']];
        $fixture['version']->forceFill(['best_practice_review_json' => $review])->save();

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Oppdatert tekst.']],
            ])
            ->assertSessionHas('success');

        $current = $this->currentVersion($fixture['page']);
        $this->assertSame(2, (int) $current->version_number);
        $this->assertSame($review, $current->best_practice_review_json);
    }

    public function test_no_changed_blocks_creates_no_version(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Noop AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [],
            ])
            ->assertRedirect(route('app.wiki.show', ['slug' => $fixture['page']->slug]));

        $this->assertNoNewVersion($fixture);
    }

    public function test_stale_expected_version_conflicts_and_leaves_the_newer_version_untouched(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Conflict AS');

        $fixture['version']->forceFill(['is_current' => false])->save();
        $newer = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $fixture['page']->id,
            'version_number' => 2,
            'is_current' => true,
            'is_staged' => false,
            'content_markdown' => 'Nyere innhold fra en annen bruker.',
            'content_blocks_json' => $fixture['blocks'],
        ]);

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Overskriving som ikke skal skje.']],
            ])
            ->assertSessionHas('error');

        $current = $this->currentVersion($fixture['page']);
        $this->assertSame((int) $newer->id, (int) $current->id);
        $this->assertSame('Nyere innhold fra en annen bruker.', $current->content_markdown);
    }

    public function test_unknown_block_key_is_rejected(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Unknown Block AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-9999', 'markdown' => 'Finnes ikke.']],
            ])
            ->assertSessionHasErrors('blocks');

        $this->assertNoNewVersion($fixture);
    }

    public function test_table_block_is_rejected_server_side(): void
    {
        $this->assertStructuredBlockIsRejected('Working Version Table AS', [
            'block_type' => 'table',
            'table_data' => ['headers' => ['Kolonne'], 'rows' => [['Verdi']]],
        ]);
    }

    public function test_image_block_is_rejected_server_side(): void
    {
        $this->assertStructuredBlockIsRejected('Working Version Image AS', [
            'block_type' => 'image',
            'image_data' => ['source_element_key' => 'source-image-1', 'alt_text' => 'Figur'],
        ]);
    }

    public function test_structural_block_is_rejected(): void
    {
        $this->assertOriginIsRejected('Working Version Structural AS', EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL);
    }

    public function test_unclassified_block_is_rejected(): void
    {
        $this->assertOriginIsRejected('Working Version Unclassified AS', EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED);
    }

    public function test_internal_error_block_is_rejected(): void
    {
        $this->assertOriginIsRejected('Working Version Internal Error AS', EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
    }

    public function test_mixed_block_is_rejected_by_the_working_version_editor(): void
    {
        $this->assertOriginIsRejected('Working Version Mixed AS', 'mixed');
    }

    /** A block a person wrote once stays editable by a person. */
    public function test_a_human_authored_block_can_be_edited_again(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Reedit AS');

        $blocks = (array) $fixture['version']->content_blocks_json;
        $blocks[0]['content_origin'] = EnterpriseWikiClaim::CONTENT_ORIGIN_HUMAN_AUTHORED;
        $fixture['version']->forceFill(['content_blocks_json' => $blocks])->save();

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Redigert en gang til.']],
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, (int) $this->currentVersion($fixture['page'])->version_number);
    }

    public function test_block_count_bound_is_this_endpoints_own_not_the_claim_repair_25(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Bound AS');

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => $this->elevenBlocks(),
            ])
            ->assertSessionHasErrors('blocks');

        $this->assertNoNewVersion($fixture);
    }

    public function test_block_count_message_is_actionable_and_free_of_internals(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Bound Message AS');

        $this->expectNoAiCalls();

        $response = $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => $this->elevenBlocks(),
            ]);

        $response->assertInvalid(['blocks' => 'Sett noen avsnitt tilbake til opprinnelig tekst']);

        $errors = $response->getSession()->get('errors');
        $message = (string) ($errors instanceof ViewErrorBag ? $errors->first('blocks') : '');

        $this->assertNotSame('', $message);

        foreach (['max:', 'validation', 'fastcgi', 'timeout', 'AI', 'block_key', 'payload'] as $internal) {
            $this->assertStringNotContainsString($internal, $message);
        }
    }

    public function test_show_exposes_the_working_version_edit_context(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Context AS');

        $this->actingAs($this->pageOwner($fixture))
            ->get(route('app.wiki.show', ['slug' => $fixture['page']->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('working_version_edit.can_edit', true)
                ->where('working_version_edit.unavailable_reason', null)
                ->where('working_version_edit.expected_page_version_id', (int) $fixture['version']->id)
                ->where('working_version_edit.max_blocks_per_save', 10)
            );
    }

    public function test_show_reports_missing_permission_rather_than_offering_editing(): void
    {
        $fixture = $this->createManualEditFixture('Working Version Context Denied AS');

        $this->actingAs($this->contributor($fixture))
            ->get(route('app.wiki.show', ['slug' => $fixture['page']->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('working_version_edit.can_edit', false)
                ->where('working_version_edit.unavailable_reason', 'not_authorized')
            );
    }

    /** @return list<array{block_key: string, markdown: string}> */
    private function elevenBlocks(): array
    {
        $blocks = [];

        for ($i = 1; $i <= 11; $i++) {
            $blocks[] = ['block_key' => 'block-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'markdown' => 'Tekst '.$i];
        }

        return $blocks;
    }

    /** @param array<string, mixed> $structuredPayload */
    private function assertStructuredBlockIsRejected(string $customerName, array $structuredPayload): void
    {
        $fixture = $this->createManualEditFixture($customerName);

        $blocks = (array) $fixture['version']->content_blocks_json;
        $blocks[0] = array_merge($blocks[0], $structuredPayload);
        $fixture['version']->forceFill(['content_blocks_json' => $blocks])->save();

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Forsøk på å redigere strukturert innhold.']],
            ])
            ->assertSessionHasErrors('blocks');

        $this->assertNoNewVersion($fixture);
    }

    private function assertOriginIsRejected(string $customerName, string $origin): void
    {
        $fixture = $this->createManualEditFixture($customerName);

        $blocks = (array) $fixture['version']->content_blocks_json;
        $blocks[0]['content_origin'] = $origin;
        $fixture['version']->forceFill(['content_blocks_json' => $blocks])->save();

        $this->expectNoAiCalls();

        $this->actingAs($this->pageOwner($fixture))
            ->patch($this->workingVersionUrl($fixture['page']), [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Forsøk på redigering.']],
            ])
            ->assertSessionHasErrors('blocks');

        $this->assertNoNewVersion($fixture);
    }

    /** @param array<string, mixed> $fixture */
    private function pageOwner(array $fixture): User
    {
        $existing = $fixture['page']->fresh()->owner_user_id;

        if ($existing !== null) {
            return User::query()->findOrFail($existing);
        }

        $owner = $this->contributor($fixture);
        $fixture['page']->forceFill(['owner_user_id' => $owner->id])->save();

        return $owner;
    }

    /** @param array<string, mixed> $fixture */
    private function contributor(array $fixture): User
    {
        return User::factory()->create([
            'customer_id' => $fixture['customer']->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
        ]);
    }

    private function workingVersionUrl(EnterpriseWikiPage $page): string
    {
        return route('app.wiki.working-version.update', ['slug' => $page->slug]);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $fixture */
    private function assertNoNewVersion(array $fixture): void
    {
        $current = $this->currentVersion($fixture['page']);

        $this->assertSame((int) $fixture['version']->id, (int) $current->id);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $fixture['page']->id)
            ->count());
    }

    /** Manual saving must never reach an AI client. */
    private function expectNoAiCalls(): void
    {
        $extraction = $this->mock(WikiPageClaimExtractionAiClient::class);
        $extraction->shouldNotReceive('extractClaimsForManualMixedBlock');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
    }
}
