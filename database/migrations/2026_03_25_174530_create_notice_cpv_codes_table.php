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
        Schema::create('notice_cpv_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id');
            $table->string('cpv_code');
            $table->timestamps();

            $table->index('cpv_code');
            $table->unique(['notice_id', 'cpv_code']);
            $table->foreign('notice_id')->references('id')->on('notices')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notice_cpv_codes');
    }
};
