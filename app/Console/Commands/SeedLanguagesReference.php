<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Support\LocaleReferenceCatalog;
use Illuminate\Console\Command;

class SeedLanguagesReference extends Command
{
    protected $signature = 'reference:seed-languages';

    protected $description = 'Seed the languages reference table from official ICU locale data.';

    public function handle(): int
    {
        $rows = LocaleReferenceCatalog::languages();
        $existing = Language::query()->get()->keyBy('code');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $current = $existing->get($row['code']);

            if (! $current instanceof Language) {
                $created++;

                continue;
            }

            if (
                $current->name_en === $row['name_en']
                && $current->name_no === $row['name_no']
            ) {
                $skipped++;

                continue;
            }

            $updated++;
        }

        Language::query()->upsert(
            $rows,
            ['code'],
            ['name_en', 'name_no', 'updated_at'],
        );

        $this->line("[DOFFIN][CPV] Languages seeded. created={$created} updated={$updated} skipped={$skipped}");

        return self::SUCCESS;
    }
}
