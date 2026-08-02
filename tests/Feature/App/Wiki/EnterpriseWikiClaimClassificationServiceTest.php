<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Direct tests of EnterpriseWikiClaimClassificationService — the single authoritative gate for
 * EnterpriseWikiClaim.content_origin transitions. verified_at is the reused authority marker (no
 * new column): null means only a provisional classification exists (any source may write); a
 * non-null timestamp means an authoritative decision exists, which only SOURCE_VERIFICATION and
 * SOURCE_MANUAL_REVERIFICATION may then change.
 */
class EnterpriseWikiClaimClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EnterpriseWikiClaimClassificationService
    {
        return app(EnterpriseWikiClaimClassificationService::class);
    }

    // =========================================================================
    // Authority gate
    // =========================================================================

    public function test_a_source_may_set_the_first_classification_on_a_never_verified_claim(): void
    {
        $claim = $this->createClaim(['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, 'verified_at' => null]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Generelt.',
            'review_metadata' => ['classification_basis' => 'normative_language'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $fresh->content_origin);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame(EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, $fresh->review_metadata['decision_source'] ?? null);
    }

    public function test_a_non_privileged_source_cannot_reclassify_an_authoritative_claim(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'verified_at' => now(),
            'review_metadata' => ['classification_basis' => 'normative_language', 'decision_source' => 'verification'],
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'review_metadata' => null,
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_REJECTED_AUTHORITATIVE, $outcome['result']);
        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $fresh->content_origin);
        $this->assertSame('verification', $fresh->review_metadata['decision_source'] ?? null);
    }

    public function test_manual_reverification_may_override_an_authoritative_claim(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => now()->subDay(),
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_MANUAL_REVERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'review_metadata' => ['classification_basis' => 'scoped_run_reevaluation'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
    }

    public function test_confirming_an_authoritative_claim_with_the_same_content_origin_is_already_correct_and_does_not_touch_decision_source(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'verified_at' => now()->subHour(),
            'review_metadata' => ['decision_source' => 'verification'],
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'review_metadata' => null,
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_ALREADY_CORRECT, $outcome['result']);
        $fresh = $claim->fresh();
        $this->assertSame('verification', $fresh->review_metadata['decision_source'] ?? null);
    }

    // =========================================================================
    // Structural fields (content_block_key) vs. content classification
    // =========================================================================

    public function test_content_block_key_can_still_be_corrected_when_content_reclassification_is_rejected(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'verified_at' => now(),
            'content_block_key' => 'block-0001',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'content_block_key' => 'block-0002',
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $fresh = $claim->fresh();
        // Structural anchor corrected...
        $this->assertSame('block-0002', $fresh->content_block_key);
        // ...but the authoritative classification itself was NOT touched.
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $fresh->content_origin);
    }

    public function test_transition_to_internal_error_is_never_gated_by_authority(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'verified_at' => now(),
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'review_reason' => null,
            'review_metadata' => null,
            'generation_issue' => 'claim_not_tied_to_current_page_version',
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, $claim->fresh()->content_origin);
    }

    public function test_transition_away_from_internal_error_is_never_gated_by_authority(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'verified_at' => now(),
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'review_metadata' => null,
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    public function test_applying_the_same_proposal_twice_is_idempotent_and_does_not_churn_verified_at(): void
    {
        $claim = $this->createClaim(['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, 'verified_at' => null]);

        $proposal = [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'review_metadata' => null,
            'generation_issue' => null,
        ];

        $first = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $proposal);
        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $first['result']);
        $verifiedAtAfterFirst = $claim->fresh()->verified_at;
        $this->assertNotNull($verifiedAtAfterFirst);

        $second = $this->service()->apply($claim->fresh(), EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $proposal);
        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_ALREADY_CORRECT, $second['result']);
        $this->assertSame(
            $verifiedAtAfterFirst->format('Y-m-d H:i:s.u'),
            $claim->fresh()->verified_at->format('Y-m-d H:i:s.u'),
        );
    }

    // =========================================================================
    // Staleness / concurrency
    // =========================================================================

    public function test_expected_verified_at_mismatch_is_reported_stale_and_does_not_write(): void
    {
        $claim = $this->createClaim(['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, 'verified_at' => now()->subMinute()]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'expected_verified_at' => now()->subDay(),
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_STALE, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
    }

    public function test_classify_claim_wrapper_uses_its_own_transaction_and_lock(): void
    {
        $claim = $this->createClaim(['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, 'verified_at' => null]);

        $outcome = $this->service()->classifyClaim($claim->id, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'x',
            'review_metadata' => null,
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
    }

    public function test_classify_claim_reports_not_found_for_a_missing_claim(): void
    {
        $outcome = $this->service()->classifyClaim(999999999, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_NOT_FOUND, $outcome['result']);
        $this->assertNull($outcome['claim']);
    }

    public function test_would_be_rejected_as_authoritative_previews_without_writing(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'verified_at' => now(),
        ]);

        $this->assertTrue($this->service()->wouldBeRejectedAsAuthoritative(
            $claim,
            EnterpriseWikiClaimClassificationService::SOURCE_INTEGRITY_REPAIR,
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ));
        $this->assertFalse($this->service()->wouldBeRejectedAsAuthoritative(
            $claim,
            EnterpriseWikiClaimClassificationService::SOURCE_MANUAL_REVERIFICATION,
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ));
        // Never wrote anything.
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
    }

    // =========================================================================
    // Finding #5646: a best_practice proposal for a pure navigation-only claim is resolved to
    // CONTENT_ORIGIN_UNCLASSIFIED instead — the one, central point every proposal passes through,
    // regardless of source.
    // =========================================================================

    public function test_pure_navigation_reference_is_resolved_to_unclassified_not_best_practice(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'For detaljert flyt og rollebeskrivelser, se [[incident-management-illustrasjon-3f9a1|Illustrasjon av Incident Management]].',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'review_reason' => 'Cross-reference to the finished article, per maintainer note.',
            'review_metadata' => ['classification_basis' => 'ai_block_content_origin'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED, $fresh->content_origin);
        $this->assertNotNull($fresh->verified_at, 'Still an authoritative decision — just not best_practice.');
        $this->assertNull($fresh->review_reason);
        $this->assertSame('navigation_reference_only', $fresh->review_metadata['classification_basis'] ?? null);
        $this->assertSame(EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $fresh->review_metadata['decision_source'] ?? null);
    }

    public function test_english_pure_navigation_reference_is_resolved_to_unclassified(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'See [[itil-incident-management|ITIL Incident Management]] for details.',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Cross-reference.',
            'review_metadata' => ['classification_basis' => 'ai_block_content_origin'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED, $claim->fresh()->content_origin);
    }

    /**
     * When page_excerpt is unavailable, the claim's own anchored block's markdown (identified by
     * content_block_key) is used as a fallback — block-level structural metadata is still
     * consulted when it is the only signal on hand.
     */
    public function test_block_markdown_is_used_as_fallback_anchor_when_page_excerpt_is_empty(): void
    {
        $customer = $this->createCustomer();
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'page-'.Str::lower(Str::random(8)),
            'title' => 'Test Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Test Page\n\nSe [[itil-incident-management|ITIL Incident Management]].",
            'content_blocks_json' => [[
                'block_key' => 'block-0002',
                'position' => 1,
                'markdown' => 'Se [[itil-incident-management|ITIL Incident Management]].',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'best_practice_reason' => 'Cross-reference.',
                'link_intents' => [
                    ['target_slug' => 'itil-incident-management', 'reason' => 'Points the reader to the owning page.'],
                ],
            ]],
            'generated_by_model' => 'gpt-5',
        ]);
        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Se ITIL Incident Management.',
            'page_excerpt' => null,
            'content_block_key' => 'block-0002',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Cross-reference.',
            'review_metadata' => ['classification_basis' => 'ai_block_content_origin'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED, $claim->fresh()->content_origin);
    }

    public function test_professional_claim_before_a_wiki_reference_still_becomes_best_practice(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'Incident Management skal ha tydelig sakseierskap. Se [[itil-incident-management|ITIL Incident Management]].',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Genuine recommendation.',
            'review_metadata' => ['classification_basis' => 'normative_language'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
        $this->assertSame('Genuine recommendation.', $claim->fresh()->review_reason);
    }

    public function test_professional_claim_after_a_wiki_reference_still_becomes_best_practice(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'Se [[itil-incident-management|ITIL Incident Management]]. Incident Management skal alltid ha én tydelig sakseier.',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Genuine recommendation.',
            'review_metadata' => ['classification_basis' => 'normative_language'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
    }

    public function test_professional_claim_in_the_same_sentence_as_the_reference_still_becomes_best_practice(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'Normal tjenesteleveranse skal gjenopprettes så raskt som mulig; se [[itil-incident-management|ITIL Incident Management]].',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Genuine recommendation.',
            'review_metadata' => ['classification_basis' => 'normative_language'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
    }

    public function test_short_text_without_a_wikilink_is_unaffected_by_the_navigation_check(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'Se dokumentasjonen.',
        ]);

        $outcome = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Genuine recommendation.',
            'review_metadata' => ['classification_basis' => 'normative_language'],
            'generation_issue' => null,
        ]);

        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
    }

    public function test_repeated_application_of_the_same_navigation_proposal_is_idempotent(): void
    {
        $claim = $this->createClaim([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'verified_at' => null,
            'page_excerpt' => 'Se [[itil-incident-management|ITIL Incident Management]].',
        ]);

        $proposal = [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Cross-reference.',
            'review_metadata' => ['classification_basis' => 'ai_block_content_origin'],
            'generation_issue' => null,
        ];

        $first = $this->service()->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $proposal);
        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_APPLIED, $first['result']);
        $verifiedAtAfterFirst = $claim->fresh()->verified_at;

        $second = $this->service()->apply($claim->fresh(), EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $proposal);
        $this->assertSame(EnterpriseWikiClaimClassificationService::RESULT_ALREADY_CORRECT, $second['result']);
        $this->assertSame(
            $verifiedAtAfterFirst->format('Y-m-d H:i:s.u'),
            $claim->fresh()->verified_at->format('Y-m-d H:i:s.u'),
        );
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED, $claim->fresh()->content_origin);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createClaim(array $overrides = []): EnterpriseWikiClaim
    {
        $customer = $this->createCustomer();
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'page-'.Str::lower(Str::random(8)),
            'title' => 'Test Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Test Page\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'A test claim.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $overrides));
    }

    private function createCustomer(string $name = 'Classification Test AS'): Customer
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
}
