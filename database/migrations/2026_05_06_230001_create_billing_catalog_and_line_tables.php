<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_products', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('billing_scope');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['category', 'billing_scope', 'is_active']);
        });

        Schema::create('billing_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_product_id')->constrained('billing_products')->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('interval');
            $table->string('currency', 10)->default('nok');
            $table->unsignedBigInteger('unit_amount')->nullable();
            $table->string('stripe_price_id')->nullable()->unique();
            $table->string('tier_key')->nullable();
            $table->boolean('is_recurring')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('included_quantity')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['interval', 'is_active']);
            $table->index(['billing_product_id', 'interval']);
        });

        Schema::create('customer_billing_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->nullOnDelete();
            $table->foreignId('billing_price_id')->nullable()->constrained('billing_prices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('stripe_subscription_item_id')->nullable()->index();
            $table->string('stripe_invoice_id')->nullable()->index();
            $table->string('source')->default('system');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'billing_price_id']);
            $table->index(['customer_id', 'user_id']);
        });

        Schema::create('customer_user_service_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->nullOnDelete();
            $table->foreignId('billing_price_id')->nullable()->constrained('billing_prices')->nullOnDelete();
            $table->string('level_key');
            $table->string('status')->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'user_id', 'status']);
            $table->index(['customer_id', 'billing_price_id']);
            $table->index(['customer_id', 'level_key']);
        });

        Schema::create('billing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('source')->default('system');
            $table->text('description')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('stripe_event_id')->nullable()->index();
            $table->timestamps();

            $table->index(['customer_id', 'event_type']);
            $table->index(['customer_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('customer_user_service_levels');
        Schema::dropIfExists('customer_billing_lines');
        Schema::dropIfExists('billing_prices');
        Schema::dropIfExists('billing_products');
    }
};
