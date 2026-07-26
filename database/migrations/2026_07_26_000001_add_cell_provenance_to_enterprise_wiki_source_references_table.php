<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->string('source_cell_key')->nullable()->after('source_row_key');
            $table->string('source_column_key')->nullable()->after('source_cell_key');

            $table->index('source_cell_key');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->dropIndex(['source_cell_key']);
            $table->dropColumn(['source_cell_key', 'source_column_key']);
        });
    }
};
