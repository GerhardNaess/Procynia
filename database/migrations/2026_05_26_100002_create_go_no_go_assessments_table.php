<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_no_go_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')
                ->constrained('saved_notices')
                ->cascadeOnDelete();
            $table->foreignId('template_id')
                ->constrained('go_no_go_assessment_templates')
                ->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recommendation', 20)->nullable(); // 'go', 'avklar', 'nogo'
            $table->unsignedSmallInteger('total_score')->nullable();
            $table->unsignedSmallInteger('max_score')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // One assessment per notice+template combination
            $table->unique(['saved_notice_id', 'template_id'], 'go_no_go_assessments_notice_template_unique');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_no_go_assessments');
    }
};
