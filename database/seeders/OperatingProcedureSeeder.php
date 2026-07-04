<?php

namespace Database\Seeders;

use App\Models\OperationalRunbookCategory;
use App\Models\OperationalRunbook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class OperatingProcedureSeeder extends Seeder
{
    /**
     * Seed the first operational runbook for Procynia.
     */
    public function run(): void
    {
        if (! Schema::hasTable('operational_runbooks')) {
            return;
        }

        $this->seedCategories();

        $this->seedRunbook(
            title: 'Docker-oppsett for Procynia',
            category: 'docker',
            summary: 'Forklarer hvordan Procynia kjører som flere isolerte Docker-containere for app, database, Redis, køarbeid, mail testing og persistente data.',
            content: 'Første versjon. Last opp faktisk Word/PDF-dokument og eventuelle figurer som vedlegg.',
            sortOrder: 1,
        );

        $this->seedRunbook(
            title: 'Uptime Kuma overvåkning',
            category: 'monitoring',
            summary: 'Beskriver hvordan Uptime Kuma brukes til oppetidsovervåkning av Procynia på tvers av Azure, Google Cloud, AWS og on-premise.',
            content: 'Uptime Kuma kjøres som leverandørnøytral Docker Compose-tjeneste med persistent Docker volume og reverse proxy/HTTPS foran. Faktisk domene, varsling og monitorer settes per miljø.',
            sortOrder: 2,
        );
    }

    /**
     * Seed the canonical runbook categories without overwriting existing edits.
     */
    private function seedCategories(): void
    {
        if (! Schema::hasTable('operational_runbook_categories')) {
            return;
        }

        $now = now();

        foreach ([
            ['name' => 'Generelt', 'slug' => 'general', 'sort_order' => 0],
            ['name' => 'Docker', 'slug' => 'docker', 'sort_order' => 10],
            ['name' => 'Backup og recovery', 'slug' => 'backup_recovery', 'sort_order' => 20],
            ['name' => 'Deploy', 'slug' => 'deploy', 'sort_order' => 30],
            ['name' => 'Overvåkning', 'slug' => 'monitoring', 'sort_order' => 40],
            ['name' => 'Sikkerhet', 'slug' => 'security', 'sort_order' => 50],
            ['name' => 'Integrasjoner', 'slug' => 'integrations', 'sort_order' => 60],
            ['name' => 'Infrastruktur', 'slug' => 'infrastructure', 'sort_order' => 70],
            ['name' => 'Hendelser og beredskap', 'slug' => 'incidents', 'sort_order' => 80],
        ] as $category) {
            OperationalRunbookCategory::query()->firstOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /**
     * Seed a single operational runbook without overwriting existing user edits.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function seedRunbook(
        string $title,
        string $category,
        string $summary,
        string $content,
        int $sortOrder,
    ): void {
        $attributes = [
            'category' => $category,
            'summary' => $summary,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ];

        if (Schema::hasColumn('operational_runbooks', 'content')) {
            $attributes['content'] = $content;
        }

        OperationalRunbook::query()->firstOrCreate(
            ['title' => $title],
            $attributes,
        );
    }
}
