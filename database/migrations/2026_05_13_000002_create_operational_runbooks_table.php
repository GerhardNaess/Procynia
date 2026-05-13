<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the operational runbooks table.
     */
    public function up(): void
    {
        Schema::create('operational_runbooks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category')->index();
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    /**
     * Drop the operational runbooks table.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_runbooks');
    }
};
