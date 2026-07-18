<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual claim source approval (WikiClaimController::approve()/unapprove()) — a System Owner
 * can approve a claim that has no source reference, and undo that approval. Reuses the same
 * access control as WikiController::approve()/reject() (System Owner only).
 */
class WikiClaimControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // Access control
    // =========================================================================

    public function test_system_owner_can_approve_claim_manually(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/approve",
            ['comment' => 'Bekreftet muntlig av kunden.'],
        );

        $response->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isApproved());
        $this->assertSame($owner->id, $fresh->approved_by_user_id);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('Bekreftet muntlig av kunden.', $fresh->approval_comment);
    }

    public function test_system_owner_can_edit_and_approve_best_practice_claim(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, $version, $claim] = $this->createPageWithClaim($customer);

        $originalText = 'Virksomheten bør gjennomføre en årlig tilgangsgjennomgang.';
        $editedText = 'Virksomheten bør gjennomføre dokumentert tilgangsgjennomgang minst årlig.';

        $version->update([
            'content_markdown' => "# Claim Page\n\n{$originalText}",
        ]);

        $claim->update([
            'claim_text' => $originalText,
            'page_excerpt' => $originalText,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'review_reason' => 'Beste praksis uten kildegrunnlag.',
        ]);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/approve",
            [
                'approved_text' => $editedText,
                'comment' => 'Presisert formulering.',
            ],
        );

        $response->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertStringContainsString($editedText, $version->fresh()->content_markdown);
        $this->assertStringNotContainsString($originalText, $version->fresh()->content_markdown);

        $claim->refresh();
        $this->assertTrue($claim->isApproved());
        $this->assertSame($editedText, $claim->claim_text);
        $this->assertSame($editedText, $claim->page_excerpt);
        $this->assertSame('Presisert formulering.', $claim->approval_comment);
    }

    public function test_bid_manager_cannot_approve_claim(): void
    {
        $customer = $this->createCustomer();
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($bidManager)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve");

        $response->assertForbidden();
        $this->assertFalse($claim->fresh()->isApproved());
    }

    public function test_contributor_cannot_approve_claim(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($contributor)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve");

        $response->assertForbidden();
        $this->assertFalse($claim->fresh()->isApproved());
    }

    public function test_contributor_cannot_reject_claim(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($contributor)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/reject");

        $response->assertForbidden();
        $this->assertTrue($claim->fresh()->isPending());
    }

    public function test_bid_manager_cannot_unapprove_claim(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => null]);

        $response = $this->actingAs($bidManager)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove");

        $response->assertForbidden();
        $this->assertTrue($claim->fresh()->isApproved());
    }

    // =========================================================================
    // QA capability (additive — combined with an ordinary role, never replacing it)
    // =========================================================================

    public function test_qa_user_can_approve_claim_manually(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($qaContributor)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/approve",
            ['comment' => 'Kvalitetssikret av QA.'],
        );

        $response->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isApproved());
        $this->assertSame($qaContributor->id, $fresh->approved_by_user_id);
        $this->assertSame('Kvalitetssikret av QA.', $fresh->approval_comment);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_document_owner_can_approve_and_undo_claim_when_claim_ties_back_to_own_document(): void
    {
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $documentOwner->id])->save();
        $this->createDocumentSourceReference($claim, $document, 'Eierens dokument.');

        $this->actingAs($documentOwner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => 'Godkjent av dokumenteier.'])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isApproved());
        $this->assertSame($documentOwner->id, $fresh->approved_by_user_id);
        $this->assertSame('Godkjent av dokumenteier.', $fresh->approval_comment);

        $this->actingAs($documentOwner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isPending());
        $this->assertNull($fresh->approved_by_user_id);
    }

    public function test_document_owner_can_reject_claim_manually(): void
    {
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $documentOwner->id])->save();
        $this->createDocumentSourceReference($claim, $document, 'Eierens dokument.');

        $this->actingAs($documentOwner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/reject", ['comment' => 'Avvist av dokumenteier.'])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isRejected());
        $this->assertSame($documentOwner->id, $fresh->approved_by_user_id);
        $this->assertSame('Avvist av dokumenteier.', $fresh->approval_comment);
    }

    public function test_qa_user_can_undo_approval(): void
    {
        $customer = $this->createCustomer();
        $qaBidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, isQa: true);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($qaBidManager)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => null]);
        $this->assertTrue($claim->fresh()->isApproved());

        $response = $this->actingAs($qaBidManager)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove");

        $response->assertRedirect(route('app.wiki.show', $page->slug));
        $this->assertFalse($claim->fresh()->isApproved());
    }

    public function test_bid_manager_with_qa_can_approve_claim(): void
    {
        $customer = $this->createCustomer();
        $qaBidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, isQa: true);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($qaBidManager)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertTrue($claim->fresh()->isApproved());
    }

    public function test_contributor_with_qa_can_approve_claim(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($qaContributor)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertTrue($claim->fresh()->isApproved());
    }

    public function test_unauthorized_approval_attempt_does_not_change_any_audit_field(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: false);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($contributor)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/approve",
            ['comment' => 'Forsøk uten tilgang.'],
        )->assertForbidden();

        $fresh = $claim->fresh();
        $this->assertFalse($fresh->isApproved());
        $this->assertNull($fresh->approved_by_user_id);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approval_comment);
    }

    public function test_qa_user_from_another_customer_cannot_approve_claim(): void
    {
        $customer = $this->createCustomer('Eier AS');
        $otherCustomer = $this->createCustomer('Fremmed AS');
        $qaContributor = $this->createUser($otherCustomer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($qaContributor)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve")
            ->assertNotFound();

        $this->assertFalse($claim->fresh()->isApproved());
    }

    // =========================================================================
    // Warning removal / restoration
    // =========================================================================

    public function test_manual_approval_removes_missing_source_warning(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => null]);

        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);
        $this->assertFalse($claim->fresh()->needsSourceWarning());
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_MANUALLY_APPROVED, $claim->fresh()->sourceStatus());
    }

    public function test_undo_approval_restores_warning_when_source_still_missing(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => null]);
        $this->assertFalse($claim->fresh()->needsSourceWarning());

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove");

        $fresh = $claim->fresh();
        $this->assertFalse($fresh->isApproved());
        $this->assertTrue($fresh->needsSourceWarning());
        $this->assertTrue(
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->exists(),
        );
    }

    public function test_undo_approval_reopens_previously_resolved_finding(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => null]);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove");

        $this->assertSame(EnterpriseWikiLintFinding::STATUS_OPEN, $finding->fresh()->status);
        $this->assertSame(
            1,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
                ->count(),
        );
    }

    public function test_document_owner_can_link_source_to_claim_without_source_when_page_uses_owned_document(): void
    {
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, $version, $claimWithSource] = $this->createPageWithClaim($customer);
        $claimWithoutSource = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Mangler kilde, men hører til samme side.',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $documentOwner->id, 'extracted_text' => 'Dokumenttekst.'])->save();
        $this->createDocumentSourceReference($claimWithSource, $document, 'Eierens dokument.');

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => false,
                    'manual_source_allowed' => true,
                    'manual_source_reason' => 'Dokumentet har ikke strukturerte elementer.',
                    'elements' => [],
                ]);

            $mock->shouldReceive('resolveSelection')->never();
        });

        $this->actingAs($documentOwner)->post(
            "/app/wiki/{$page->slug}/claims/{$claimWithoutSource->id}/source-references",
            [
                'source_document_id' => $document->id,
                'source_element_type' => 'manual',
                'excerpt' => 'Manuelt kilderutdrag.',
                'page_reference' => 'Side 2',
            ],
        )->assertRedirect(route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claimWithoutSource->id]));

        $this->assertDatabaseHas('enterprise_wiki_source_references', [
            'enterprise_wiki_claim_id' => $claimWithoutSource->id,
            'source_id' => $document->id,
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL,
            'excerpt' => 'Manuelt kilderutdrag.',
        ]);
    }

    public function test_document_owner_can_fetch_source_elements_for_owned_document(): void
    {
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $documentOwner->id, 'extracted_text' => 'Dokumenttekst.'])->save();
        $this->createDocumentSourceReference($claim, $document, 'Eierens dokument.');

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => true,
                    'manual_source_allowed' => false,
                    'manual_source_reason' => 'Dokumentet har strukturerte elementer.',
                    'elements' => [
                        [
                            'source_element_key' => 'paragraph-1',
                            'source_element_type' => 'paragraph',
                            'source_row_key' => null,
                            'page_reference' => 'Avsnitt 1.1',
                            'reference_text' => 'Kildeelement.',
                            'display_text' => 'Kildeelement.',
                        ],
                    ],
                ]);
        });

        $this->actingAs($documentOwner)
            ->getJson("/app/wiki/{$page->slug}/claims/{$claim->id}/source-documents/{$document->id}/elements")
            ->assertOk()
            ->assertJsonPath('elements.0.source_element_key', 'paragraph-1');
    }

    public function test_document_owner_cannot_approve_claim_tied_only_to_foreign_document(): void
    {
        $customer = $this->createCustomer();
        $documentOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $foreignOwner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $foreignDocument = $this->createDocument($customer);
        $foreignDocument->forceFill(['owner_user_id' => $foreignOwner->id])->save();
        $this->createDocumentSourceReference($claim, $foreignDocument, 'Fremmed dokument.');

        $this->actingAs($documentOwner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", ['comment' => 'Skal ikke være mulig.'])
            ->assertForbidden();

        $this->assertTrue($claim->fresh()->isPending());
    }

    // =========================================================================
    // Source linking
    // =========================================================================

    public function test_system_owner_can_link_source_to_claim_and_resolve_missing_source_warning(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($owner)->post(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/source-references",
            [
                'source_document_id' => $document->id,
                'excerpt' => 'Dokumentet viser at påstanden er korrekt.',
                'page_reference' => 'Avsnitt 2.1',
            ],
        );

        $response->assertRedirect(route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id]));

        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_FOUND, $fresh->sourceStatus());
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('source_id', $document->id)
            ->first();

        $this->assertNotNull($reference);
        $this->assertSame($document->original_filename, $reference->source_label);
        $this->assertSame('Dokumentet viser at påstanden er korrekt.', $reference->excerpt);
        $this->assertSame('Avsnitt 2.1', $reference->page_reference);
    }

    public function test_system_owner_can_link_structured_source_element_to_claim(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);
        $document = $this->createDocument($customer);
        $document->forceFill(['extracted_text' => 'Dokumenttekst.'])->save();

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock) use ($document): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => true,
                    'manual_source_allowed' => false,
                    'manual_source_reason' => 'Dokumentet har strukturerte kildeelementer.',
                    'elements' => [
                        [
                            'source_element_key' => 'paragraph-0',
                            'source_element_type' => 'paragraph',
                            'source_row_key' => null,
                            'page_reference' => 'Avsnitt 2.1',
                            'reference_text' => 'Påstanden er dokumentert i dette avsnittet.',
                            'display_text' => 'Påstanden er dokumentert i dette avsnittet.',
                        ],
                    ],
                ]);

            $mock->shouldReceive('resolveSelection')
                ->once()
                ->with(
                    \Mockery::on(static fn ($arg): bool => $arg instanceof EnterpriseWikiDocument && $arg->id === $document->id),
                    'paragraph-0',
                    'paragraph',
                    null,
                )
                ->andReturn([
                    'source_element_key' => 'paragraph-0',
                    'source_element_type' => 'paragraph',
                    'source_row_key' => null,
                    'page_reference' => 'Avsnitt 2.1',
                    'reference_text' => 'Påstanden er dokumentert i dette avsnittet.',
                    'display_text' => 'Påstanden er dokumentert i dette avsnittet.',
                ]);
        });

        $response = $this->actingAs($owner)->post(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/source-references",
            [
                'source_document_id' => $document->id,
                'source_element_key' => 'paragraph-0',
                'source_element_type' => 'paragraph',
            ],
        );

        $response->assertRedirect(route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id]));

        $freshClaim = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $freshClaim->approval_status);
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_FOUND, $freshClaim->sourceStatus());
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('source_id', $document->id)
            ->first();

        $this->assertNotNull($reference);
        $this->assertSame('paragraph-0', $reference->source_element_key);
        $this->assertSame('paragraph', $reference->source_element_type);
        $this->assertNull($reference->source_row_key);
        $this->assertSame('Påstanden er dokumentert i dette avsnittet.', $reference->excerpt);
        $this->assertSame('Avsnitt 2.1', $reference->page_reference);
        $this->assertNotEmpty($reference->source_hash);
    }

    public function test_system_owner_can_link_manual_source_excerpt_only_when_document_has_no_structured_elements(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);
        $document->forceFill(['extracted_text' => 'Dokumenttekst.'])->save();

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => false,
                    'manual_source_allowed' => true,
                    'manual_source_reason' => 'Dokumentet har ikke strukturerte kildeelementer.',
                    'elements' => [],
                ]);

            $mock->shouldReceive('resolveSelection')->never();
        });

        $response = $this->actingAs($owner)->post(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/source-references",
            [
                'source_document_id' => $document->id,
                'excerpt' => 'Manuelt dokumentert utdrag.',
                'page_reference' => 'Side 4',
                'source_element_type' => 'manual',
            ],
        );

        $response->assertRedirect(route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id]));

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('source_id', $document->id)
            ->first();

        $this->assertNotNull($reference);
        $this->assertSame(EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL, $reference->source_element_type);
        $this->assertNull($reference->source_element_key);
        $this->assertNull($reference->source_row_key);
        $this->assertSame('Manuelt dokumentert utdrag.', $reference->excerpt);
        $this->assertSame('Side 4', $reference->page_reference);
    }

    public function test_invalid_structured_source_element_does_not_resolve_missing_source_warning(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);
        $document = $this->createDocument($customer);
        $document->forceFill(['extracted_text' => 'Dokumenttekst.'])->save();

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock) use ($document): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => true,
                    'manual_source_allowed' => false,
                    'manual_source_reason' => 'Dokumentet har strukturerte kildeelementer.',
                    'elements' => [],
                ]);

            $mock->shouldReceive('resolveSelection')
                ->once()
                ->with(
                    \Mockery::on(static fn ($arg): bool => $arg instanceof EnterpriseWikiDocument && $arg->id === $document->id),
                    'paragraph-9',
                    'paragraph',
                    null,
                )
                ->andReturnNull();
        });

        $response = $this->actingAs($owner)->post(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/source-references",
            [
                'source_document_id' => $document->id,
                'source_element_key' => 'paragraph-9',
                'source_element_type' => 'paragraph',
            ],
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('enterprise_wiki_source_references', [
            'enterprise_wiki_claim_id' => $claim->id,
            'source_id' => $document->id,
        ]);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_OPEN, $finding->fresh()->status);
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_MISSING, $claim->fresh()->sourceStatus());
    }

    public function test_source_element_endpoint_returns_structured_elements(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);
        $document->forceFill(['extracted_text' => 'Dokumenttekst.'])->save();

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => true,
                    'manual_source_allowed' => false,
                    'manual_source_reason' => 'Dokumentet har strukturerte kildeelementer.',
                    'elements' => [
                        [
                            'source_element_key' => 'listitem-0',
                            'source_element_type' => 'list_item',
                            'source_row_key' => null,
                            'page_reference' => 'Avsnitt 1.2',
                            'reference_text' => 'Første listepunkt.',
                            'display_text' => 'Første listepunkt.',
                        ],
                    ],
                ]);
        });

        $response = $this->actingAs($owner)->getJson("/app/wiki/{$page->slug}/claims/{$claim->id}/source-documents/{$document->id}/elements");

        $response->assertOk();
        $response->assertJsonPath('supports_structured_elements', true);
        $response->assertJsonPath('manual_source_allowed', false);
        $response->assertJsonPath('elements.0.source_element_key', 'listitem-0');
        $response->assertJsonPath('elements.0.source_element_type', 'list_item');
        $response->assertJsonPath('elements.0.reference_text', 'Første listepunkt.');
    }

    public function test_linking_first_real_source_resets_existing_manual_decision_to_pending(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
            'approval_comment' => 'Tidligere godkjent uten kilde.',
        ]);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);
        $document = $this->createDocument($customer);
        $document->forceFill(['extracted_text' => 'Dokumenttekst.'])->save();

        $this->mock(EnterpriseWikiDocumentSourceElementService::class, function ($mock) use ($document): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->andReturn([
                    'supports_structured_elements' => true,
                    'manual_source_allowed' => false,
                    'manual_source_reason' => 'Dokumentet har strukturerte kildeelementer.',
                    'elements' => [
                        [
                            'source_element_key' => 'paragraph-1',
                            'source_element_type' => 'paragraph',
                            'source_row_key' => null,
                            'page_reference' => 'Avsnitt 3.4',
                            'reference_text' => 'Ny dokumentasjon.',
                            'display_text' => 'Ny dokumentasjon.',
                        ],
                    ],
                ]);

            $mock->shouldReceive('resolveSelection')
                ->once()
                ->with(
                    \Mockery::on(static fn ($arg): bool => $arg instanceof EnterpriseWikiDocument && $arg->id === $document->id),
                    'paragraph-1',
                    'paragraph',
                    null,
                )
                ->andReturn([
                    'source_element_key' => 'paragraph-1',
                    'source_element_type' => 'paragraph',
                    'source_row_key' => null,
                    'page_reference' => 'Avsnitt 3.4',
                    'reference_text' => 'Ny dokumentasjon.',
                    'display_text' => 'Ny dokumentasjon.',
                ]);
        });

        $this->actingAs($owner)->post(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/source-references",
            [
                'source_document_id' => $document->id,
                'source_element_key' => 'paragraph-1',
                'source_element_type' => 'paragraph',
            ],
        );

        $freshClaim = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $freshClaim->approval_status);
        $this->assertNull($freshClaim->approved_by_user_id);
        $this->assertNull($freshClaim->approved_at);
        $this->assertNull($freshClaim->approval_comment);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);
    }

    public function test_contributor_cannot_link_source_to_claim(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);

        $this->actingAs($contributor)
            ->post("/app/wiki/{$page->slug}/claims/{$claim->id}/source-references", [
                'source_document_id' => $document->id,
                'excerpt' => 'Dokumentert.',
            ])
            ->assertForbidden();
    }

    public function test_other_customer_cannot_link_source_to_claim(): void
    {
        $customer = $this->createCustomer('Eier AS');
        $otherCustomer = $this->createCustomer('Fremmed AS');
        $owner = $this->createUser($otherCustomer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $document = $this->createDocument($customer);

        $this->actingAs($owner)
            ->post("/app/wiki/{$page->slug}/claims/{$claim->id}/source-references", [
                'source_document_id' => $document->id,
                'excerpt' => 'Dokumentert.',
            ])
            ->assertNotFound();
    }

    // =========================================================================
    // Guards
    // =========================================================================

    public function test_system_owner_can_approve_claim_that_already_has_source_reference(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => 'existing.pdf',
            'excerpt' => 'Eksisterende utdrag.',
        ]);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/approve",
            ['comment' => 'Godkjent på tross av at kilden allerede finnes.'],
        );

        $response->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isApproved());
        $this->assertSame('Godkjent på tross av at kilden allerede finnes.', $fresh->approval_comment);
        $this->assertSame($owner->id, $fresh->approved_by_user_id);
    }

    public function test_system_owner_can_reject_claim_manually(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);
        $finding = $this->createOpenMissingSourceFinding($customer, $page, $claim);

        $response = $this->actingAs($owner)->patch(
            "/app/wiki/{$page->slug}/claims/{$claim->id}/reject",
            ['comment' => 'Påstanden ble avvist av fagansvarlig.'],
        );

        $response->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isRejected());
        $this->assertSame($owner->id, $fresh->approved_by_user_id);
        $this->assertSame('Påstanden ble avvist av fagansvarlig.', $fresh->approval_comment);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);
    }

    public function test_unapprove_rejects_claim_that_is_not_approved(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove");

        $response->assertStatus(422);
    }

    public function test_unapprove_resets_rejected_claim_back_to_pending(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/reject", ['comment' => null]);

        $response = $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/unapprove");

        $response->assertRedirect(route('app.wiki.show', $page->slug));

        $fresh = $claim->fresh();
        $this->assertTrue($fresh->isPending());
        $this->assertNull($fresh->approved_by_user_id);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approval_comment);
    }

    public function test_approve_rejects_other_customers_claim(): void
    {
        $customer = $this->createCustomer('Eier AS');
        $other = $this->createCustomer('Fremmed AS');
        $owner = $this->createUser($other, User::BID_ROLE_SYSTEM_OWNER);
        [$page, , $claim] = $this->createPageWithClaim($customer);

        $response = $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve");

        $response->assertNotFound();
        $this->assertFalse($claim->fresh()->isApproved());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Wiki Claim Test AS'): Customer
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

    private function createUser(Customer $customer, string $bidRole, bool $isQa = false): User
    {
        return User::query()->create([
            'name' => 'Wiki Claim Tester',
            'email' => Str::lower(Str::random(8)).'@wiki-claim-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'is_qa' => $isQa,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion, 2: EnterpriseWikiClaim}
     */
    private function createPageWithClaim(Customer $customer): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'claim-page-'.Str::lower(Str::random(6)),
            'title' => 'Claim Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Claim Page',
            'generated_by_model' => 'gpt-5',
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Advania er leverandør av IT-driftstjenester.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        return [$page, $version, $claim];
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'uploaded_by_user_id' => null,
            'owner_user_id' => null,
            'original_filename' => 'source-document.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(12).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Dette er et testutdrag som dokumenterer påstanden.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createDocumentSourceReference(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiDocument $document,
        ?string $excerpt = null,
    ): EnterpriseWikiSourceReference {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'excerpt' => $excerpt,
            'page_reference' => null,
        ]);
    }

    private function createOpenMissingSourceFinding(
        Customer $customer,
        EnterpriseWikiPage $page,
        EnterpriseWikiClaim $claim,
    ): EnterpriseWikiLintFinding {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'enterprise_wiki_document_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Claim has no source reference.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);
    }
}
