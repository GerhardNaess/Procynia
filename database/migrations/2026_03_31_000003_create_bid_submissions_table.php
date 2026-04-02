<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bid_submissions')) {
            return;
        }

        Schema::create('bid_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('label');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['saved_notice_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_submissions');
    }
};
