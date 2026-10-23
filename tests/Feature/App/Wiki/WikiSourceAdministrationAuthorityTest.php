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
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionFailureRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageGenerationFailureRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunDismissalService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Deleting a source document, cancelling or retrying a run and clearing a run from Kjøringer are
 * checked in the services themselves, not only in the controllers (docs/external-api-phase-0-plan.md
 * §4.4, step 5, and A2 in step 20): a person of the customer, with wiki.source.manage, for whom the
 * document is theirs to delete. Uploading, starting a run and naming a document owner are checked
 * there too (A2): a person of the customer, and for the last two wiki.source.manage.
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

    /** Upload, «Start» and «Dokumenteier» refuse an integration and a request through the API. */
    public function test_upload_start_and_owner_change_refuse_an_integration_and_the_api(): void
    {
        ['customer' => $customer, 'document' => $document] = $this->scenario();
        $integration = $this->user($customer, null, User::BID_ROLE_SYSTEM_OWNER);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $systemOwner = $this->user($customer, null, User::BID_ROLE_SYSTEM_OWNER);

        foreach ($this->sourceActions($document) as $name => $action) {
            $this->assertRefusedAs(HumanDecisionRefused::class, fn () => $action($integration->fresh()), "{$name}: integration");
        }

        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        foreach ($this->sourceActions($document) as $name => $action) {
            $this->assertRefusedAs(HumanDecisionRefused::class, fn () => $action($systemOwner), "{$name}: API");
        }

        $this->assertSame(0, EnterpriseWikiIngestRun::query()->where('source_id', $document->id)->where('status', '!=', EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL)->count());
        $this->assertNull($document->fresh()->owner_user_id);
    }

    /** A System Owner of another customer reaches none of them, and nothing is written. */
    public function test_a_person_of_another_customer_is_refused(): void
    {
        ['document' => $document] = $this->scenario();
        ['customer' => $otherCustomer] = $this->scenario();
        $stranger = $this->user($otherCustomer, null, User::BID_ROLE_SYSTEM_OWNER);

        foreach ($this->sourceActions($document) as $name => $action) {
            $this->assertRefusedAs(AuthorizationException::class, fn () => $action($stranger), $name);
        }

        $this->assertNull($document->fresh()->owner_user_id);
    }

    /** «Start» and «Dokumenteier» need wiki.source.manage; reading the Wiki is not enough. */
    public function test_start_and_owner_change_need_source_management(): void
    {
        ['customer' => $customer, 'document' => $document] = $this->scenario();
        $reader = $this->user($customer, [CustomerPermissionCatalog::WIKI_VIEW]);

        Queue::fake();
        $this->assertRefusedAs(AuthorizationException::class, fn () => app(EnterpriseWikiDocumentFlowService::class)->startForDocumentAs($reader, $document), 'start');
        $this->assertRefusedAs(AuthorizationException::class, fn () => app(EnterpriseWikiDocumentFlowService::class)->changeDocumentOwner($reader, $document, null), 'owner');
        Queue::assertNothingPushed();
    }

    /**
     * The owner a person names is checked in the service: an integration and a person of another
     * customer are never made owner, and naming someone else needs the right to assign owners.
     */
    public function test_the_named_owner_is_checked_in_the_service(): void
    {
        ['customer' => $customer, 'document' => $document] = $this->scenario();
        ['customer' => $otherCustomer] = $this->scenario();
        $systemOwner = $this->user($customer, null, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, null, User::BID_ROLE_SYSTEM_OWNER);
        $integration = $this->user($customer, null, User::BID_ROLE_SYSTEM_OWNER);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $stranger = $this->user($otherCustomer, null, User::BID_ROLE_SYSTEM_OWNER);
        $flow = app(EnterpriseWikiDocumentFlowService::class);

        foreach (['integration' => $integration, 'other customer' => $stranger] as $who => $owner) {
            $this->assertRefusedAs(ValidationException::class, fn () => $flow->changeDocumentOwner($systemOwner, $document, $owner->id), $who);
        }
        $this->assertNull($document->fresh()->owner_user_id);

        $flow->changeDocumentOwner($systemOwner, $document, $colleague->id);
        $this->assertSame($colleague->id, (int) $document->fresh()->owner_user_id);

        // The upload service applies the same rule to the owner named with a file.
        $file = UploadedFile::fake()->createWithContent('kilde.pdf', 'x');
        $this->assertRefusedAs(ValidationException::class, fn () => app(EnterpriseWikiDocumentUploadService::class)->store($customer->id, $file, $integration->id, $systemOwner), 'upload owner');

        // Someone who may not assign owners names only themselves.
        $manager = $this->user($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);
        $this->assertFalse($manager->canAssignEnterpriseWikiDocumentOwner());
        $this->assertRefusedAs(AuthorizationException::class, fn () => app(EnterpriseWikiDocumentUploadService::class)->store($customer->id, $file, $colleague->id, $manager), 'upload names another');
    }

    /** A person with wiki.source.manage starts a run through the service. */
    public function test_a_source_manager_starts_a_run(): void
    {
        ['customer' => $customer] = $this->scenario();
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'ny.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Ny kilde.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $manager = $this->user($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);

        Queue::fake();
        $prepared = app(EnterpriseWikiDocumentFlowService::class)->startForDocumentAs($manager, $document);

        $this->assertTrue($prepared['created']);
        $this->assertSame($document->id, (int) $prepared['run']->source_id);
    }

    /** @return array<string, callable(User): mixed> */
    private function sourceActions(EnterpriseWikiDocument $document): array
    {
        return [
            'upload' => fn (User $actor) => app(EnterpriseWikiDocumentUploadService::class)->store((int) $document->customer_id, UploadedFile::fake()->createWithContent('kilde.pdf', 'x'), null, $actor),
            'start run' => fn (User $actor) => app(EnterpriseWikiDocumentFlowService::class)->startForDocumentAs($actor, $document),
            'change owner' => fn (User $actor) => app(EnterpriseWikiDocumentFlowService::class)->changeDocumentOwner($actor, $document, null),
        ];
    }

    /** @param class-string<\Throwable> $expected */
    private function assertRefusedAs(string $expected, callable $action, string $label): void
    {
        try {
            $action();
            $this->fail("{$label} must be refused");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($expected, $e, $label.': '.$e->getMessage());
        }
    }

    /** @return array<string, callable(User): mixed> */
    private function actions(EnterpriseWikiDocument $document, EnterpriseWikiIngestRun $run): array
    {
        return [
            'delete document' => fn (User $actor) => app(EnterpriseWikiDocumentDeletionService::class)->delete($document, $actor),
            'cancel run' => fn (User $actor) => app(EnterpriseWikiDocumentFlowService::class)->cancelRun($run, $actor),
            'dismiss run' => fn (User $actor) => app(EnterpriseWikiRunDismissalService::class)->dismiss($run, $actor),
            'retry decision phase' => fn (User $actor) => app(EnterpriseWikiMaintainerDecisionFailureRecoveryService::class)->retryAs($actor, $run),
            'retry failed pages' => fn (User $actor) => app(EnterpriseWikiPageGenerationFailureRecoveryService::class)->retryAs($actor, $run),
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
