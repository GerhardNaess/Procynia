<?php

namespace Database\Seeders;

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
