<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_version_id')->constrained('enterprise_wiki_page_versions')->cascadeOnDelete();
            $table->text('claim_text');
            $table->unsignedInteger('position_order')->default(0);
            $table->string('confidence')->default('uncertain');
            $table->boolean('conflict_flag')->default(false);
            $table->string('approval_status')->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('enterprise_wiki_page_id');
            $table->index('enterprise_wiki_page_version_id');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_claims');
    }
};
