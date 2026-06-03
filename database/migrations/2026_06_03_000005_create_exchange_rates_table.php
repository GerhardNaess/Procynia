<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('base_currency', 8);
            $table->string('quote_currency', 8);
            $table->decimal('rate', 16, 8);
            $table->date('rate_date');
            $table->string('source', 64)->default('norges_bank');
            $table->string('source_payload_hash', 64)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'rate_date', 'source'], 'exchange_rates_unique');
            $table->index(['base_currency', 'quote_currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
