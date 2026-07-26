<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Support command for the Wiki graph filter dropdown E2E scale tests
 * (tests/e2e/wiki-graph-filters.spec.js). Seeds synthetic documents/owners on top
 * of a customer's existing Enterprise Wiki pages so the document/owner filter
 * dropdowns have enough options to exercise their internal search and scrolling.
 * Paired with `wiki:scale-test-cleanup`, which removes exactly what this creates.
 */
class ScaleTestWikiFilterData extends Command
{
    protected $signature = 'wiki:scale-test-seed {customer_id=4} {--count=20}';

    protected $description = 'Seed synthetic documents/owners for E2E scale testing of the Wiki graph filter dropdowns';

    public function handle(): int
    {
        $customerId = (int) $this->argument('customer_id');
        $count = (int) $this->option('count');

        $pageIds = EnterpriseWikiPage::where('customer_id', $customerId)->pluck('id')->all();

        if ($pageIds === []) {
            $this->error("No enterprise wiki pages found for customer {$customerId}.");

            return self::FAILURE;
        }

        for ($i = 1; $i <= $count; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $owner = User::create([
                'customer_id' => $customerId,
                'name' => "Skalatest Eier {$n}",
                'email' => "scale-test-owner-{$n}@example.test",
                'password' => Hash::make('not-used-scale-test'),
                'role' => 'user',
                'bid_role' => 'contributor',
            ]);

            $doc = EnterpriseWikiDocument::create([
                'customer_id' => $customerId,
                'owner_user_id' => $owner->id,
                'original_filename' => "Skalatest dokument {$n} - lang beskrivende filnavn for testing av trunkering og linjebryting.docx",
                'file_path' => "scale-test/doc-{$n}.docx",
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

        $this->info("Created {$count} scale-test documents/owners for customer {$customerId}.");
        $this->info('Docs now: '.EnterpriseWikiDocument::where('customer_id', $customerId)->count());
        $this->info('Users now: '.User::where('customer_id', $customerId)->count());

        return self::SUCCESS;
    }
}
