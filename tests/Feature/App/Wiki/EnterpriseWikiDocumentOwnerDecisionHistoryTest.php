<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocumentOwnerDecision;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval as Approval;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A document owner's decision is never overwritten without a trace — decision B9,
 * docs/external-api-phase-0-plan.md §4.10.
 *
 * Every decision is an event in enterprise_wiki_document_owner_decisions (who, when, from what, to
 * what, why); the approval row keeps the current state. The same decision again writes nothing; a
 * change of mind needs a reason and is a new event; the database refuses to change or delete the
 * history.
 */
class EnterpriseWikiDocumentOwnerDecisionHistoryTest extends TestCase
{
    use GrantsWikiPermissions;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_every_decision_is_an_event_and_a_change_of_mind_needs_a_reason(): void
    {
        ['approval' => $approval, 'owner' => $owner] = $this->requirement();
        $service = app(EnterpriseWikiDocumentOwnerApprovalService::class);

        $service->decide($approval, $owner, Approval::APPROVAL_STATUS_APPROVED);

        $first = $approval->decisions()->sole();
        $this->assertSame(Approval::APPROVAL_STATUS_PENDING, $first->previous_status);
        $this->assertSame(Approval::APPROVAL_STATUS_APPROVED, $first->decision);
        $this->assertSame((int) $owner->id, $first->decided_by_user_id);
        $this->assertNotNull($first->decided_at);

        // The same decision again is not news.
        $service->decide($approval->fresh(), $owner, Approval::APPROVAL_STATUS_APPROVED);
        $this->assertSame(1, $approval->decisions()->count());

        // Changing it without a reason is refused, and nothing changes.
        try {
            $service->decide($approval->fresh(), $owner, Approval::APPROVAL_STATUS_REJECTED);
            $this->fail('a change of mind must need a reason');
        } catch (ValidationException) {
        }
        $this->assertSame(Approval::APPROVAL_STATUS_APPROVED, $approval->fresh()->approval_status);
        $this->assertSame(1, $approval->decisions()->count());

        // With a reason it is a new event; the first one stays as it was.
        $service->decide($approval->fresh(), $owner, Approval::APPROVAL_STATUS_REJECTED, 'Kilden er revidert siden jeg godkjente.');

        $history = $approval->decisions()->get();
        $this->assertCount(2, $history);
        $this->assertSame([Approval::APPROVAL_STATUS_APPROVED, Approval::APPROVAL_STATUS_REJECTED], $history->pluck('decision')->all());
        $this->assertSame(Approval::APPROVAL_STATUS_APPROVED, $history[1]->previous_status);
        $this->assertSame('Kilden er revidert siden jeg godkjente.', $history[1]->comment);
        $this->assertSame(Approval::APPROVAL_STATUS_REJECTED, $approval->fresh()->approval_status);
    }

    public function test_only_the_document_owner_or_a_system_owner_decides_and_never_a_superseded_requirement(): void
    {
        ['approval' => $approval, 'customer' => $customer] = $this->requirement();
        $service = app(EnterpriseWikiDocumentOwnerApprovalService::class);
        $someoneElse = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        try {
            $service->decide($approval, $someoneElse, Approval::APPROVAL_STATUS_APPROVED);
            $this->fail('only the document owner may decide');
        } catch (AuthorizationException) {
        }

        $approval->forceFill(['superseded_at' => now()])->save();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        try {
            $service->decide($approval->fresh(), $systemOwner, Approval::APPROVAL_STATUS_APPROVED);
            $this->fail('a superseded requirement is history');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, EnterpriseWikiDocumentOwnerDecision::query()->where('approval_id', $approval->id)->count());
        $this->assertSame(Approval::APPROVAL_STATUS_PENDING, $approval->fresh()->approval_status);
    }

    public function test_an_integration_never_decides(): void
    {
        ['approval' => $approval, 'customer' => $customer] = $this->requirement();
        $integration = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $this->expectException(HumanDecisionRefused::class);
        app(EnterpriseWikiDocumentOwnerApprovalService::class)->decide($approval, $integration->fresh(), Approval::APPROVAL_STATUS_APPROVED);
    }

