<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalize historical category values to canonical keys.
     */
    public function up(): void
    {
        if (! Schema::hasTable('operational_runbooks')) {
            return;
        }

        $mappings = [
            'General' => 'general',
            'general' => 'general',
            'Backup' => 'backup_recovery',
            'backup' => 'backup_recovery',
            'Deploy' => 'deploy',
            'deploy' => 'deploy',
            'Monitoring' => 'monitoring',
            'monitoring' => 'monitoring',
            'Security' => 'security',
            'security' => 'security',
            'Integrations' => 'integrations',
            'integrations' => 'integrations',
            'Infrastructure' => 'infrastructure',
            'infrastructure' => 'infrastructure',
            'Incident' => 'incidents',
            'incident' => 'incidents',
        ];

        foreach ($mappings as $from => $to) {
            DB::table('operational_runbooks')
                ->where('category', $from)
                ->update(['category' => $to]);
        }
    }

    /**
     * Revert the category normalization if the migration is rolled back.
     */
    public function down(): void
    {
        if (! Schema::hasTable('operational_runbooks')) {
            return;
        }

        $mappings = [
            'general' => 'General',
            'backup_recovery' => 'Backup',
            'deploy' => 'Deploy',
            'monitoring' => 'Monitoring',
            'security' => 'Security',
            'integrations' => 'Integrations',
            'infrastructure' => 'Infrastructure',
            'incidents' => 'Incident',
        ];

        foreach ($mappings as $from => $to) {
            DB::table('operational_runbooks')
                ->where('category', $from)
                ->update(['category' => $to]);
        }
    }
};
