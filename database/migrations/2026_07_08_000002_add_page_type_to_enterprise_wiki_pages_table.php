<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->string('page_type')->default('article')->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->dropColumn('page_type');
        });
    }
};
