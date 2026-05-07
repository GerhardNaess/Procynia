<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('status'); // paid|open|void|uncollectible
            $table->unsignedBigInteger('amount_paid'); // øre (NOK * 100)
            $table->string('currency', 10)->default('nok');
            $table->json('line_items')->nullable();
            $table->timestamp('invoice_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_logs');
    }
};
