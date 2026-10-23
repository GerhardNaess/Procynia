<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One external application that may call the Procynia API (docs/external-api-architecture.md §4.2).
 *
 * Holds only what identifies the application in an Entra access token: its application id (`azp`)
 * and the tenant it lives in (`tid`). Never a secret — the client authenticates to Entra, not to
 * Procynia (D5). Which customers it may read is api_client_customer_grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Entra application (client) id: the `azp` claim of a v2 access token. Globally unique.
            $table->uuid('entra_app_id')->unique();
            // The client's own Entra tenant: the `tid` the token must come from.
            $table->uuid('home_tenant_id')->index();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
