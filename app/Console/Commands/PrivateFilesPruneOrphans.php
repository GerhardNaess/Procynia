<?php

namespace App\Console\Commands;

use App\Support\PrivateFiles\PrivateFileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Removes private files no row references any more (config/private_files.php): what an interrupted
 * upload, a failed deletion or a deleted customer leaves behind.
 *
 * What protects a file that must stay:
 *
 * - only files in an area's own folder, in the exact form PrivateFileStore writes, are considered —
 *   other modules' files (the Enterprise Wiki's) are never listed;
 * - a file referenced by any row the area lists — a documentation row's path or a control's snapshot
 *   of its key — is never deleted, and that is checked again immediately before each deletion;
 * - a file younger than the grace period is left alone. Rows only ever point at files written moments
 *   before in the same request, so an upload still being saved is always young; the check runs
 *   against committed rows, so a transaction that is removing a reference has not removed it yet;
 * - if a run finds more orphans than prune_max_per_run, it deletes nothing and fails: that many at
 *   once means a wrong or empty database or a missing reference, not ordinary leftovers.
 *   --dry-run lists, --force deletes past the brake deliberately.
 *
 * Every deletion and failure is logged with the path and customer, so a run can be traced afterwards.
 * Works on any Storage disk (local now, Azure Blob later).
 */
class PrivateFilesPruneOrphans extends Command
{
    protected $signature = 'private-files:prune-orphans
        {--dry-run : List the orphans without deleting them}
        {--force : Delete even when more orphans are found than the safety brake allows}';

    protected $description = 'Delete private customer files that no row references any more';

    public function handle(PrivateFileStore $store): int
    {
        $grace = (int) config('private_files.orphan_grace_hours', 24);
        $limit = (int) config('private_files.prune_max_per_run', 100);
        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;

        foreach (array_keys((array) config('private_files.areas')) as $area) {
            $orphans = $store->orphans($area, $grace);

            if (! $dryRun && ! $this->option('force') && count($orphans) > $limit) {
                Log::error('private-files: prune stopped by the safety brake; nothing deleted', ['area' => $area, 'orphans' => count($orphans), 'limit' => $limit]);
                $this->error(sprintf('%s: %d orphaned file(s) is more than the safety limit of %d. Nothing was deleted. Check with --dry-run; delete with --force.', $area, count($orphans), $limit));
                $failed++;

                continue;
            }

            foreach ($orphans as $path) {
                $customerId = (int) explode('/', $path)[1];

                if ($dryRun) {
                    $this->line("Would delete {$path}");

                    continue;
                }

                if ($store->delete($path, $customerId, $area)) {
                    Log::info('private-files: deleted an orphaned file', ['path' => $path, 'customer_id' => $customerId, 'area' => $area]);
                    $this->line("Deleted {$path}");
                } else {
                    $failed++;
                    $this->line("Could not delete {$path}");
                }
            }

            $this->info(sprintf('%s: %d orphaned file(s)%s.', $area, count($orphans), $dryRun ? ' (dry run)' : ''));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
