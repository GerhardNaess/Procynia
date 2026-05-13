<?php

namespace Database\Seeders;

use App\Models\OperationalRunbook;
use Illuminate\Database\Seeder;

class OperatingProcedureSeeder extends Seeder
{
    /**
     * Seed the first operational runbook for Procynia.
     */
    public function run(): void
    {
        OperationalRunbook::query()->firstOrCreate(
            ['title' => 'Docker-oppsett for Procynia'],
            [
                'category' => 'docker',
                'summary' => 'Forklarer hvordan Procynia kjører som flere isolerte Docker-containere for app, database, Redis, køarbeid, mail testing og persistente data.',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );
    }
}
