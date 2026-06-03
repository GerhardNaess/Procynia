<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_price_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('models_seen')->default(0);
            $table->unsignedInteger('prices_created')->default(0);
            $table->unsignedInteger('prices_changed')->default(0);
            $table->unsignedInteger('prices_unchanged')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->text('error_message')->nullable();
            $table->string('raw_snapshot_path', 512)->nullable();
            $table->timestamps();

            $table->index(['provider', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_price_sync_runs');
    }
};
