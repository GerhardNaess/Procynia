<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Tears down the synthetic data created by `wiki:scale-test-seed`. Matches strictly
 * on the "scale-test" file_path prefix / email domain so it can never touch real
 * customer data.
 */
class ScaleTestWikiFilterDataCleanup extends Command
{
    protected $signature = 'wiki:scale-test-cleanup {customer_id=4}';

    protected $description = 'Remove synthetic scale-test documents/owners created by wiki:scale-test-seed';

    public function handle(): int
    {
        $customerId = (int) $this->argument('customer_id');

        $docs = EnterpriseWikiDocument::where('customer_id', $customerId)
            ->where('file_path', 'like', 'scale-test/%')
            ->get();

        foreach ($docs as $doc) {
            EnterpriseWikiIngestRun::where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $doc->id)
                ->delete(); // cascades to enterprise_wiki_ingest_run_pages
        }

        $docCount = $docs->count();
        EnterpriseWikiDocument::where('customer_id', $customerId)
            ->where('file_path', 'like', 'scale-test/%')
            ->delete();

        $userCount = User::where('customer_id', $customerId)
            ->where('email', 'like', 'scale-test-owner-%@example.test')
            ->count();
        User::where('customer_id', $customerId)
            ->where('email', 'like', 'scale-test-owner-%@example.test')
            ->delete();

        $this->info("Removed {$docCount} scale-test documents and {$userCount} scale-test owners for customer {$customerId}.");

        return self::SUCCESS;
    }
}
