<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a relational category reference to operational runbooks and backfill existing rows.
     */
    public function up(): void
    {
        if (! Schema::hasTable('operational_runbooks') || ! Schema::hasTable('operational_runbook_categories')) {
            return;
        }

        Schema::table('operational_runbooks', function (Blueprint $table): void {
            $table->foreignId('operational_runbook_category_id')
                ->nullable()
                ->after('title')
                ->constrained('operational_runbook_categories')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        $categoryIds = DB::table('operational_runbook_categories')
            ->pluck('id', 'slug')
            ->all();

        foreach ($categoryIds as $slug => $categoryId) {
            DB::table('operational_runbooks')
                ->where('category', $slug)
                ->update(['operational_runbook_category_id' => $categoryId]);
        }
    }

    /**
     * Remove the relational category reference from operational runbooks.
     */
    public function down(): void
    {
        if (! Schema::hasTable('operational_runbooks')) {
            return;
        }

        Schema::table('operational_runbooks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operational_runbook_category_id');
        });
    }
};
