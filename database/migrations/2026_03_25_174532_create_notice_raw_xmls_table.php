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
        Schema::create('notice_raw_xml', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->unique();
            $table->longText('xml_content');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->foreign('notice_id')->references('id')->on('notices')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notice_raw_xml');
    }
};
