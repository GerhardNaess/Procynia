<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Test-only fixture for the Wiki graph filter dropdown E2E scale tests
 * (tests/e2e/wiki-graph-filters.spec.js). Lives under tests/ (autoload-dev
 * only — absent from any `composer install --no-dev` production build) and is
 * not registered as an Artisan command; invoked directly via
 * `php artisan tinker --execute=...` from the Playwright spec.
 *
 * Synthetic rows are tagged with a "scale-test/" file_path prefix and an
 * "@example.test" email domain so seed()/cleanup() can never touch real
 * customer documents, owners, or cross-customer data.
 */
class WikiGraphFilterScaleTestFixture
{
    private const EMAIL_DOMAIN = '@example.test';

    private const FILE_PATH_PREFIX = 'scale-test/';

    public static function seed(int $customerId, int $count = 20): void
    {
        $pageIds = EnterpriseWikiPage::where('customer_id', $customerId)->pluck('id')->all();

        if ($pageIds === []) {
            throw new RuntimeException("No enterprise wiki pages found for customer {$customerId}.");
        }

        for ($i = 1; $i <= $count; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $owner = User::create([
                'customer_id' => $customerId,
                'name' => "Skalatest Eier {$n}",
                'email' => "scale-test-owner-{$n}".self::EMAIL_DOMAIN,
                'password' => Hash::make('not-used-scale-test'),
                'role' => 'user',
                'bid_role' => 'contributor',
            ]);

            $doc = EnterpriseWikiDocument::create([
                'customer_id' => $customerId,
                'owner_user_id' => $owner->id,
                'original_filename' => "Skalatest dokument {$n} - lang beskrivende filnavn for testing av trunkering og linjebryting.docx",
                'file_path' => self::FILE_PATH_PREFIX."doc-{$n}.docx",
                'file_hash_sha256' => hash('sha256', "scale-test-doc-{$n}"),
                'document_status' => 'processed',
            ]);

            $run = EnterpriseWikiIngestRun::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'trigger_type' => 'manual',
                'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $doc->id,
                'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            ]);

            foreach (collect($pageIds)->random(min(3, count($pageIds))) as $pageId) {
                EnterpriseWikiIngestRunPage::firstOrCreate([
                    'enterprise_wiki_ingest_run_id' => $run->id,
                    'enterprise_wiki_page_id' => $pageId,
                ], ['action' => 'created']);
            }
        }
    }

    public static function cleanup(int $customerId): void
    {
        $docs = EnterpriseWikiDocument::where('customer_id', $customerId)
            ->where('file_path', 'like', self::FILE_PATH_PREFIX.'%')
            ->get();

        foreach ($docs as $doc) {
            EnterpriseWikiIngestRun::where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $doc->id)
                ->delete(); // cascades to enterprise_wiki_ingest_run_pages
        }

        EnterpriseWikiDocument::where('customer_id', $customerId)
            ->where('file_path', 'like', self::FILE_PATH_PREFIX.'%')
            ->delete();

        User::where('customer_id', $customerId)
            ->where('email', 'like', 'scale-test-owner-%'.self::EMAIL_DOMAIN)
            ->delete();
    }
}
