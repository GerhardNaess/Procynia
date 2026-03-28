<?php

namespace App\Console\Commands;

use App\Models\Nationality;
use App\Support\LocaleReferenceCatalog;
use Illuminate\Console\Command;

class SeedNationalitiesReference extends Command
{
    protected $signature = 'reference:seed-nationalities';

    protected $description = 'Seed the nationalities reference table from official ICU locale data.';

    public function handle(): int
    {
        $rows = LocaleReferenceCatalog::nationalities();
        $existing = Nationality::query()->get()->keyBy('code');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $current = $existing->get($row['code']);

            if (! $current instanceof Nationality) {
                $created++;

                continue;
            }

            if (
                $current->name_en === $row['name_en']
                && $current->name_no === $row['name_no']
                && $current->flag_emoji === $row['flag_emoji']
            ) {
                $skipped++;

                continue;
            }

            $updated++;
        }

        Nationality::query()->upsert(
            $rows,
            ['code'],
            ['name_en', 'name_no', 'flag_emoji', 'updated_at'],
        );

        $this->line("[DOFFIN][CPV] Nationalities seeded. created={$created} updated={$updated} skipped={$skipped}");

        return self::SUCCESS;
    }
}
