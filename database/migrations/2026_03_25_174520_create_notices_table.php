<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('notice_id')->unique();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->string('notice_type')->nullable();
            $table->string('notice_subtype')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('issue_date')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->decimal('estimated_value_amount', 18, 2)->nullable();
            $table->string('estimated_value_currency', 3)->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_org_number')->nullable();
            $table->string('buyer_city')->nullable();
            $table->string('buyer_postal_code')->nullable();
            $table->string('buyer_region_code')->nullable();
            $table->string('buyer_country_code')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('relevance_level')->nullable();
            $table->integer('relevance_score')->nullable();
            $table->boolean('raw_xml_stored')->default(false);
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('notice_type');
            $table->index('publication_date');
            $table->index('deadline');
            $table->index('relevance_level');
            $table->index('relevance_score');
            $table->index('buyer_org_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
