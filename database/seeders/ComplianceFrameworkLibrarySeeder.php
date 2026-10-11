<?php

namespace Database\Seeders;

use App\Services\Compliance\Library\ComplianceFrameworkLibraryImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Kravbiblioteket: loads Procynia's standardised requirement packages into the shared library —
 * ISO 9001:2015 + A1:2024, ISO/IEC 27001:2022 + A1:2024 (chapters 4–10 and the 93 Annex A
 * controls) and ISO 14001:2026. One file per edition in database/data/compliance_frameworks.
 *
 *   php artisan db:seed --class=ComplianceFrameworkLibrarySeeder --force
 *
 * Safe to run in an existing environment, as often as needed: frameworks and nodes are matched on
 * their stable keys, so a rerun with unchanged files changes nothing, and a changed file updates
 * the library in place (removed clauses are withdrawn, never deleted). It never activates a
 * framework for any customer. Customers that have already activated one only get their register
 * brought up to date — new requirements added, library-owned text refreshed — while owners,
 * relevance judgements, assessments, links and history stay untouched. See
 * ComplianceFrameworkLibraryImporter.
 */
class ComplianceFrameworkLibrarySeeder extends Seeder
{
    /** The framework definition files, in the order the library lists them. */
    public const DEFINITIONS = [
        'iso9001_2015.php',
        'iso27001_2022.php',
        'iso14001_2026.php',
    ];

    public function run(ComplianceFrameworkLibraryImporter $importer): void
    {
        if (! Schema::hasTable('compliance_frameworks')) {
            return;
        }

        foreach (self::DEFINITIONS as $file) {
            $report = $importer->import(ComplianceFrameworkLibraryImporter::definitionFromFile(database_path('data/compliance_frameworks/'.$file)));

            $this->command?->info(sprintf(
                '%s: %d new, %d updated, %d unchanged, %d withdrawn, %d restored; %d activated customer(s) synced (%d requirement(s) added, %d updated).',
                $report['framework']->label(),
                $report['created'],
                $report['updated'],
                $report['unchanged'],
                $report['withdrawn'],
                $report['restored'],
                $report['customers_synced'],
                $report['customer_requirements_created'],
                $report['customer_requirements_updated'],
            ));
        }
    }
}
