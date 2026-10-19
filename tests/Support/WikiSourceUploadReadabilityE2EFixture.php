<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Test-only helper for the Wiki source-upload readability E2E spec
 * (tests/e2e/wiki-source-upload-readability.spec.js). The upload checks need no seed — the form is
 * always present on the Kildedokumenter tab — and cleanup() removes the one real upload afterward.
 * The document-list checks seed their own states (seedListStates): a failed run and a user who may
 * only read the Wiki, both removed by cleanupListStates().
 */
class WikiSourceUploadReadabilityE2EFixture
{
    private const UPLOAD_FILENAME = 'e2e-source-upload-readability.pdf';

    private const FAILED_FILENAME = 'e2e-source-list-failed-run.docx';

    /** A backend error as the pipeline writes it — never meant to be read in the document list. */
    public const FAILED_RUN_ERROR = 'RuntimeException: Page generation failed for 1 of 2 planned pages (concept:supplier-follow-up) after 3 attempts; upstream returned HTTP 502 Bad Gateway';

    public const READER_EMAIL = 'e2e.wikisources.reader@procynia.test';

    public const READER_PASSWORD = 'e2e-wiki-sources-reader';

    private const READER_ROLE = 'E2E Wiki lesetilgang (kildedokumenter)';

    /**
     * The uploaded stub is not a real PDF, so extraction fails and the row offers no «Lag Wiki».
     * Marking it extracted gives the row the state a real upload ends in, without a real document.
     */
    public static function markExtracted(int $customerId): void
    {
        EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('original_filename', self::UPLOAD_FILENAME)
            ->update([
                'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
                'extracted_text' => 'E2E readability check content',
            ]);
    }

    /**
     * A document whose latest run failed with a long English backend error, and a user in the same
     * customer who may read the Wiki but not manage its sources.
     */
    public static function seedListStates(int $customerId): void
    {
        self::cleanupListStates($customerId);

        DB::transaction(function () use ($customerId): void {
            $document = EnterpriseWikiDocument::query()->create([
                'customer_id' => $customerId,
                'original_filename' => self::FAILED_FILENAME,
                'file_path' => 'e2e-source-list/'.Str::random(8).'.docx',
                'file_hash_sha256' => hash('sha256', Str::random(32)),
                'extracted_text' => 'E2E source list check content.',
                'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            ]);

            EnterpriseWikiIngestRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_hash' => hash('sha256', 'e2e-source-list-failed'),
                'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => self::FAILED_RUN_ERROR,
                'started_at' => now()->subMinutes(5),
                'finished_at' => now(),
            ]);

            $customer = Customer::query()->findOrFail($customerId);
            $role = CustomerRole::query()->create([
                'customer_id' => $customerId,
                'name' => self::READER_ROLE,
                'is_active' => true,
            ]);
            $role->syncPermissions([CustomerPermissionCatalog::WIKI_VIEW]);

            $reader = User::query()->create([
                'name' => 'E2E Wiki-leser',
                'email' => self::READER_EMAIL,
                'password' => Hash::make(self::READER_PASSWORD),
                'role' => User::customerRoleForBidRole(User::BID_ROLE_CONTRIBUTOR),
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'customer_id' => $customer->id,
                'is_active' => true,
            ]);
            $reader->customerRoles()->attach($role->id, ['customer_id' => $customerId]);
        });
    }

    public static function cleanupListStates(int $customerId): void
    {
        DB::transaction(function () use ($customerId): void {
            $documentIds = EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->where('original_filename', self::FAILED_FILENAME)
                ->pluck('id');

            EnterpriseWikiIngestRun::query()
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->whereIn('source_id', $documentIds)
                ->delete();
            EnterpriseWikiDocument::query()->whereIn('id', $documentIds)->delete();

            $roleIds = CustomerRole::query()
                ->where('customer_id', $customerId)
                ->where('name', self::READER_ROLE)
                ->pluck('id');
            DB::table('customer_user_roles')->whereIn('customer_role_id', $roleIds)->delete();
            CustomerRole::query()->whereIn('id', $roleIds)->delete();
            User::query()->where('email', self::READER_EMAIL)->delete();
        });
    }

    public static function cleanup(int $customerId): void
    {
        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('original_filename', self::UPLOAD_FILENAME)
            ->get();

        foreach ($documents as $document) {
            if ($document->file_path !== null && $document->file_path !== '') {
                Storage::disk('local')->delete($document->file_path);
            }

            $document->delete();
        }
    }
}
