<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_items', 'extraction_status')) {
                $table->dropColumn('extraction_status');
            }

            if (Schema::hasColumn('knowledge_items', 'extraction_error')) {
                $table->dropColumn('extraction_error');
            }

            if (Schema::hasColumn('knowledge_items', 'extracted_text')) {
                $table->dropColumn('extracted_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_items', 'extracted_text')) {
                $table->longText('extracted_text')->nullable();
            }

            if (! Schema::hasColumn('knowledge_items', 'extraction_status')) {
                $table->string('extraction_status', 32)->nullable();
            }

            if (! Schema::hasColumn('knowledge_items', 'extraction_error')) {
                $table->text('extraction_error')->nullable();
            }
        });
    }
};
