<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the operational runbook attachments table.
     */
    public function up(): void
    {
        Schema::create('operational_runbook_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_runbook_id')->constrained('operational_runbooks')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['operational_runbook_id', 'sort_order']);
        });
    }

    /**
     * Drop the operational runbook attachments table.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_runbook_attachments');
    }
};
