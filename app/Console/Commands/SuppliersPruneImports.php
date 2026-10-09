<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Import\SupplierImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes «Importer leverandører» uploads nobody confirmed within a day: the cell values of a file
 * that never became suppliers. A completed import is the record of what was imported and stays —
 * it holds no rows any more, only the result.
 */
class SuppliersPruneImports extends Command
{
    protected $signature = 'suppliers:prune-imports';

    protected $description = 'Delete pending supplier imports that were never confirmed';

    public function handle(SupplierImportService $imports): int
    {
        $deleted = $imports->pruneExpired();

        if ($deleted > 0) {
            Log::info('Supplier imports: deleted pending imports that were never confirmed.', ['deleted' => $deleted]);
        }

        $this->info("Deleted {$deleted} pending supplier import(s).");

        return self::SUCCESS;
    }
}