    public function test_the_history_cannot_be_changed_or_deleted_but_goes_with_its_requirement(): void
    {
        ['approval' => $approval, 'owner' => $owner] = $this->requirement();
        app(EnterpriseWikiDocumentOwnerApprovalService::class)->decide($approval, $owner, Approval::APPROVAL_STATUS_APPROVED);
        $event = $approval->decisions()->sole();

        $this->assertRefused(fn () => DB::table('enterprise_wiki_document_owner_decisions')->where('id', $event->id)->update(['decision' => 'rejected']));
        $this->assertRefused(fn () => DB::table('enterprise_wiki_document_owner_decisions')->where('id', $event->id)->update(['comment' => 'Omskrevet']));
        $this->assertRefused(fn () => DB::table('enterprise_wiki_document_owner_decisions')->where('id', $event->id)->delete());

        // The requirement's own deletion (a deleted page or version cascades) takes its history along.
        DB::table('enterprise_wiki_page_version_document_owner_approvals')->where('id', $approval->id)->delete();
        $this->assertSame(0, DB::table('enterprise_wiki_document_owner_decisions')->where('approval_id', $approval->id)->count());
    }

    /** Over HTTP: the same refusal twice returns the page once, and the history holds one decision. */
    public function test_repeating_a_refusal_over_http_sends_the_page_back_once(): void
    {
        ['approval' => $approval, 'owner' => $owner, 'page' => $page] = $this->requirement();
        $url = "/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/reject";

        $this->actingAs($owner)->patch($url, ['comment' => 'Kilden er gjengitt feil.'])->assertRedirect();
        $this->actingAs($owner)->patch($url, ['comment' => 'Kilden er gjengitt feil.'])->assertRedirect();

        $this->assertSame(1, EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $page->id)->count());
        $this->assertSame(1, $approval->decisions()->count());
    }

    /**
     * The service itself returns the version on a new refusal, so no caller of decide() can record
     * a refusal and leave the page waiting in review on nobody (phase 0 step 20, §2m).
     */
    public function test_a_refusal_through_the_service_alone_returns_the_version_to_its_owner(): void
    {
        ['approval' => $approval, 'owner' => $owner, 'page' => $page] = $this->requirement();

        app(EnterpriseWikiDocumentOwnerApprovalService::class)->decide($approval, $owner, Approval::APPROVAL_STATUS_REJECTED, 'Kilden er gjengitt feil.');

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
        $event = EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $page->id)->sole();
        $this->assertSame(EnterpriseWikiPageReviewEvent::EVENT_TYPE_CHANGES_REQUESTED, $event->event_type);
        $this->assertSame(EnterpriseWikiPageReviewEvent::ACTOR_ROLE_DOCUMENT_OWNER, $event->actor_role);
        $this->assertSame($owner->id, (int) $event->actor_user_id);
        $this->assertSame('Kilden er gjengitt feil.', $event->reason);
        $this->assertSame((int) $approval->enterprise_wiki_page_version_id, (int) $event->enterprise_wiki_page_version_id);

        // An approval returns nothing.
        ['approval' => $other, 'owner' => $otherOwner, 'page' => $otherPage] = $this->requirement();
        app(EnterpriseWikiDocumentOwnerApprovalService::class)->decide($other, $otherOwner, Approval::APPROVAL_STATUS_APPROVED);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $otherPage->fresh()->status);
        $this->assertSame(0, EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $otherPage->id)->count());
    }

    private function assertRefused(callable $statement): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail('the database must refuse this statement');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{customer: Customer, owner: User, page: EnterpriseWikiPage, approval: Approval} */
    private function requirement(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Dokumenteier AS',
            'slug' => 'dokumenteier-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'kildeside-'.Str::lower(Str::random(6)),
            'title' => 'Kildeside',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Kildeside',
        ]);

        $approval = Approval::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'document_owner_user_id' => $owner->id,
            'source_document_ids' => [1],
            'source_documents_hash' => hash('sha256', '1'),
            'approval_status' => Approval::APPROVAL_STATUS_PENDING,
        ]);

        return ['customer' => $customer, 'owner' => $owner, 'page' => $page, 'approval' => $approval];
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
        ]));
    }
}
