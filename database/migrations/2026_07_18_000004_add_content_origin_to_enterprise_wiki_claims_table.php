<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->string('content_origin')->default('unclassified')->after('claim_text');
            $table->text('page_excerpt')->nullable()->after('content_origin');
            $table->text('review_reason')->nullable()->after('page_excerpt');
            $table->string('generation_issue')->nullable()->after('review_reason');

            $table->index('content_origin');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropIndex(['content_origin']);
            $table->dropColumn([
                'content_origin',
                'page_excerpt',
                'review_reason',
                'generation_issue',
            ]);
        });
    }
};
