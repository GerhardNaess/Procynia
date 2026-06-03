<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('deployment_name', 128)->nullable();
            $table->string('provider_region', 64)->nullable();
            $table->string('currency', 8)->default('usd');
            $table->decimal('input_price_per_1m_tokens', 12, 6);
            $table->decimal('cached_input_price_per_1m_tokens', 12, 6)->nullable();
            $table->decimal('output_price_per_1m_tokens', 12, 6);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source_url', 512)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('sync_status', 32)->nullable();
            $table->text('sync_note')->nullable();
            $table->timestamps();

            $table->index(['provider', 'model', 'is_active', 'valid_from']);
            $table->index(['provider', 'model', 'deployment_name', 'provider_region', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
