<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunDismissalService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Deleting a source document, cancelling a run and clearing a run from Kjøringer are checked in the
 * services themselves, not only in the controllers (docs/external-api-phase-0-plan.md §4.4, step 5):
 * a person of the customer, with wiki.source.manage, for whom the document is theirs to delete.
 */
class WikiSourceAdministrationAuthorityTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    public function test_a_document_owner_without_source_management_is_refused_by_every_service(): void
    {
        ['customer' => $customer, 'document' => $document, 'run' => $run] = $this->scenario();
        // Owns the document, may read the Wiki, but does not administer its sources.
        $owner = $this->user($customer, [CustomerPermissionCatalog::WIKI_VIEW]);
        $document->forceFill(['owner_user_id' => $owner->id])->save();

        foreach ($this->actions($document->fresh(), $run) as $name => $action) {
            try {
                $action($owner);
                $this->fail("{$name} must refuse a user without wiki.source.manage");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->fresh()->status);
    }

    public function test_source_management_without_the_document_is_refused(): void
    {
        ['customer' => $customer, 'document' => $document, 'run' => $run] = $this->scenario();
        // Administers sources, but this document is somebody else's.
        $manager = $this->user($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);

        foreach ($this->actions($document, $run) as $name => $action) {
            try {
                $action($manager);
                $this->fail("{$name} must refuse a user the document does not belong to");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_integration_is_refused_whatever_it_holds(): void
    {
        ['customer' => $customer, 'document' => $document, 'run' => $run] = $this->scenario();
        $integration = $this->user($customer, null, User::BID_ROLE_SYSTEM_OWNER);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        foreach ($this->actions($document, $run) as $name => $action) {
            try {
                $action($integration->fresh());
                $this->fail("{$name} must refuse an integration");
            } catch (HumanDecisionRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, callable(User): mixed> */
    private function actions(EnterpriseWikiDocument $document, EnterpriseWikiIngestRun $run): array
    {
        return [
            'delete document' => fn (User $actor) => app(EnterpriseWikiDocumentDeletionService::class)->delete($document, $actor),
            'cancel run' => fn (User $actor) => app(EnterpriseWikiDocumentFlowService::class)->cancelRun($run, $actor),
            'dismiss run' => fn (User $actor) => app(EnterpriseWikiRunDismissalService::class)->dismiss($run, $actor),
        ];
    }

    /** @return array{customer: Customer, document: EnterpriseWikiDocument, run: EnterpriseWikiIngestRun} */
    private function scenario(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Kildeadministrasjon AS',
            'slug' => 'kildeadm-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'policy.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Policy.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
        ]);

        return compact('customer', 'document', 'run');
    }

    /** @param list<string>|null $permissions */
    private function user(Customer $customer, ?array $permissions, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
        ]), $permissions);
    }
}
