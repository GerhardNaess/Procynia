<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Support\Str;

/**
 * Shared Enterprise Wiki page/link/claim fixture builders for the Fase 9 Wiki-research test
 * suite (RequirementWikiCatalogBuilderTest, RequirementWikiPageRankerTest,
 * RequirementWikiLinkNavigatorTest, RequirementWikiPageReaderTest,
 * RequirementWikiResearchServiceTest, RequirementWikiAnswerServiceTest, and the controller test).
 * Mirrors the exact fixture shape already established in EnterpriseWikiBacklinksControllerTest.
 */
trait CreatesEnterpriseWikiFixtures
{
    protected function createWikiCustomer(string $name = 'Wiki Research Test AS'): Customer
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
            'is_active' => true,
        ]);
    }

    protected function createWikiPage(Customer $customer, string $title, array $overrides = []): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create(array_merge([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ], $overrides));
    }

    /**
     * A page that counts as current, usable customer knowledge — which since the document-owner
     * gate means both an eligible page status AND a current version its document owners have
     * signed off on. Pass $withDocumentOwnerApproval = false to build a page nobody has signed
     * yet (a page the requirement-answer engine must refuse to read).
     */
    protected function createWikiPageWithVersion(
        Customer $customer,
        string $title,
        string $markdown,
        array $pageOverrides = [],
        array $versionOverrides = [],
        bool $withDocumentOwnerApproval = true,
    ): EnterpriseWikiPage {
        $page = $this->createWikiPage($customer, $title, $pageOverrides);

        $version = EnterpriseWikiPageVersion::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
        ], $versionOverrides));

        if ($withDocumentOwnerApproval && ($version->is_current ?? false)) {
            $this->approveWikiPageVersionAsDocumentOwner($version);

            // Retrieval reads enterprise_wiki_pages.published_version_id, so a fixture that means
            // "this page is available as knowledge" has to publish the version. Document-owner
            // sign-off is what permits publication; it is no longer the retrieval signal itself.
            $page->forceFill(['published_version_id' => $version->id])->save();
        }

        return $page->refresh();
    }

    /**
     * Materialize one settled document-owner approval row for a page version — the signal
     * RequirementWikiCatalogBuilder treats as "this current version is approved".
     */
    protected function approveWikiPageVersionAsDocumentOwner(
        EnterpriseWikiPageVersion $version,
        string $approvalStatus = EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED,
    ): EnterpriseWikiPageVersionDocumentOwnerApproval {
        $page = $version->relationLoaded('page') ? $version->page : $version->page()->first();

        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $page->customer_id,
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'document_owner_user_id' => null,
            'source_document_ids' => [],
            'source_documents_hash' => Str::random(64),
            'approval_status' => $approvalStatus,
            'decided_at' => $approvalStatus === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING ? null : now(),
        ]);
    }

    protected function createWikilink(Customer $customer, EnterpriseWikiPage $from, EnterpriseWikiPage $to): EnterpriseWikiPageLink
    {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }

    protected function createWikiClaim(EnterpriseWikiPage $page, string $claimText, array $overrides = []): EnterpriseWikiClaim
    {
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();

        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $claimText,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
        ], $overrides));
    }

    /**
     * A fully-shaped WikiClaimVerificationAiClient::verifyClaim() result — the post-validation
     * shape EnterpriseWikiVerifyPageClaimsService/EnterpriseWikiClaimSourceReconciliationService
     * consume, for tests that mock the AI client directly rather than the OpenAI HTTP layer.
     * Defaults to a clean "supported" verdict with every check matching; override just the
     * fields a given test cares about.
     *
     * @param  list<string>  $supportingSourceElementKeys
     * @param  array<string, string>  $checkOverrides
     */
    protected function verificationResult(
        string $verdict = 'supported',
        array $supportingSourceElementKeys = [],
        string $reason = 'Claim matches the cited source excerpt.',
        string $unsupportedParts = '',
        array $checkOverrides = [],
    ): array {
        $checks = array_merge([
            'actor' => 'match',
            'action' => 'match',
            'object' => 'match',
            'modality' => 'match',
            'negation' => 'match',
            'numbers_and_units' => 'match',
            'time_and_date' => 'match',
            'scope' => 'match',
            'conditions_and_exceptions' => 'not_applicable',
            'subject_entity' => 'match',
        ], $checkOverrides);

        return [
            'verdict' => $verdict,
            'same_meaning_across_languages' => true,
            'claim_language' => 'no',
            'source_language' => 'no',
            'supporting_source_element_keys' => $supportingSourceElementKeys,
            'reason' => $reason,
            'unsupported_parts' => $unsupportedParts,
            'checks' => $checks,
        ];
    }
}
