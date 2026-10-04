<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimFindingExplainer;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * A generated source_based block is document content on the page, so its whole wording is checked
 * against the source elements it cites — through the ordinary claim → verification → finding →
 * decision flow, not a parallel one.
 *
 * The scenario every test starts from: the source says «Systemet sender automatisk varsel ved
 * feil.» and generation either rephrases it faithfully or appends an inferred effect («…, slik at
 * hendelser oppdages tidligere»). The first must cost the reviewer nothing; the second must reach a
 * person, be explained, and hold up publication until somebody keeps, edits or removes it.
 */
class EnterpriseWikiSourceBasedBlockVerificationTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use GrantsWikiPermissions;
    use RefreshDatabase;

    private const SOURCE_SENTENCE = 'Systemet sender automatisk varsel ved feil.';

    private const PARAPHRASE = 'Ved feil varsler systemet automatisk.';

    private const WITH_INFERENCE = 'Systemet sender automatisk varsel ved feil, slik at hendelser oppdages tidligere.';

    private const UNSUPPORTED_PART = 'slik at hendelser oppdages tidligere';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Source-based blocks never go through the extraction AI — only Procynia's own additions do.
        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');
    }

    // =========================================================================
    // A. Fully supported text
    // =========================================================================

    public function test_a_fully_supported_source_based_block_is_verified_without_creating_review_work(): void
    {
        $scenario = $this->scenario(self::PARAPHRASE);

        $this->mockVerifier($this->verificationResult(supportingSourceElementKeys: ['paragraph-1']));
        $this->extractAndVerify($scenario['run']);

        $claim = $this->onlyClaim($scenario['version']);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertSame(self::PARAPHRASE, $claim->claim_text, 'The whole block is the claim, never a fragment of it.');
        $this->assertNotNull($claim->verified_at);
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_FOUND, $claim->sourceStatus());
        $this->assertSame('paragraph-1', $claim->sourceReferences()->value('source_element_key'));
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $this->block($scenario['version'])['content_origin']);

        $payload = $this->showClaim($scenario['owner'], $scenario['page'], $claim);
        $this->assertFalse($payload['source_based_block_finding']);
        $this->assertNull($payload['finding_category']);

        $findings = $this->actingAs($scenario['owner'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings")->assertOk();
        $this->assertNull(collect($findings->json('findings'))->firstWhere('claim_id', $claim->id));

        $this->assertFalse(app(EnterpriseWikiClaimFindingExplainer::class)->blocksPublication($claim, $scenario['version']->fresh()));
    }

    public function test_a_verbatim_source_based_block_is_confirmed_without_an_ai_call(): void
    {
        $scenario = $this->scenario(self::SOURCE_SENTENCE);

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
        $this->extractAndVerify($scenario['run']);

        $claim = $this->onlyClaim($scenario['version']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertSame('deterministic_verbatim_match', $claim->review_metadata['classification_basis'] ?? null);
    }

    public function test_headings_and_deterministic_tables_are_not_claim_candidates(): void
    {
        $scenario = $this->scenario(self::PARAPHRASE);
        $blocks = $scenario['version']->content_blocks_json;
        $blocks[] = $this->sourceBlock('block-heading', 1, '## Varsling');
        $blocks[] = array_merge($this->sourceBlock('block-table', 2, '| A | B |'), ['block_type' => 'table']);
        $scenario['version']->update(['content_blocks_json' => $blocks]);

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($scenario['run']->fresh());

        $this->assertSame(['block-0001'], EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $scenario['version']->id)
            ->pluck('content_block_key')
            ->all());
    }

    // =========================================================================
    // B. An inferred effect appended to supported text
    // =========================================================================

    public function test_an_inferred_effect_becomes_a_visible_explained_finding(): void
    {
        $scenario = $this->scenario(self::WITH_INFERENCE);
        $verifiedText = null;

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturnUsing(function (string $claimText) use (&$verifiedText): array {
                $verifiedText = $claimText;

                return $this->partiallySupported();
            });

        $this->extractAndVerify($scenario['run']);

        $this->assertSame(self::WITH_INFERENCE, $verifiedText, 'The verifier must judge the complete assertion, including the appended clause.');

        $claim = $this->onlyClaim($scenario['version']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame('claim_partially_supported', $claim->generation_issue);
        $this->assertSame(self::UNSUPPORTED_PART, $claim->review_reason);
        $this->assertFalse($claim->hasSourceReference(), 'Not treated as fully source-based without a decision.');
        $this->assertTrue($claim->isPending());

        $payload = $this->showClaim($scenario['owner'], $scenario['page'], $claim);
        $this->assertTrue($payload['source_based_block_finding']);
        $this->assertTrue($payload['remove_requires_edit']);
        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $payload['finding_category']);
        $this->assertSame(self::UNSUPPORTED_PART, $payload['finding_explanation']);
        $this->assertTrue($payload['requires_decision']);

        $finding = collect($this->actingAs($scenario['owner'])
            ->getJson("/app/wiki/runs/{$scenario['run']->id}/findings")
            ->assertOk()
            ->json('findings'))
            ->firstWhere('claim_id', $claim->id);
        $this->assertNotNull($finding, 'The finding reaches the existing Funn panel.');

        $this->assertTrue(app(EnterpriseWikiClaimFindingExplainer::class)->blocksPublication($claim, $scenario['version']->fresh()));
    }

    public function test_a_wholly_unsupported_source_based_block_is_never_rescued_as_best_practice(): void
    {
        // Normative wording that would ordinarily qualify for the best_practice rescue.
        $scenario = $this->scenario('Det anbefales å teste varslingen kvartalsvis.');

        $this->mockVerifier($this->verificationResult(verdict: 'not_supported', reason: 'Kilden nevner ikke testing av varsling.'));
        $this->extractAndVerify($scenario['run']);

        $claim = $this->onlyClaim($scenario['version']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertTrue(app(EnterpriseWikiClaimFindingExplainer::class)->blocksPublication($claim, $scenario['version']->fresh()));
    }

    // =========================================================================
    // C. Keep the text
    // =========================================================================

    public function test_keeping_the_text_records_the_decision_and_leaves_the_block_alone(): void
    {
        $scenario = $this->verifiedInferenceScenario();
        $claim = $scenario['claim'];

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$claim->id}/approve", [
                'comment' => 'Effekten er faglig riktig.',
                // The editor is seeded with the block text; sending it unchanged is a plain keep.
                'approved_text' => self::WITH_INFERENCE,
            ])
            ->assertRedirect();

        $claim->refresh();
        $this->assertTrue($claim->isApproved());
        $this->assertSame((int) $scenario['owner']->id, (int) $claim->approved_by_user_id);

        $decision = EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->sole();
        $this->assertSame(EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS, $decision->decision_type);
        $this->assertSame(['approval_status' => 'pending'], $decision->previous_state);
        $this->assertSame(['approval_status' => 'approved'], $decision->new_state);
        $this->assertSame('Effekten er faglig riktig.', $decision->comment);

        $block = $this->block($scenario['version']->fresh());
        $this->assertSame(self::WITH_INFERENCE, $block['markdown']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $block['content_origin']);
        $this->assertFalse(app(EnterpriseWikiClaimFindingExplainer::class)->blocksPublication($claim, $scenario['version']->fresh()));
    }

    // =========================================================================
    // D. Edit and approve
    // =========================================================================

    public function test_editing_away_the_inference_rewrites_the_block_and_closes_the_finding(): void
    {
        $scenario = $this->verifiedInferenceScenario();
        $claim = $scenario['claim'];

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$claim->id}/approve", [
                'approved_text' => self::SOURCE_SENTENCE,
            ])
            ->assertRedirect();

        $version = $scenario['version']->fresh();
        $block = $this->block($version);

        $this->assertSame(self::SOURCE_SENTENCE, $block['markdown']);
        $this->assertStringContainsString(self::SOURCE_SENTENCE, $version->content_markdown);
        $this->assertStringNotContainsString(self::UNSUPPORTED_PART, $version->content_markdown);
        // The person took responsibility for the wording, exactly as ordinary editing records it.
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_HUMAN_AUTHORED, $block['content_origin']);
        $this->assertSame([], $block['source_elements']);

        $claim->refresh();
        $this->assertTrue($claim->isApproved());
        $this->assertSame(self::SOURCE_SENTENCE, $claim->claim_text);
        $this->assertTrue($claim->review_metadata['edited_before_approval'] ?? false);

        $decision = EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->sole();
        $this->assertSame(['approval_status' => 'pending', 'claim_text' => self::WITH_INFERENCE], $decision->previous_state);
        $this->assertSame(['approval_status' => 'approved', 'claim_text' => self::SOURCE_SENTENCE], $decision->new_state);

        $this->assertFalse(app(EnterpriseWikiClaimFindingExplainer::class)->blocksPublication($claim, $version));
        $this->assertFalse(app(EnterpriseWikiClaimFindingExplainer::class)->presentsUnsupportedTextAsSourceBased($claim, $version));
    }

    public function test_the_edited_text_is_not_sent_back_through_ai_verification(): void
    {
        $scenario = $this->verifiedInferenceScenario();

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$scenario['claim']->id}/approve", [
                'approved_text' => self::SOURCE_SENTENCE,
            ])
            ->assertRedirect();

        $this->assertSame(1, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $scenario['version']->id)->count());
    }

    public function test_removing_a_partially_supported_block_leads_to_editing_instead(): void
    {
        $scenario = $this->verifiedInferenceScenario();
        $claim = $scenario['claim'];

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$claim->id}/reject")
            ->assertStatus(422);

        $this->assertTrue($claim->fresh()->isPending());
        $this->assertSame(self::WITH_INFERENCE, $this->block($scenario['version']->fresh())['markdown']);
        $this->assertSame(0, EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
    }

    public function test_removing_a_wholly_unsupported_block_blanks_exactly_that_block(): void
    {
        $scenario = $this->scenario('Systemet har innebygget maskinlæring.');
        $this->mockVerifier($this->verificationResult(verdict: 'not_supported', reason: 'Kilden nevner ikke maskinlæring.'));
        $this->extractAndVerify($scenario['run']);
        $claim = $this->onlyClaim($scenario['version']);

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$claim->id}/reject")
            ->assertRedirect();

        $this->assertTrue($claim->fresh()->isRejected());
        $this->assertSame('', $this->block($scenario['version']->fresh())['markdown']);
        $this->assertFalse(app(EnterpriseWikiClaimFindingExplainer::class)->blocksPublication($claim->fresh(), $scenario['version']->fresh()));
    }

    // =========================================================================
    // E. Publication
    // =========================================================================

    public function test_an_unresolved_finding_holds_up_final_approval_until_it_is_decided(): void
    {
        $scenario = $this->verifiedInferenceScenario();
        $reviewer = $this->submitForReview($scenario);

        $this->actingAs($this->freshUser($reviewer))
            ->patch("/app/wiki/{$scenario['page']->slug}/approve")
            ->assertStatus(409);

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $scenario['page']->fresh()->status);
        $this->assertNull($scenario['page']->fresh()->published_version_id);

        $publication = $this->actingAs($this->freshUser($reviewer))
            ->get("/app/wiki/{$scenario['page']->slug}")
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame('unresolved_source_findings', $publication['review_assignment']['final_approval_blocker']);
        $this->assertContains(__('procynia.wiki.publication_blocker_unresolved_source_findings'), $publication['publication']['blocking_reasons']);

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$scenario['claim']->id}/approve", [
                'approved_text' => self::SOURCE_SENTENCE,
            ])
            ->assertRedirect();

        $this->actingAs($this->freshUser($reviewer))
            ->patch("/app/wiki/{$scenario['page']->slug}/approve")
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $scenario['page']->fresh()->status);
    }

    public function test_an_explicit_not_blocking_decision_lets_the_page_be_published(): void
    {
        $scenario = $this->verifiedInferenceScenario();
        $reviewer = $this->submitForReview($scenario);

        $this->actingAs($scenario['owner'])
            ->patch("/app/wiki/{$scenario['page']->slug}/claims/{$scenario['claim']->id}/blocking", [
                'blocking' => false,
                'comment' => 'Avklart med fagansvarlig.',
            ])
            ->assertRedirect();

        $this->actingAs($this->freshUser($reviewer))
            ->patch("/app/wiki/{$scenario['page']->slug}/approve")
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $scenario['page']->fresh()->status);
    }

    // =========================================================================
    // F. Best practice stays advisory
    // =========================================================================

    public function test_a_pending_best_practice_suggestion_does_not_hold_up_final_approval(): void
    {
        $scenario = $this->scenario(self::SOURCE_SENTENCE);
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
        $this->extractAndVerify($scenario['run']);

        $bestPractice = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $scenario['page']->id,
            'enterprise_wiki_page_version_id' => $scenario['version']->id,
            'claim_text' => 'Det anbefales å teste varslingen jevnlig.',
            'page_excerpt' => 'Det anbefales å teste varslingen jevnlig.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0001',
            'review_reason' => 'Procynia-anbefaling.',
            'position_order' => 5,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $reviewer = $this->submitForReview($scenario);

        $this->actingAs($this->freshUser($reviewer))
            ->patch("/app/wiki/{$scenario['page']->slug}/approve")
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $scenario['page']->fresh()->status);
        $this->assertTrue($bestPractice->fresh()->isPending());
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * @return array{customer: Customer, owner: User, document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun, page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion}
     */
    private function scenario(string $blockMarkdown): array
    {
        $customer = $this->createWikiCustomer('Varsling AS');
        $owner = $this->grantWikiPermissions($customer, User::query()->create([
            'name' => 'Sideeier',
            'email' => Str::lower(Str::random(10)).'@source-finding.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]));

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'driftsbeskrivelse.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => self::SOURCE_SENTENCE,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = $this->createWikiPage($customer, 'Varsling', ['owner_user_id' => $owner->id]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $blockMarkdown,
            'content_blocks_json' => [$this->sourceBlock('block-0001', 0, $blockMarkdown, $document)],
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        return compact('customer', 'owner', 'document', 'run', 'page', 'version');
    }

    /** @return array<string, mixed> */
    private function sourceBlock(string $key, int $position, string $markdown, ?EnterpriseWikiDocument $document = null): array
    {
        $sourceId = $document?->id ?? EnterpriseWikiDocument::query()->latest('id')->value('id');

        return [
            'block_key' => $key,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $sourceId,
            'source_elements' => [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $sourceId,
                'source_label' => 'driftsbeskrivelse.docx',
                'source_element_key' => 'paragraph-1',
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_excerpt' => self::SOURCE_SENTENCE,
                'page_reference' => 'Avsnitt 1',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedInferenceScenario(): array
    {
        $scenario = $this->scenario(self::WITH_INFERENCE);
        $this->mockVerifier($this->partiallySupported());
        $this->extractAndVerify($scenario['run']);
        $scenario['claim'] = $this->onlyClaim($scenario['version']);

        return $scenario;
    }

    /** Owner sends the page to a colleague who may approve Wiki pages; returns that colleague. */
    private function submitForReview(array $scenario): User
    {
        $reviewer = $this->grantWikiPermissions($scenario['customer'], User::query()->create([
            'name' => 'Kontrollør',
            'email' => Str::lower(Str::random(10)).'@source-finding.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $scenario['customer']->id,
            'is_active' => true,
        ]));

        $this->actingAs($scenario['owner'])->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);

        $this->actingAs($this->freshUser($scenario['owner']))
            ->patch("/app/wiki/{$scenario['page']->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $scenario['page']->fresh()->status);

        return $reviewer;
    }

    private function extractAndVerify(EnterpriseWikiIngestRun $run): void
    {
        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());
        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());
    }

    private function mockVerifier(array $result): void
    {
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($result);
    }

    private function partiallySupported(): array
    {
        return $this->verificationResult(
            verdict: 'partially_supported',
            supportingSourceElementKeys: ['paragraph-1'],
            reason: 'Kilden sier at systemet varsler automatisk ved feil, men ikke noe om at hendelser oppdages tidligere.',
            unsupportedParts: self::UNSUPPORTED_PART,
        );
    }

    private function onlyClaim(EnterpriseWikiPageVersion $version): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->sole();
    }

    /** @return array<string, mixed> */
    private function block(EnterpriseWikiPageVersion $version): array
    {
        return collect($version->content_blocks_json)->firstWhere('block_key', 'block-0001');
    }

    /** @return array<string, mixed> */
    private function showClaim(User $user, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): array
    {
        $claims = $this->actingAs($this->freshUser($user))
            ->get("/app/wiki/{$page->slug}")
            ->assertOk()
            ->viewData('page')['props']['claims'];

        return collect($claims)->firstWhere('id', $claim->id);
    }

    private function freshUser(User $user): User
    {
        return User::query()->with('customer')->findOrFail($user->id);
    }
}
