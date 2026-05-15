<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_deviations', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('category')->index();
            $table->string('severity')->index();
            $table->string('status')->index();
            $table->text('description');
            $table->text('impact')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('acceptance_criteria')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->nullable()->index();
            $table->date('source_date')->nullable()->index();
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ready_for_verification_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('closed_at')->nullable()->index();
            $table->string('commit_hash')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'severity', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_deviations');
    }
};
