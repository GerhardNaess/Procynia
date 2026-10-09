<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Mine oppgaver» for the styringsmoduler (punkt 6D) asks every module for one customer's objects
 * with this person as ansvarlig, on every visit to Oppfølging and for every person in the daily
 * fristpåminnelse run. None of these owner columns had an index — Postgres does not index a foreign
 * key by itself — so each lookup scanned the customer's rows.
 *
 * Only the columns those queries filter on: (customer_id, owner) per table, the order the sources
 * filter in. docs/notifications-and-tasks-plan.md §7.
 */
return new class extends Migration
{
    /** @var array<string, string> table => owner column */
    private const OWNERS = [
        'risks' => 'owner_user_id',
        'risk_treatment_actions' => 'owner_user_id',
        'improvement_cases' => 'owner_user_id',
        'improvement_actions' => 'owner_user_id',
        'compliance_requirements' => 'owner_user_id',
        'compliance_audits' => 'responsible_user_id',
        'quality_items' => 'owner_user_id',
        'objectives' => 'owner_user_id',
        'kpis' => 'owner_user_id',
    ];

    public function up(): void
    {
        foreach (self::OWNERS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $column): void {
                $blueprint->index(['customer_id', $column], $table.'_customer_owner_index');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::OWNERS, true) as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_customer_owner_index');
            });
        }
    }
};
