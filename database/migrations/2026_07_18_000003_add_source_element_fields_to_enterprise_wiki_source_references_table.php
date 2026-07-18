<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->string('source_element_key')->nullable()->after('source_id');
            $table->string('source_element_type')->nullable()->after('source_element_key');
            $table->string('source_row_key')->nullable()->after('source_element_type');

            $table->index('source_element_key');
            $table->index('source_row_key');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->dropIndex(['source_element_key']);
            $table->dropIndex(['source_row_key']);
            $table->dropColumn(['source_element_key', 'source_element_type', 'source_row_key']);
        });
    }
};
