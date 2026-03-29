<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watch_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('doffin_notice_id');
            $table->text('title');
            $table->string('buyer_name')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->string('external_url', 2000)->nullable();
            $table->unsignedInteger('relevance_score')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['watch_profile_id', 'doffin_notice_id'], 'watch_profile_inbox_records_unique_notice');
            $table->index(['customer_id', 'discovered_at']);
            $table->index(['user_id', 'discovered_at']);
            $table->index(['department_id', 'discovered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_profile_inbox_records');
    }
};
