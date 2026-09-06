<?php

namespace App\Console\Commands;

use App\Services\EnterpriseWiki\EnterpriseWikiPageOwnerService;
use Illuminate\Console\Command;

/**
 * Give an owner to Wiki pages created before ownership was recorded, from the document that
 * originally created each page.
 *
 * A command rather than a migration on purpose: assigning responsibility to named people is an
 * operation someone should choose to run and be able to read the result of, not something that
 * happens silently during a deploy. It is idempotent, so a dry run followed by a real run — or a
 * second run after new data arrives — is safe.
 */
class EnterpriseWikiBackfillPageOwners extends Command
{
    protected $signature = 'enterprise-wiki:backfill-page-owners
                            {--customer= : Limit to one customer id}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Set enterprise_wiki_pages.owner_user_id from the document that originally created each page';

    public function handle(EnterpriseWikiPageOwnerService $owners): int
    {
        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $diagnostics = $owners->unownedPageDiagnostics($customerId);

        $this->table(
            ['Situasjon', 'Sider'],
            collect($diagnostics)->map(fn (int $count, string $key): array => [$key, $count])->values()->all(),
        );

        if ($diagnostics['resolvable'] === 0) {
            $this->info('No page has an unambiguous original owner to inherit. Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$diagnostics['resolvable']} page(s) would be given an owner.");

            return self::SUCCESS;
        }

        $result = $owners->backfillMissingOwners($customerId);

        $this->info("Assigned an owner to {$result['assigned']} page(s).");

        if ($result['skipped'] > 0) {
            // Deliberately listed: these need a human decision, not a default.
            $this->warn("Left untouched: {$result['skipped']} page(s) whose original owner is not unambiguous.");
            $this->line('  Page ids: '.implode(', ', $result['skipped_page_ids']));
        }

        return self::SUCCESS;
    }
}
