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
        Schema::create('notice_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->index();
            $table->text('lot_title')->nullable();
            $table->text('lot_description')->nullable();
            $table->timestamps();

            $table->foreign('notice_id')->references('id')->on('notices')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notice_lots');
    }
};
