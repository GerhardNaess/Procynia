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
        Schema::create('watch_profile_cpv_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_profile_id');
            $table->string('cpv_code');
            $table->integer('weight')->default(1);
            $table->timestamps();

            $table->index('cpv_code');
            $table->unique(['watch_profile_id', 'cpv_code']);
            $table->foreign('watch_profile_id')->references('id')->on('watch_profiles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watch_profile_cpv_codes');
    }
};
