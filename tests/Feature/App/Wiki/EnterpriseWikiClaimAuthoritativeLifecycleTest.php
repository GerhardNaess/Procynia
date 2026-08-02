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
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimClassificationService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimIntegrityRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunBestPracticeReevaluationService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage for the claim-classification authority fix: a claim goes through the REAL
 * extraction, verification, integrity-repair, and best-practice-reevaluation services (only the
 * two AI clients are mocked — no service in the chain is stubbed out), confirming EnterpriseWikiClaim
 * ends up with exactly one authoritative content_origin that repair/reevaluation cannot silently
 * override once verification has decided it.
 */
class EnterpriseWikiClaimAuthoritativeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The documented bug scenario, step by step:
     *  1. Extraction inherits content_origin=source_based from the block and creates a source
     *     reference (provisional only — verified_at stays null).
     *  2. Verification's own AI verdict is not_supported, but the claim's wording is a genuine,
     *     general recommendation — it is deliberately rescued to best_practice (verified_at now
     *     set: this IS the claim's authoritative decision).
     *  3. The source reference extraction created is NOT deleted — it remains as historical
     *     provenance — but no longer proves the claim is source_based.
     *  4. wiki:repair-claim-integrity's structural-integrity rule would, on its own, read "this
     *     claim has a source reference" and reclassify it back to source_based — but the claim
     *     is already authoritative, so the classification service rejects the reclassification and
     *     the claim stays best_practice.
     *  5. wiki:reevaluate-run-best-practice-claims does not touch it either (it's already
     *     best_practice, not unsupported_generated_content, so it's out of that command's scope
     *     anyway — but this test drives it for real to prove that too, not just assume it).
     */
    public function test_full_lifecycle_extraction_verification_rescue_survives_repair_and_reevaluation(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $blockMarkdown = 'Illustrasjonen viser hvordan Kunde og Leverandør samhandler gjennom Incident-prosessen.';
        [$page, $version] = $this->createPageWithSourceBasedBlock($customer, $run, $blockMarkdown);

        $recommendationText = 'Regelmessig gjennomgang av eskaleringsrutiner reduserer risiko for forsinket håndtering.';

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn(['claims' => [
                ['text' => $recommendationText, 'confidence' => 'uncertain', 'excerpt' => $blockMarkdown, 'conflict_note' => null],
            ]]);

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());

        $claim = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->firstOrFail();

        // --- Step 1: provisional, inherited from the source_based block ---
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertNull($claim->verified_at, 'Extraction must never mark a claim as authoritatively decided.');
        $extractionReferenceId = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->value('id');
        $this->assertNotNull($extractionReferenceId, 'A source_based-inherited claim gets a source reference at extraction time.');

        // --- Step 2: verification's own AI call says not_supported; rescued to best_practice ---
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn([
                'verdict' => 'not_supported',
                'same_meaning_across_languages' => true,
                'claim_language' => 'no',
                'source_language' => 'no',
                'supporting_source_element_keys' => [],
                'reason' => 'Kilden beskriver ikke en generell anbefaling om eskaleringsrutiner.',
                'unsupported_parts' => '',
                'checks' => [
                    'actor' => 'no_claim', 'action' => 'no_claim', 'object' => 'no_claim',
                    'modality' => 'match', 'negation' => 'match', 'numbers_and_units' => 'not_applicable',
                    'time_and_date' => 'not_applicable', 'scope' => 'match',
                    'conditions_and_exceptions' => 'not_applicable', 'subject_entity' => 'match',
                ],
            ]);

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $verified = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $verified->content_origin);
        $this->assertNotNull($verified->verified_at, 'This is the claim\'s authoritative decision.');
        $this->assertSame('normative_language', $verified->review_metadata['classification_basis'] ?? null);
        // Task rule 7: the not_supported verdict and the best_practice decision are BOTH recorded
        // explicitly, on the same row — never a silent, inferable-only combination.
        $this->assertSame('not_supported', $verified->review_metadata['verification_verdict'] ?? null);
        $this->assertSame(EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $verified->review_metadata['decision_source'] ?? null);

        // --- Step 3: the extraction-time source reference is untouched, still there as provenance ---
        $this->assertTrue(
            EnterpriseWikiSourceReference::query()->whereKey($extractionReferenceId)->exists(),
            'A source reference is historical provenance and must never be deleted just to make the classification look consistent.',
        );

        // --- Step 4: repair must not use "a reference exists" to override the authoritative decision ---
        $repairCounts = app(EnterpriseWikiClaimIntegrityRepairService::class)->repair($customer->id, apply: true);
        $this->assertSame(1, $repairCounts['authoritative_kept']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
        $this->assertSame(EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, $claim->fresh()->review_metadata['decision_source'] ?? null);

        // --- Step 5 (task test 2): reevaluation does not touch it either ---
        $reevalResult = app(EnterpriseWikiRunBestPracticeReevaluationService::class)->reevaluate($run->fresh(), apply: true);
        $this->assertSame(0, $reevalResult['reclassified']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);

        // --- Invariant (task test 12): the claim's sole authoritative classification is traceable
        // to exactly one stored decision — verified_at set, and review_metadata explains it fully.
        $final = $claim->fresh();
        $this->assertNotNull($final->verified_at);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $final->content_origin);
        $this->assertSame('normative_language', $final->review_metadata['classification_basis'] ?? null);
    }

    /**
     * Task test 3: a genuinely supported, source-grounded claim must remain source_based after
     * BOTH repair and reevaluation — the fix must not make repair/reevaluation reject legitimate,
     * unchanged confirmations.
     */
    public function test_supported_source_based_claim_survives_repair_and_reevaluation_unchanged(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $blockMarkdown = 'Servicedesk Alfa er tilgjengelig mandag til fredag fra klokken 08.00 til 16.00.';
        [$page, $version] = $this->createPageWithSourceBasedBlock($customer, $run, $blockMarkdown);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn(['claims' => [
                // A single word swapped ("tilgjengelig" -> "åpen"), every number/actor/date kept
                // byte-identical to the source — different enough that verify()'s deterministic
                // verbatim-match fast path never fires (so the mocked AI below is genuinely
                // exercised), but with nothing for the deterministic safety net's own
                // actor/number/date conflict checks to flag either.
                ['text' => 'Servicedesk Alfa er åpen mandag til fredag fra klokken 08.00 til 16.00.', 'confidence' => 'high', 'excerpt' => $blockMarkdown, 'conflict_note' => null],
            ]]);

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());
        $claim = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->firstOrFail();

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn([
                'verdict' => 'supported',
                'same_meaning_across_languages' => true,
                'claim_language' => 'no',
                'source_language' => 'no',
                'supporting_source_element_keys' => ['paragraph-0'],
                'reason' => 'Claim matches the cited source excerpt.',
                'unsupported_parts' => '',
                'checks' => [
                    'actor' => 'match', 'action' => 'match', 'object' => 'match',
                    'modality' => 'match', 'negation' => 'match', 'numbers_and_units' => 'match',
                    'time_and_date' => 'match', 'scope' => 'match',
                    'conditions_and_exceptions' => 'not_applicable', 'subject_entity' => 'match',
                ],
            ]);

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
        $this->assertNotNull($claim->fresh()->verified_at);

        app(EnterpriseWikiClaimIntegrityRepairService::class)->repair($customer->id, apply: true);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);

        app(EnterpriseWikiRunBestPracticeReevaluationService::class)->reevaluate($run->fresh(), apply: true);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
    }

    /**
     * Task test 4 (repair-flavored, complementing the reevaluation-flavored version already
     * covered in EnterpriseWikiRunBestPracticeReevaluationCommandTest): a claim genuinely,
     * authoritatively verified as unsupported_generated_content — a contradicted verdict, no
     * rescue applicable — must not be flipped to source_based by repair's weaker
     * "a source reference exists" rule, even if a stale reference happens to still be attached.
     */
    public function test_authoritatively_unsupported_claim_is_not_reclassified_by_repair_even_with_a_stale_reference(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        // The block never mentions escalation levels at all — the AI-extracted claim below is a
        // hallucinated addition, not present in the source, which is exactly why the mocked AI
        // verdict below is "contradicted". Also deliberately NOT verbatim-identical to the claim
        // text, so verify()'s deterministic verbatim-match fast path never fires and the mocked
        // AI client below is the one actually exercised.
        $blockMarkdown = 'Figuren illustrerer samhandlingen mellom Kunde og Leverandør i Incident-prosessen.';
        [$page, $version] = $this->createPageWithSourceBasedBlock($customer, $run, $blockMarkdown);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn(['claims' => [
                ['text' => 'Kunden har fem godkjente eskaleringsnivåer definert i sin styringsmodell.', 'confidence' => 'high', 'excerpt' => $blockMarkdown, 'conflict_note' => null],
            ]]);

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());
        $claim = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->firstOrFail();
        // The stale reference extraction created for the (pre-verification) source_based origin.
        $this->assertTrue(EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->exists());

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn([
                'verdict' => 'contradicted',
                'same_meaning_across_languages' => true,
                'claim_language' => 'no',
                'source_language' => 'no',
                'supporting_source_element_keys' => [],
                'reason' => 'Kilden nevner ingen eskaleringsnivåer i det hele tatt.',
                'unsupported_parts' => 'fem godkjente eskaleringsnivåer',
                'checks' => [
                    'actor' => 'match', 'action' => 'mismatch', 'object' => 'mismatch',
                    'modality' => 'match', 'negation' => 'match', 'numbers_and_units' => 'mismatch',
                    'time_and_date' => 'not_applicable', 'scope' => 'match',
                    'conditions_and_exceptions' => 'not_applicable', 'subject_entity' => 'match',
                ],
            ]);

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
        $this->assertNotNull($claim->fresh()->verified_at);

        $repairCounts = app(EnterpriseWikiClaimIntegrityRepairService::class)->repair($customer->id, apply: true);

        $this->assertSame(1, $repairCounts['authoritative_kept']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
    }

    /**
     * Task test 5: an explicit, sporbar reverification (wiki:reevaluate-run-claim-verification's
     * underlying service call) CAN change an authoritative decision — this is the one sanctioned
     * path — and the change is fully traceable.
     */
    public function test_explicit_reverification_can_change_an_authoritative_decision_traceably(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $blockMarkdown = 'Brukerstøtte registrerer og prioriterer alle innkommende henvendelser.';
        [$page, $version] = $this->createPageWithSourceBasedBlock($customer, $run, $blockMarkdown);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            // A single word swapped ("alle" -> "samtlige"), actor/action/object otherwise
            // byte-identical to the source — different enough that reevaluateClaimForRun()'s
            // deterministic verbatim-match fast path never fires (so the mocked AI below is
            // genuinely exercised), but with nothing for the deterministic safety net's own
            // actor/action/object conflict checks to flag either.
            'claim_text' => 'Brukerstøtte registrerer og prioriterer samtlige innkommende henvendelser.',
            'page_excerpt' => $blockMarkdown,
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now()->subDay(),
            'review_metadata' => ['classification_basis' => 'semantic_verification', 'verdict' => 'not_supported'],
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn([
                'verdict' => 'supported',
                'same_meaning_across_languages' => true,
                'claim_language' => 'no',
                'source_language' => 'no',
                'supporting_source_element_keys' => ['paragraph-0'],
                'reason' => 'Claim matches the cited source excerpt.',
                'unsupported_parts' => '',
                'checks' => [
                    'actor' => 'match', 'action' => 'match', 'object' => 'match',
                    'modality' => 'match', 'negation' => 'match', 'numbers_and_units' => 'not_applicable',
                    'time_and_date' => 'not_applicable', 'scope' => 'match',
                    'conditions_and_exceptions' => 'not_applicable', 'subject_entity' => 'match',
                ],
            ]);

        $report = app(EnterpriseWikiVerifyPageClaimsService::class)->reevaluateClaimForRun($claim, $run->fresh(), apply: true);

        $this->assertTrue($report['applied']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $report['new_content_origin']);

        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $fresh->content_origin);
        $this->assertSame(EnterpriseWikiClaimClassificationService::SOURCE_MANUAL_REVERIFICATION, $fresh->review_metadata['decision_source'] ?? null);
        $this->assertSame($run->id, $fresh->review_metadata['reevaluated_run_id'] ?? null);
        $this->assertSame(
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            $fresh->review_metadata['reevaluated_from_content_origin'] ?? null,
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Lifecycle Test AS'): Customer
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
            'extracted_text' => 'Illustrasjonen viser hvordan Kunde og Leverandør samhandler gjennom Incident-prosessen.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion}
     */
    private function createPageWithSourceBasedBlock(Customer $customer, EnterpriseWikiIngestRun $run, string $blockMarkdown): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'page-'.Str::lower(Str::random(8)),
            'title' => 'Incident Management Illustration',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Incident Management Illustration\n\n{$blockMarkdown}",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $blockMarkdown,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_type' => 'enterprise_wiki_document',
                'source_id' => $run->source_id,
                'source_label' => 'source.pdf',
                'source_hash' => str_pad('a', 64, '0'),
                'source_element_key' => 'paragraph-0',
                'source_element_type' => 'paragraph',
                'source_elements' => [[
                    'source_id' => $run->source_id,
                    'source_label' => 'source.pdf',
                    'source_hash' => str_pad('a', 64, '0'),
                    'source_element_key' => 'paragraph-0',
                    'source_element_type' => 'paragraph',
                    'source_excerpt' => $blockMarkdown,
                    'page_reference' => 'Løpende tekst',
                ]],
                'best_practice_reason' => null,
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        return [$page, $version];
    }
}
