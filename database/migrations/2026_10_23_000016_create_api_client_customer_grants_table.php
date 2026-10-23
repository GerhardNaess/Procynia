<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer has given a client application read access, through one integration identity
 * (docs/external-api-architecture.md §4.2, D1, D6).
 *
 * The integration identity is a users row with role = integration in the same customer; its
 * customer roles and fagområder decide what the client sees. A revoked grant is kept, with who
 * revoked it and when — revocation is history, not deletion. At most one grant per (client,
 * customer) is active at a time, and an integration identity belongs to one grant only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_client_customer_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('integration_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Updated at most once a minute by the API (D10), for «last used» in the customer's view.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX api_client_customer_grants_one_active ON api_client_customer_grants (api_client_id, customer_id) WHERE revoked_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_client_customer_grants');
    }
};
